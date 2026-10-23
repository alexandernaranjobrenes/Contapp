<?php

namespace App\Domains\Repository\Models;

use App\Domains\Licensing\Models\Propietario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Un documento del Repositorio: un Markdown que subió el backoffice, en el
 * disco «repositories» (public/repositories/{file_path}). Global, sin
 * compañía.
 *
 * Dos interruptores, independientes:
 * - conti_enabled: Conti lo consulta con su herramienta «repositorio».
 * - public_enabled: cualquier cuenta lo ve en Repositorio, como PDF.
 */
class RepositoryDocument extends Model
{
    public const DISK = 'repositories';

    public const MAX_TITLE = 200;

    public const MAX_DESCRIPTION = 1000;

    public const MAX_KILOBYTES = 5120;

    protected $fillable = [
        'folder_id', 'title', 'description', 'file_path', 'original_name', 'size',
        'conti_enabled', 'public_enabled', 'file_updated_at', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'conti_enabled' => 'boolean',
            'public_enabled' => 'boolean',
            'file_updated_at' => 'datetime',
            'size' => 'integer',
        ];
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(RepositoryFolder::class, 'folder_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Propietario::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(Propietario::class, 'updated_by');
    }

    /** Los que ven las cuentas en Repositorio. */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('public_enabled', true);
    }

    /** Los que consulta Conti. */
    public function scopeForConti(Builder $query): Builder
    {
        return $query->where('conti_enabled', true);
    }

    /** El Markdown tal como está en el disco, o null si el archivo ya no está. */
    public function markdown(): ?string
    {
        $contents = Storage::disk(self::DISK)->get($this->file_path);

        return is_string($contents) ? $contents : null;
    }
}
