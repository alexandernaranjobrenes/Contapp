<?php

namespace App\Http\Controllers;

use App\Domains\Core\Support\MediaStorage;
use App\Domains\Feedback\Exceptions\FeedbackException;
use App\Domains\Feedback\Models\FeedbackComment;
use App\Domains\Feedback\Models\FeedbackVote;
use App\Domains\Feedback\Models\NewsPost;
use App\Domains\Feedback\Services\FeedbackService;
use App\Domains\Feedback\Support\AttachedImage;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * El panel de «Comentarios y noticias» de la barra superior, del lado de la
 * aplicación: cualquier cuenta, con cualquier rol, publica, vota y comenta,
 * y lee las noticias del backoffice. Ver FeedbackService.
 *
 * Responde JSON y no páginas de Inertia: el panel está en todas las
 * pantallas, y una visita de Inertia recargaría la pantalla de atrás (un
 * reporte, un asiento a medio cargar) en cada voto.
 */
class FeedbackController extends Controller
{
    private const POSTS_PER_PAGE = 15;

    private const NEWS_PER_PAGE = 10;

    public function __construct(
        private readonly FeedbackService $feedback,
        private readonly MediaStorage $media,
    ) {}

    /** Las publicaciones a la vista: las más votadas primero, o las más nuevas. */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sort' => ['nullable', Rule::in(['top', 'new'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = FeedbackComment::query()
            ->posts()
            ->open()
            ->with('user:id,name,photo_path,photo_updated_at')
            ->withCount(['replies as replies_count' => fn ($q) => $q->open()]);

        if (($validated['sort'] ?? 'top') === 'new') {
            $query->orderByDesc('id');
        } else {
            $query->orderByDesc('score')->orderByDesc('id');
        }

        $page = $query->simplePaginate(self::POSTS_PER_PAGE);
        $user = $request->user();

        $myVotes = FeedbackVote::query()
            ->where('user_id', $user->id)
            ->whereIn('comment_id', $page->pluck('id'))
            ->pluck('value', 'comment_id');

