<?php

namespace App\Domains\Feedback\Models;

use App\Domains\Licensing\Models\Propietario;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una publicación del canal de comentarios sobre CONTAPP, o una respuesta a
 * una (parent_id). Ver FeedbackService.
 *
 * Global, sin compañía: la ven todas las cuentas de CONTAPP, sea cual sea su
 * licencia. Por eso de su autor se muestra el nombre y la foto, nunca la
 * compañía ni el correo.
 */
class FeedbackComment extends Model
{
    public const OPEN = 'open';

    public const SOLVED = 'solved';

    public const REMOVED = 'removed';

    /** Imágenes por publicación o respuesta. */
    public const MAX_IMAGES = 2;

    /** Largo máximo del texto. */
    public const MAX_BODY = 2000;

    protected $fillable = [
        'parent_id', 'user_id', 'propietario_id', 'body', 'image_paths', 'score', 'status',
        'solved_at', 'solved_by', 'removed_at', 'removed_by', 'removal_reason',
    ];

    protected function casts(): array
    {
        return [
            'image_paths' => 'array',
            'score' => 'integer',
            'solved_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function propietario(): BelongsTo
    {
        return $this->belongsTo(Propietario::class);
    }

    public function votes(): HasMany
    {
        return $this->hasMany(FeedbackVote::class, 'comment_id');
    }

    /** Solo las publicaciones, sin las respuestas. */
    public function scopePosts(Builder $query): void
    {
        $query->whereNull('parent_id');
    }

    /** Lo que se ve en la aplicación: ni resuelto ni quitado. */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', self::OPEN);
    }

    public function isPost(): bool
    {
        return $this->parent_id === null;
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    /** ¿Lo escribió el equipo de CONTAPP desde el backoffice? */
    public function isFromTeam(): bool
    {
        return $this->propietario_id !== null;
    }

    /** @return list<string> */
    public function imagePaths(): array
    {
        return array_values($this->image_paths ?? []);
    }

    /** El comienzo del texto, para un correo o una tabla. */
    public function excerpt(int $length = 160): string
    {
        return mb_strimwidth(preg_replace('/\s+/u', ' ', trim($this->body)), 0, $length, '…');
    }
}
