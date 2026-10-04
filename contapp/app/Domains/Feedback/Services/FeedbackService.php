<?php

namespace App\Domains\Feedback\Services;

use App\Domains\Core\Support\MediaStorage;
use App\Domains\Feedback\Exceptions\FeedbackException;
use App\Domains\Feedback\Models\FeedbackComment;
use App\Domains\Feedback\Models\FeedbackVote;
use App\Domains\Feedback\Support\AttachedImage;
use App\Domains\Licensing\Models\Propietario;
use App\Models\User;
use App\Notifications\FeedbackRemovedNotification;
use App\Notifications\FeedbackSolvedNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * El canal de comentarios sobre CONTAPP: las cuentas publican sugerencias,
 * comentarios e ideas, las votan (a favor o en contra) y las comentan; el
 * backoffice las responde, las quita con un motivo o las da por resueltas.
 *
 * - Las publicaciones se votan; las respuestas no. Lo que se ordena por
 *   votos es la lista de publicaciones, y una respuesta es parte de la
 *   conversación de una, que se lee en orden.
 * - Cada publicación o respuesta lleva hasta dos imágenes, en el bucket:
 *   comments/{id}_{n}.{ext}.
 * - Quitar y resolver no borran la fila: la sacan de la aplicación y queda
 *   el registro (quién, cuándo, por qué) para el backoffice. Las imágenes de
 *   lo quitado sí se borran del bucket. Lo que borra su propio autor, en
 *   cambio, se borra del todo.
 */
class FeedbackService
{
    public function __construct(private readonly MediaStorage $media) {}

    /**
     * Una publicación nueva o, con $parent, una respuesta a una.
     *
     * @param  list<UploadedFile>  $images
     *
     * @throws FeedbackException
     */
    public function publish(User|Propietario $author, string $body, array $images = [], ?FeedbackComment $parent = null): FeedbackComment
    {
        if ($parent !== null && (! $parent->isPost() || ! $parent->isOpen())) {
            throw new FeedbackException('Esa publicación ya no recibe comentarios.');
        }

        if (count($images) > FeedbackComment::MAX_IMAGES) {
            throw new FeedbackException('Se pueden adjuntar hasta '.FeedbackComment::MAX_IMAGES.' imágenes.', 'images');
        }

        // Primero se leen todas: una imagen rota no deja una publicación a medias.
        $prepared = [];

        foreach ($images as $file) {
            $image = AttachedImage::normalize($file);

            if ($image === null) {
                throw new FeedbackException('No pudimos leer una de las imágenes. Probá con otra.', 'images');
            }

            $prepared[] = $image;
        }

        $stored = [];

        try {
            return DB::transaction(function () use ($author, $body, $parent, $prepared, &$stored) {
                $comment = FeedbackComment::create([
                    'parent_id' => $parent?->id,
                    'user_id' => $author instanceof User ? $author->id : null,
                    'propietario_id' => $author instanceof Propietario ? $author->id : null,
                    'body' => trim($body),
                    'score' => 0,
                    'status' => FeedbackComment::OPEN,
                ]);

                // El nombre lleva el id: hay que crear la fila antes de subirlas.
                foreach ($prepared as $index => $image) {
                    $path = 'comments/'.$comment->id.'_'.($index + 1).'.'.$image['extension'];

                    if (! $this->media->put($path, $image['contents'])) {
                        throw new FeedbackException('No se pudieron guardar las imágenes. Probá de nuevo en un momento.', 'images');
                    }

                    $stored[] = $path;
                }

                if ($stored !== []) {
                    $comment->forceFill(['image_paths' => $stored])->save();
                }

                return $comment;
            });
        } catch (Throwable $e) {
            // La fila no quedó: que tampoco queden sus imágenes en el bucket.
            foreach ($stored as $path) {
                $this->media->delete($path);
            }

            throw $e;
        }
    }

