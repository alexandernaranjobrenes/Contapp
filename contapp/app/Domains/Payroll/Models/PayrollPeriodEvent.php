<?php

namespace App\Domains\Payroll\Models;

use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Core\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un cambio de estado del período, con quién y por qué. Ver el encabezado de
 * la migración sobre por qué es una tabla y no unas columnas.
 */
class PayrollPeriodEvent extends Model
{
    use BelongsToCompany, HasFactory;

    public const EVENTS = [
        'calculated' => 'Calculada',
        'approved' => 'Aprobada',
        'posted' => 'Contabilizada',
        'reopened' => 'Reabierta',
        'voided' => 'Anulada',
        'closed' => 'Cerrada',
    ];

    /** Los que exigen razón: son los que deshacen algo. */
    public const REQUIRE_REASON = ['reopened', 'voided'];

    protected $fillable = [
        'company_id', 'payroll_period_id', 'event',
        'from_status', 'to_status', 'reason', 'journal_entry_id', 'created_by',
    ];

    public function period(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
