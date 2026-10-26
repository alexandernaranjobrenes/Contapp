<?php

namespace App\Domains\Blog\Models;

use App\Domains\Core\Support\MediaStorage;
use App\Domains\Licensing\Models\Propietario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una imagen de un artículo del blog —del texto o la portada— en el bucket,
 * como blog/imag_{id}.{ext} (CLAUDE.md secc. 34 y 41).
 *
 * Nace sin artículo (post_id nulo) al subirla desde el editor, y pasa a ser
 * del artículo cuando un guardado la usa. La que nadie usa en un día la borra
 * `pivox:prune-blog-images`; la que un artículo deja de usar, el mismo
 * guardado.
 */
class BlogImage extends Model
{
    /** Cuánto espera una imagen sin artículo antes de borrarse. */
    public const ORPHAN_HOURS = 24;

    protected $fillable = ['post_id', 'path', 'mime', 'width', 'height', 'size', 'uploaded_by'];

    protected function casts(): array
    {
        return [
            'width' => 'integer',
            'height' => 'integer',
            'size' => 'integer',
        ];
    }

    /** La ruta en el bucket: el id existe antes de subir el archivo. */
    public static function pathFor(int $id, string $extension): string
    {
        return "blog/imag_{$id}.{$extension}";
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(BlogPost::class, 'post_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(Propietario::class, 'uploaded_by');
    }

    /**
     * La dirección pública. Sin versión (?v=): una imagen no se reemplaza, se
     * sube otra con otro id.
     */
    public function url(): ?string
    {
        return app(MediaStorage::class)->url($this->path);
    }
}
