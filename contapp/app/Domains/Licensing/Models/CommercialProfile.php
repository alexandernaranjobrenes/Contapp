<?php

namespace App\Domains\Licensing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Capa comercial (CLAUDE.md secc. 15), separada por completo del dato duro
 * de licenciamiento (vigencia/categoría/cupo, que sigue viviendo solo en
 * License/LicenseCategory) — este perfil solo referencia la licencia por
 * license_id, nunca duplica ni recalcula esos datos.
 */
class CommercialProfile extends Model
{
    protected $fillable = ['license_id', 'contact_name', 'phone', 'commercial_email', 'referral_source', 'notes'];

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    public function interactions(): HasMany
    {
        return $this->hasMany(CommercialInteraction::class)->orderByDesc('occurred_at')->orderByDesc('id');
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(CommercialFollowUp::class)->orderBy('next_action_date');
    }

    /**
     * El seguimiento pendiente más próximo — usado en el listado principal
     * del backoffice (CLAUDE.md secc. 15: "estado de licencia + próxima
     * acción comercial pendiente en una sola vista, dos fuentes distintas
     * unidas en la consulta"). Se resuelve en PHP sobre followUps() ya
     * cargada (ordenada por next_action_date) en vez de con un HasOne
     * ofMany(): esa API de Eloquent no aplica un where() encadenado antes
     * de oldestOfMany() DENTRO de la subconsulta de agregación, así que
     * terminaba comparando contra la fecha mínima entre TODOS los
     * seguimientos (incluidos los completados) — un bug real encontrado al
     * escribir el primer test, no una preferencia de estilo.
     */
    public function nextPendingFollowUp(): ?CommercialFollowUp
    {
        return $this->followUps->firstWhere('status', 'pending');
    }
}