        return response()->json([
            'posts' => $page->getCollection()->map(fn (FeedbackComment $post) => $this->post($post, $user, (int) ($myVotes[$post->id] ?? 0)))->values(),
            'has_more' => $page->hasMorePages(),
            'limits' => [
                ...AttachedImage::clientRules(),
                'max_images' => FeedbackComment::MAX_IMAGES,
                'max_body' => FeedbackComment::MAX_BODY,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateComment($request);

        $post = $this->publish($request->user(), $validated);

        return response()->json(['post' => $this->post($post->loadCount('replies'), $request->user(), 0)], 201);
    }

    /** Las respuestas de una publicación, en el orden en que se escribieron. */
    public function replies(Request $request, FeedbackComment $comment): JsonResponse
    {
        $this->ensureVisiblePost($comment);

        $replies = $comment->replies()
            ->open()
            ->with('user:id,name,photo_path,photo_updated_at')
            ->orderBy('id')
            ->get();

        return response()->json([
            'replies' => $replies->map(fn (FeedbackComment $reply) => $this->reply($reply, $request->user()))->values(),
        ]);
    }

    public function storeReply(Request $request, FeedbackComment $comment): JsonResponse
    {
        $this->ensureVisiblePost($comment);

        $validated = $this->validateComment($request);

        $reply = $this->publish($request->user(), $validated, $comment);

        return response()->json(['reply' => $this->reply($reply, $request->user())], 201);
    }

    /** 1 a favor, -1 en contra, 0 quita el voto. */
    public function vote(Request $request, FeedbackComment $comment): JsonResponse
    {
        $this->ensureVisiblePost($comment);

        $value = (int) $request->validate(['value' => ['required', 'integer', Rule::in([-1, 0, 1])]])['value'];

        try {
            $post = $this->feedback->vote($comment, $request->user(), $value);
        } catch (FeedbackException $e) {
            throw ValidationException::withMessages(['value' => $e->getMessage()]);
        }

        return response()->json(['score' => $post->score, 'my_vote' => $value]);
    }

    /** El autor borra su publicación o su respuesta. */
    public function destroy(Request $request, FeedbackComment $comment): Response
    {
        // Lo que ya no se ve (resuelto, quitado, o la respuesta de algo que
        // ya no se ve) tampoco se borra desde acá.
        abort_unless($comment->isOpen() && ($comment->isPost() || $comment->parent?->isOpen()), 404);
        abort_unless($comment->user_id === $request->user()->id, 403, 'Solo podés borrar lo que escribiste vos.');

        $this->feedback->deleteOwn($comment, $request->user());

        return response()->noContent();
    }

    /** Las noticias del backoffice, de la más nueva a la más vieja. */
    public function news(Request $request): JsonResponse
    {
        $request->validate(['page' => ['nullable', 'integer', 'min:1']]);

        $page = NewsPost::query()->orderByDesc('created_at')->orderByDesc('id')->simplePaginate(self::NEWS_PER_PAGE);

        return response()->json([
            'news' => $page->getCollection()->map(fn (NewsPost $news) => [
                'id' => $news->id,
                'title' => $news->title,
                'body' => $news->body,
                'link_url' => $news->link_url,
                'link_is_internal' => $news->hasInternalLink(),
                'image_url' => $this->media->versionedUrl($news->image_path, $news->updated_at),
                'created_at' => $news->created_at->toIso8601String(),
            ])->values(),
            'has_more' => $page->hasMorePages(),
        ]);
    }

    /** @return array{body: string, images?: list<UploadedFile>} */
    private function validateComment(Request $request): array
    {
        $images = AttachedImage::validation('images', FeedbackComment::MAX_IMAGES);

        return $request->validate([
            'body' => ['required', 'string', 'max:'.FeedbackComment::MAX_BODY],
            ...$images['rules'],
        ], $images['messages'], ['body' => 'texto']);
    }

    private function publish(User $user, array $validated, ?FeedbackComment $parent = null): FeedbackComment
    {
        try {
            return $this->feedback->publish($user, $validated['body'], $validated['images'] ?? [], $parent);
        } catch (FeedbackException $e) {
            throw ValidationException::withMessages([$e->field => $e->getMessage()]);
        }
    }

    /** Una publicación a la vista. Lo resuelto o quitado, para la aplicación, ya no existe. */
    private function ensureVisiblePost(FeedbackComment $comment): void
    {
        abort_unless($comment->isPost() && $comment->isOpen(), 404, 'Esa publicación ya no está a la vista.');
    }

    private function post(FeedbackComment $post, User $viewer, int $myVote): array
    {
        return [
            ...$this->entry($post, $viewer),
            'score' => $post->score,
            'my_vote' => $myVote,
            'replies_count' => (int) ($post->replies_count ?? 0),
        ];
    }

    private function reply(FeedbackComment $reply, User $viewer): array
    {
        return $this->entry($reply, $viewer);
    }

    /**
     * Lo común a una publicación y a una respuesta. Del autor, solo el nombre
     * y la foto: el canal es de todas las licencias, y su correo o su
     * compañía no son de nadie más.
     */
    private function entry(FeedbackComment $comment, User $viewer): array
    {
        return [
            'id' => $comment->id,
            'body' => $comment->body,
            'images' => $this->imageUrls($comment),
            'author' => $this->author($comment),
            'is_mine' => $comment->user_id === $viewer->id,
            'created_at' => $comment->created_at->toIso8601String(),
        ];
    }

    /** @return array{name: string, photo_url: ?string, is_team: bool} */
    private function author(FeedbackComment $comment): array
    {
        if ($comment->isFromTeam()) {
            return ['name' => 'Equipo '.config('app.name'), 'photo_url' => null, 'is_team' => true];
        }

        $user = $comment->user;

        return [
            'name' => $user?->name ?? 'Cuenta eliminada',
            'photo_url' => $user ? $this->media->versionedUrl($user->photo_path, $user->photo_updated_at) : null,
            'is_team' => false,
        ];
    }

    /** @return list<string> */
    private function imageUrls(FeedbackComment $comment): array
    {
        return Collection::make($comment->imagePaths())->map(fn (string $path) => $this->media->url($path))->filter()->values()->all();
    }
}
