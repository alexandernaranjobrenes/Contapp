<?php

namespace App\Http\Controllers\Backoffice;

use App\Domains\Core\Support\MediaStorage;
use App\Domains\Feedback\Exceptions\FeedbackException;
use App\Domains\Feedback\Models\NewsPost;
use App\Domains\Feedback\Services\NewsService;
use App\Domains\Feedback\Support\AttachedImage;
use App\Http\Controllers\Controller;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Las noticias de CONTAPP desde el backoffice (guard 'propietario'):
 * publicarlas, editarlas y eliminarlas. Las cuentas las leen en el panel de
 * la barra superior, pestaña «Noticias» (FeedbackController::news).
 */
class NewsController extends Controller
{
    public function __construct(
        private readonly NewsService $news,
        private readonly MediaStorage $media,
    ) {}

    public function index(): Response
    {
        $news = NewsPost::query()
            ->with('creator:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->through(fn (NewsPost $post) => [
                'id' => $post->id,
                'title' => $post->title,
                'body' => $post->body,
                'link_url' => $post->link_url,
                'image_url' => $this->media->versionedUrl($post->image_path, $post->updated_at),
                'created_by' => $post->creator?->name,
                'created_at' => $post->created_at->toIso8601String(),
                'updated_at' => $post->updated_at->toIso8601String(),
            ]);

        return Inertia::render('Backoffice/News/Index', [
            'news' => $news,
            'imageRules' => AttachedImage::clientRules(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->save($request, new NewsPost, 'La noticia quedó publicada.');
    }

    public function update(Request $request, NewsPost $news): RedirectResponse
    {
        return $this->save($request, $news, 'Los cambios de la noticia quedaron guardados.');
    }

    public function destroy(NewsPost $news): RedirectResponse
    {
        $this->news->delete($news);

        return back()->with('success', 'La noticia se eliminó.');
    }

    private function save(Request $request, NewsPost $news, string $message): RedirectResponse
    {
        $images = AttachedImage::validation('images', 1);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:'.NewsPost::MAX_TITLE],
            'body' => ['required', 'string', 'max:'.NewsPost::MAX_BODY],
            'link_url' => ['nullable', 'string', 'max:500', $this->linkRule()],
            'image' => ['nullable', ...$images['rules']['images.*']],
            'remove_image' => ['nullable', 'boolean'],
        ], [
            'image.image' => $images['messages']['images.*.image'],
            'image.mimes' => $images['messages']['images.*.mimes'],
            'image.max' => $images['messages']['images.*.max'],
            'image.dimensions' => $images['messages']['images.*.dimensions'],
            'image.uploaded' => $images['messages']['images.*.uploaded'],
        ], [
            'title' => 'título',
            'body' => 'descripción',
            'link_url' => 'enlace',
            'image' => 'imagen',
        ]);

        $link = trim((string) ($validated['link_url'] ?? ''));

        try {
            $this->news->save(
                $news,
                [
                    'title' => $validated['title'],
                    'body' => $validated['body'],
                    'link_url' => $link !== '' ? $link : null,
                ],
                $request->user('propietario'),
                $request->file('image'),
                (bool) ($validated['remove_image'] ?? false),
            );
        } catch (FeedbackException $e) {
            return back()->withErrors([$e->field => $e->getMessage()]);
        }

        return back()->with('success', $message);
    }

    /**
     * Una dirección completa (http o https) o una pantalla de CONTAPP que
     * empieza con «/». Nada de «javascript:» ni de «//otro-sitio»: el enlace
     * se abre al tocar la noticia.
     */
    private function linkRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            $link = trim((string) $value);

            if ($link === '') {
                return;
            }

            $internal = str_starts_with($link, '/') && ! str_starts_with($link, '//') && ! str_contains($link, '\\');
            $external = filter_var($link, FILTER_VALIDATE_URL) !== false
                && in_array(strtolower((string) parse_url($link, PHP_URL_SCHEME)), ['http', 'https'], true);

            if (! $internal && ! $external) {
                $fail('El enlace tiene que ser una dirección completa (https://…) o una pantalla de CONTAPP que empiece con «/».');
            }
        };
    }
}
