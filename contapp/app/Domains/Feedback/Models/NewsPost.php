<?php

namespace App\Domains\Feedback\Models;

use App\Domains\Licensing\Models\Propietario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una noticia de CONTAPP —una actualización, un aviso— que publica el
 * backoffice y ven todas las cuentas en el panel de la barra superior
 * (pestaña «Noticias»). Global, sin compañía.
 *
 * link_url es opcional: una dirección completa (https://…), que se abre en
 * otra pestaña, o una pantalla de CONTAPP (/dashboard), que se abre en la
 * misma.
 */
class NewsPost extends Model
{
    public const MAX_TITLE = 150;

    public const MAX_BODY = 2000;

    /** Caché de la fecha de la última noticia (el punto del botón de la barra). */
    public const LATEST_CACHE_KEY = 'news_posts.latest_at';

    protected $fillable = ['title', 'body', 'link_url', 'image_path', 'created_by', 'updated_by'];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Propietario::class, 'created_by');
    }

    /** ¿El enlace es una pantalla de CONTAPP y no otro sitio? */
    public function hasInternalLink(): bool
    {
        return $this->link_url !== null && str_starts_with($this->link_url, '/');
    }
}
