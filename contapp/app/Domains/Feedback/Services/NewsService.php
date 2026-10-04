<?php

namespace App\Domains\Feedback\Services;

use App\Domains\Core\Support\MediaStorage;
use App\Domains\Feedback\Exceptions\FeedbackException;
use App\Domains\Feedback\Models\NewsPost;
use App\Domains\Feedback\Support\AttachedImage;
use App\Domains\Licensing\Models\Propietario;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Las noticias de CONTAPP que publica el backoffice: título, descripción, un
 * enlace opcional y una imagen opcional, en el bucket como news/{id}_1.{ext}.
 */
class NewsService
{
    public function __construct(private readonly MediaStorage $media) {}

    /**
     * Crea la noticia, o guarda los cambios de una que ya existe ($news con
     * id). Con $image la reemplaza; con $removeImage la quita.
     *
     * @param  array{title: string, body: string, link_url: ?string}  $data
     *
     * @throws FeedbackException
     */
    public function save(NewsPost $news, array $data, Propietario $by, ?UploadedFile $image = null, bool $removeImage = false): NewsPost
    {
        $prepared = null;

        if ($image !== null) {
            $prepared = AttachedImage::normalize($image);

            if ($prepared === null) {
                throw new FeedbackException('No pudimos leer esa imagen. Probá con otra.', 'image');
            }
        }

        $previous = $news->image_path;
        $newPath = null;

        DB::transaction(function () use ($news, $data, $by, $prepared, $removeImage, &$newPath) {
            $news->fill([
                'title' => trim($data['title']),
                'body' => trim($data['body']),
                'link_url' => $data['link_url'],
                'updated_by' => $by->id,
            ]);

            if (! $news->exists) {
                $news->created_by = $by->id;
            }

            if ($removeImage && $prepared === null) {
                $news->image_path = null;
            }

            $news->save();

            if ($prepared !== null) {
                // El nombre lleva el id: hay que crear la fila antes de subirla.
                $newPath = 'news/'.$news->id.'_1.'.$prepared['extension'];

                if (! $this->media->put($newPath, $prepared['contents'])) {
                    $newPath = null;

                    throw new FeedbackException('No se pudo guardar la imagen. Probá de nuevo en un momento.', 'image');
                }

                $news->forceFill(['image_path' => $newPath])->save();
            }
        });

        // Con la misma extensión, la nueva ya pisó a la anterior. Con otra, o
        // si se quitó, la anterior queda sin usar: se borra.
        if ($previous !== null && $previous !== $news->image_path) {
            $this->media->delete($previous);
        }

        Cache::forget(NewsPost::LATEST_CACHE_KEY);

        return $news;
    }

    public function delete(NewsPost $news): void
    {
        $path = $news->image_path;

        $news->delete();

        $this->media->delete($path);

        Cache::forget(NewsPost::LATEST_CACHE_KEY);
    }
}