    /**
     * Vota una publicación: 1 a favor, -1 en contra, 0 quita el voto. Devuelve
     * la publicación con su puntaje nuevo.
     *
     * @throws FeedbackException
     */
    public function vote(FeedbackComment $post, User $user, int $value): FeedbackComment
    {
        if (! $post->isPost() || ! $post->isOpen()) {
            throw new FeedbackException('Esa publicación ya no está a la vista.');
        }

        if ($post->user_id === $user->id) {
            throw new FeedbackException('No podés votar tu propia publicación.');
        }

        DB::transaction(function () use ($post, $user, $value) {
            // Los votos simultáneos a una misma publicación van de a uno: cada
            // uno recalcula el puntaje con los votos de los anteriores.
            FeedbackComment::whereKey($post->id)->lockForUpdate()->first();

            if ($value === 0) {
                FeedbackVote::where('comment_id', $post->id)->where('user_id', $user->id)->delete();
            } else {
                FeedbackVote::updateOrCreate(
                    ['comment_id' => $post->id, 'user_id' => $user->id],
                    ['value' => $value > 0 ? 1 : -1],
                );
            }

            // Sin tocar updated_at: un voto no es un cambio de la publicación.
            FeedbackComment::whereKey($post->id)->toBase()->update([
                'score' => (int) FeedbackVote::where('comment_id', $post->id)->sum('value'),
            ]);
        });

        return $post->refresh();
    }

    /**
     * El autor borra su publicación o su respuesta. Se borra del todo, con
     * sus imágenes; una publicación, también con sus respuestas y sus votos.
     *
     * @throws FeedbackException
     */
    public function deleteOwn(FeedbackComment $comment, User $user): void
    {
        if ($comment->user_id !== $user->id) {
            throw new FeedbackException('Solo podés borrar lo que escribiste vos.');
        }

        $paths = $comment->imagePaths();

        if ($comment->isPost()) {
            foreach ($comment->replies()->get() as $reply) {
                array_push($paths, ...$reply->imagePaths());
            }
        }

        $comment->delete();

        foreach ($paths as $path) {
            $this->media->delete($path);
        }
    }

    /**
     * El backoffice quita una publicación o una respuesta. Deja de verse en
     * la aplicación, se borran sus imágenes y al autor le llega el motivo por
     * correo. Devuelve si el correo salió.
     *
     * @throws FeedbackException
     */
    public function remove(FeedbackComment $comment, Propietario $by, string $reason): bool
    {
        if ($comment->status === FeedbackComment::REMOVED) {
            throw new FeedbackException('Ya estaba eliminado.', 'reason');
        }

        $paths = $comment->imagePaths();

        $comment->forceFill([
            'status' => FeedbackComment::REMOVED,
            'removed_at' => now(),
            'removed_by' => $by->id,
            'removal_reason' => trim($reason),
            'image_paths' => null,
        ])->save();

        foreach ($paths as $path) {
            $this->media->delete($path);
        }

        return $this->notifyAuthor($comment, new FeedbackRemovedNotification($comment));
    }

    /**
     * El backoffice da una publicación por resuelta: deja de verse en la
     * aplicación y al autor le llega un agradecimiento, con $note si se
     * escribió. Devuelve si el correo salió.
     *
     * @throws FeedbackException
     */
    public function solve(FeedbackComment $post, Propietario $by, ?string $note = null): bool
    {
        if (! $post->isPost() || ! $post->isOpen()) {
            throw new FeedbackException('Solo una publicación a la vista se puede dar por resuelta.', 'note');
        }

        $post->forceFill([
            'status' => FeedbackComment::SOLVED,
            'solved_at' => now(),
            'solved_by' => $by->id,
        ])->save();

        $note = $note !== null && trim($note) !== '' ? trim($note) : null;

        return $this->notifyAuthor($post, new FeedbackSolvedNotification($post, $note));
    }

    /**
     * Vuelve a mostrar una publicación resuelta (se resolvió por error). No
     * le avisa a nadie.
     *
     * @throws FeedbackException
     */
    public function reopen(FeedbackComment $post): void
    {
        if (! $post->isPost() || $post->status !== FeedbackComment::SOLVED) {
            throw new FeedbackException('Solo una publicación resuelta se puede reabrir.', 'note');
        }

        $post->forceFill(['status' => FeedbackComment::OPEN, 'solved_at' => null, 'solved_by' => null])->save();
    }

    /**
     * El correo al autor. Si lo escribió el equipo, o la cuenta ya no
     * existe, no hay a quién. Si el servidor de correo falla, lo hecho no se
     * deshace: se reporta y se avisa en pantalla.
     */
    private function notifyAuthor(FeedbackComment $comment, Notification $notification): bool
    {
        $author = $comment->user;

        if ($author === null) {
            return false;
        }

        try {
            $author->notify($notification);
        } catch (Throwable $e) {
            report($e);

            return false;
        }

        return true;
    }
}
