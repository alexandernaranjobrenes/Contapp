<?php

namespace App\Domains\Blog\Services;

use App\Domains\Blog\Exceptions\BlogException;
use App\Domains\Blog\Models\BlogImage;
use App\Domains\Blog\Models\BlogPost;
use App\Domains\Core\Support\MediaStorage;
use App\Domains\Feedback\Support\AttachedImage;
use App\Domains\Licensing\Models\Propietario;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Las imágenes de los artículos del blog, en el bucket como
 * blog/imag_{id}.{ext} (CLAUDE.md secc. 34 y 41).
 *
 * Se guardan vueltas a codificar (AttachedImage, como las de los comentarios
 * y las noticias): sin los metadatos de una foto de teléfono, y achicadas a
 * 2000 px de lado si son más grandes.
 */
class BlogImageService
{
    public function __construct(private readonly MediaStorage $media) {}

    /**
     * Sube una imagen desde el editor. Queda sin artículo hasta que un
     * guardado la use.
     *
     * @throws BlogException
     */
    public function store(UploadedFile $file, Propietario $by): BlogImage
    {
        $prepared = AttachedImage::normalize($file);

        if ($prepared === null) {
            throw new BlogException('No pudimos leer esa imagen. Probá con otra.', 'image');
        }

        $size = @getimagesizefromstring($prepared['contents']) ?: [null, null];

        // El nombre lleva el id: la fila va antes que el archivo. Si la subida
        // falla, la transacción se deshace y no queda una fila sin archivo.
        return DB::transaction(function () use ($prepared, $size, $by) {
            $image = BlogImage::create([
                'mime' => match ($prepared['extension']) {
                    'jpg' => 'image/jpeg',
                    'webp' => 'image/webp',
                    default => 'image/png',
                },
                'width' => $size[0],
                'height' => $size[1],
                'size' => strlen($prepared['contents']),
                'uploaded_by' => $by->id,
            ]);

            $path = BlogImage::pathFor($image->id, $prepared['extension']);

            if (! $this->media->put($path, $prepared['contents'])) {
                throw new BlogException('No se pudo guardar la imagen. Probá de nuevo en un momento.', 'image');
            }

            $image->forceFill(['path' => $path])->save();

            return $image;
        });
    }

    /**
     * Borra imágenes y sus archivos. Las filas, enseguida; los archivos,
     * después: si el bucket falla, queda un archivo huérfano, no una imagen
     * rota en un artículo.
     *
     * @param  Collection<int, BlogImage>  $images
     */
    public function delete(Collection $images): void
    {
        if ($images->isEmpty()) {
            return;
        }

        BlogImage::query()->whereIn('id', $images->pluck('id'))->delete();

        $images->each(fn (BlogImage $image) => $this->media->delete($image->path));
    }

    /**
     * Las que se subieron y nadie usó (se cerró el editor sin guardar) hace
     * más de un día. Una portada guardada siempre tiene artículo, pero se
     * revisa igual: no se borra una imagen que un artículo todavía muestra.
     */
    public function pruneOrphans(): int
    {
        $orphans = BlogImage::query()
            ->whereNull('post_id')
            ->where('created_at', '<', now()->subHours(BlogImage::ORPHAN_HOURS))
            ->whereNotIn('id', BlogPost::query()->whereNotNull('cover_image_id')->select('cover_image_id'))
            ->get();

        $this->delete($orphans);

        return $orphans->count();
    }
}
