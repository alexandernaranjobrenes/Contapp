<?php

namespace App\Domains\Accounting\Models;

use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Core\Concerns\BelongsToCompany;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Models\DocumentTypeNumberSeries;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JournalEntry extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'document_type_id', 'document_number', 'number_series_id', 'series_number',
        'document_date', 'posting_date', 'due_date',
        'fiscal_period_id', 'description', 'status', 'reversal_of_id', 'source_module',
        'business_partner_id', 'created_by', 'posted_by', 'posted_at', 'schedule_id',
    ];

    // updated_at ya no se ignora (antes se asumía que un asiento solo se
    // insertaba una vez): un borrador se puede editar varias veces antes de
    // contabilizarlo formalmente, así que ahora sí vale la pena rastrearlo.

    protected function casts(): array
    {
        return [
            'document_date' => 'date',
            'posting_date' => 'date',
            'due_date' => 'date',
            'posted_at' => 'datetime',
        ];
    }

    /**
     * Sin esto, document_date se serializa a JSON con hora y zona
     * (ej. "2026-08-17T00:00:00.000000Z") en vez de "2026-08-17" — se nota
     * apenas se muestra en una tabla (JournalEntries/Index.vue). serializeDate()
     * es global al modelo (no por atributo), así que esto asume que ningún
     * datetime con hora real (posted_at) se selecciona junto al resto de
     * columnas hacia Inertia — JournalEntryController::index() lo respeta
     * seleccionando columnas explícitas, igual que el resto de los índices.
     */
    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d');
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function numberSeries(): BelongsTo
    {
        return $this->belongsTo(DocumentTypeNumberSeries::class);
    }

    public function businessPartner(): BelongsTo
    {
        return $this->belongsTo(BusinessPartner::class);
    }

    public function fiscalPeriod(): BelongsTo
    {
        return $this->belongsTo(FiscalPeriod::class);
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(JournalEntrySchedule::class, 'schedule_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function details(): HasMany
    {
        return $this->hasMany(JournalDetail::class);
    }
}
