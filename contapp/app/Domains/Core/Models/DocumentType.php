<?php

namespace App\Domains\Core\Models;

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Core\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DocumentType extends Model
{
    use BelongsToCompany, HasFactory;

    public const ORIGIN_MODULES = [
        'ventas' => 'Ventas',
        'compras' => 'Compras',
        'bancos' => 'Bancos',
        'contable' => 'Contable',
        'cxc' => 'Cuentas por Cobrar',
        'cxp' => 'Cuentas por Pagar',
        'activos_fijos' => 'Activos Fijos',
    ];

    public const CURRENCY_MODES = [
        'local_fija' => 'Local fija',
        'extranjera_fija' => 'Extranjera fija',
        'libre' => 'Libre (la que traiga cada línea)',
    ];

    /**
     * Qué exige este tipo de documento en cada línea que trae socio de
     * negocio: 'due_date' obliga a abrir partida (opensItem, con vencimiento);
     * 'application' obliga a aplicar contra una partida existente
     * (applyToOpenItemId); 'either' acepta cualquiera de las dos por línea,
     * pero no ambas a la vez (ver JournalLineInput). Es la base de los
     * reportes de antigüedad de saldos y estados de cuenta por S.N.
     * (docs/decisiones.md 2026-08-24) — PostJournalService::post() es quien
     * hace cumplir esto, y solo al contabilizar en serio, nunca en un borrador.
     */
    public const BP_LINE_REQUIREMENTS = [
        'none' => 'Ninguno',
        'due_date' => 'Vencimiento (abre partida)',
        'application' => 'Aplicación (cancela partida existente)',
        'either' => 'Ambos (vencimiento o aplicación, a elegir por línea)',
    ];

    protected $fillable = [
        'company_id', 'code', 'name', 'origin_module', 'generates_journal', 'requires_electronic_key',
        'bp_line_requirement', 'is_opening_type', 'is_reconciliation_type', 'is_closing_type',
        'default_debit_account_id', 'default_credit_account_id',
        'numbering_mask', 'field_count', 'next_consecutive', 'range_from', 'range_to',
        'consecutive_on_save', 'allow_out_of_range_dates', 'prevent_admins_out_of_range',
        'date_range_from', 'date_range_to', 'currency_mode', 'allows_balance_increase',
        'reads_document_classifications', 'status',
    ];

    protected function casts(): array
    {
        return [
            'generates_journal' => 'boolean',
            'requires_electronic_key' => 'boolean',
            'is_opening_type' => 'boolean',
            'is_reconciliation_type' => 'boolean',
            'is_closing_type' => 'boolean',
            'consecutive_on_save' => 'boolean',
            'allow_out_of_range_dates' => 'boolean',
            'prevent_admins_out_of_range' => 'boolean',
            'allows_balance_increase' => 'boolean',
            'reads_document_classifications' => 'boolean',
            'date_range_from' => 'date',
            'date_range_to' => 'date',
        ];
    }

    /**
     * Tipos de documento activos que de verdad generan asientos — el único
     * universo que tiene sentido ofrecer para elegir en el registro por tipo
     * de documento (Reports\DocumentTypeRegisterController y el botón
     * contextual de JournalEntries/Show.vue): un tipo inactivo o que no
     * genera asientos nunca tendría entradas que exportar.
     */
    public function scopeGeneratesJournalActive(Builder $query): Builder
    {
        return $query->where('generates_journal', true)->where('status', 'active');
    }

    public function defaultDebitAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'default_debit_account_id');
    }

    public function defaultCreditAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'default_credit_account_id');
    }

    public function printingConfig(): HasOne
    {
        return $this->hasOne(DocumentTypePrinting::class);
    }

    public function officers(): HasMany
    {
        return $this->hasMany(DocumentTypeOfficer::class);
    }

    public function visibleColumns(): HasMany
    {
        return $this->hasMany(DocumentTypeVisibleColumn::class);
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(DocumentTypePermission::class);
    }

    public function numberSeries(): HasMany
    {
        return $this->hasMany(DocumentTypeNumberSeries::class);
    }

    /**
     * Resuelve el permiso más restrictivo entre el otorgado directamente al usuario
     * y el otorgado a cualquiera de sus roles en la compañía activa.
     */
    public function userCan(\App\Models\User $user, string $ability): bool
    {
        if ($user->isSuperAdmin($this->company_id)) {
            return true;
        }

        $roleIds = $user->userRoles()
            ->where('company_id', $this->company_id)
            ->pluck('role_id');

        return $this->permissions()
            ->where(function ($query) use ($user, $roleIds) {
                $query->where(fn ($q) => $q->where('subject_type', 'user')->where('subject_id', $user->id))
                    ->orWhere(fn ($q) => $q->where('subject_type', 'role')->whereIn('subject_id', $roleIds));
            })
            ->where($ability, true)
            ->exists();
    }
}
