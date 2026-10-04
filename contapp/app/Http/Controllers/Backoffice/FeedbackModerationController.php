<?php

namespace App\Http\Controllers\Backoffice;

use App\Domains\Core\Support\MediaStorage;
use App\Domains\Feedback\Exceptions\FeedbackException;
use App\Domains\Feedback\Models\FeedbackComment;
use App\Domains\Feedback\Services\FeedbackService;
use App\Domains\Feedback\Support\AttachedImage;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * El canal de comentarios visto desde el backoffice (guard 'propietario'):
 * leer lo que escribe la gente, responder como equipo de CONTAPP, quitar lo
 * que no corresponde —con el motivo por correo al autor— y dar por resuelto
 * lo que ya se atendió —con un agradecimiento por correo—. Ver
 * FeedbackService.
 *
 * Acá sí se ve el correo del autor: es a quien se le escribe.
 */
class FeedbackModerationController extends Controller
{
    private const PER_PAGE = 20;

    private const STATUSES = [FeedbackComment::OPEN, FeedbackComment::SOLVED, FeedbackComment::REMOVED];

    public function __construct(
        private readonly FeedbackService $feedback,
        private readonly MediaStorage $media,
    ) {}

    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $status = $validated['status'] ?? FeedbackComment::OPEN;
        $search = trim($validated['q'] ?? '');

        $posts = FeedbackComment::query()
            ->posts()
            ->where('status', $status)
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('body', 'like', "%{$search}%")
                ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))))
            ->with([
                'user:id,name,email,photo_path,photo_updated_at',
                'propietario:id,name',
                'replies' => fn ($q) => $q->orderBy('id')->with(['user:id,name,email,photo_path,photo_updated_at', 'propietario:id,name']),
            ])
            ->withCount([
                'votes as upvotes' => fn ($q) => $q->where('value', 1),
                'votes as downvotes' => fn ($q) => $q->where('value', -1),
            ])
            // Lo abierto, por votos: es lo que más gente pide. Lo resuelto y
            // lo quitado, de lo más reciente a lo más viejo.
            ->when($status === FeedbackComment::OPEN,
                fn ($q) => $q->orderByDesc('score')->orderByDesc('id'),
                fn ($q) => $q->orderByDesc('updated_at')->orderByDesc('id'))
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $posts->through(fn (FeedbackComment $post) => [
            ...$this->entry($post),
            'score' => $post->score,
            'upvotes' => (int) $post->upvotes,
            'downvotes' => (int) $post->downvotes,
            'solved_at' => $post->solved_at?->toIso8601String(),
            'replies' => $post->replies->map(fn (FeedbackComment $reply) => $this->entry($reply))->values(),
            'open_replies_count' => $post->replies->where('status', FeedbackComment::OPEN)->count(),
        ]);

        $counts = FeedbackComment::query()->posts()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return Inertia::render('Backoffice/Feedback/Index', [
            'posts' => $posts,
            'filters' => ['status' => $status, 'q' => $search],
            'counts' => Collection::make(self::STATUSES)->mapWithKeys(fn (string $s) => [$s => (int) ($counts[$s] ?? 0)]),
            'imageRules' => [...AttachedImage::clientRules(), 'max_images' => FeedbackComment::MAX_IMAGES],
        ]);
    }

    /** Una respuesta del equipo de CONTAPP a una publicación a la vista. */
    public function reply(Request $request, FeedbackComment $comment): RedirectResponse
    {
        abort_unless($comment->isPost(), 404);

        $images = AttachedImage::validation('images', FeedbackComment::MAX_IMAGES);
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:'.FeedbackComment::MAX_BODY],
            ...$images['rules'],
        ], $images['messages'], ['body' => 'respuesta']);

        try {
            $this->feedback->publish($request->user('propietario'), $validated['body'], $validated['images'] ?? [], $comment);
        } catch (FeedbackException $e) {
            return back()->withErrors([$e->field => $e->getMessage()]);
        }

        return back()->with('success', 'Tu respuesta quedó publicada como Equipo '.config('app.name').'.');
    }

    /** Quita una publicación o una respuesta, y le manda el motivo al autor. */
    public function remove(Request $request, FeedbackComment $comment): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']], [], ['reason' => 'motivo']);

        try {
            $mailed = $this->feedback->remove($comment, $request->user('propietario'), $validated['reason']);
        } catch (FeedbackException $e) {
            return back()->withErrors([$e->field => $e->getMessage()]);
        }

        $what = $comment->isPost() ? 'La publicación se eliminó' : 'El comentario se eliminó';

        return back()->with('success', $what.$this->mailOutcome($comment, $mailed, 'el motivo'));
    }

    /** La da por resuelta y le agradece al autor. */
    public function solve(Request $request, FeedbackComment $comment): RedirectResponse
    {
        $validated = $request->validate(['note' => ['nullable', 'string', 'max:1000']], [], ['note' => 'mensaje']);

        try {
            $mailed = $this->feedback->solve($comment, $request->user('propietario'), $validated['note'] ?? null);
        } catch (FeedbackException $e) {
            return back()->withErrors([$e->field => $e->getMessage()]);
        }

        return back()->with('success', 'La publicación quedó resuelta'.$this->mailOutcome($comment, $mailed, 'el agradecimiento'));
    }

    /** Vuelve a mostrar una publicación que se dio por resuelta. */
    public function reopen(FeedbackComment $comment): RedirectResponse
    {
        try {
            $this->feedback->reopen($comment);
        } catch (FeedbackException $e) {
            return back()->withErrors([$e->field => $e->getMessage()]);
        }

        return back()->with('success', 'La publicación se reabrió: vuelve a verse en la aplicación.');
    }

    /** El final del mensaje de éxito: si el correo al autor salió o no. */
    private function mailOutcome(FeedbackComment $comment, bool $mailed, string $what): string
    {
        $author = $comment->user;

        return match (true) {
            $author === null => '.',
            $mailed => " y le enviamos {$what} por correo a {$author->email}.",
            default => ", pero no se pudo enviar {$what} por correo a {$author->email}. Revisá la configuración de correo.",
        };
    }

    /** Lo común a una publicación y a una respuesta. */
    private function entry(FeedbackComment $comment): array
    {
        return [
            'id' => $comment->id,
            'body' => $comment->body,
            'excerpt' => $comment->excerpt(120),
            'images' => Collection::make($comment->imagePaths())->map(fn (string $path) => $this->media->url($path))->filter()->values(),
            'status' => $comment->status,
            'author' => $this->author($comment),
            'created_at' => $comment->created_at->toIso8601String(),
            'removed_at' => $comment->removed_at?->toIso8601String(),
            'removal_reason' => $comment->removal_reason,
        ];
    }

    /** @return array{name: string, email: ?string, photo_url: ?string, is_team: bool} */
    private function author(FeedbackComment $comment): array
    {
        if ($comment->isFromTeam()) {
            return [
                'name' => 'Equipo '.config('app.name').($comment->propietario ? " ({$comment->propietario->name})" : ''),
                'email' => null,
                'photo_url' => null,
                'is_team' => true,
            ];
        }

        $user = $comment->user;

        return [
            'name' => $user?->name ?? 'Cuenta eliminada',
            'email' => $user?->email,
            'photo_url' => $user ? $this->media->versionedUrl($user->photo_path, $user->photo_updated_at) : null,
            'is_team' => false,
        ];
    }
}
