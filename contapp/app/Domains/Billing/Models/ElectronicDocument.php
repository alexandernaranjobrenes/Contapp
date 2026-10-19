<?php

namespace App\Domains\Billing\Models;

use App\Domains\Billing\Exceptions\InvalidElectronicTransitionException;
use App\Domains\Core\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * La vida fiscal de un comprobante: el XML firmado y su estado ante Hacienda.
 *
 * ── Los estados ─────────────────────────────────────────────────────────
 *
 *   signed ──► submitting ──► submitted ──► accepted
 *                  │               │    └──► rejected ──► superseded
 *                  │               └──────► needs_attention
 *                  ├──► submit_unknown (tiempo agotado, 5xx: ¿llegó?)
 *                  └──► submit_failed  (400: Hacienda no lo recibió) ──► superseded
 *
 * - Desde signed, el XML no cambia: corregir es emitir otro comprobante.
 * - Una clave que Hacienda ya registró nunca se reenvía. Solo se reenvía la
 *   MISMA clave cuando hay certeza de que no llegó (la consulta da 404).
 * - rejected y submit_failed se corrigen con una clave nueva (superseded).
 * - accepted y superseded son finales: lo de ahí en adelante es una nota.
 *
 * Una transición que no está en la tabla LANZA, en vez de ignorarse: si la
 * cola intenta algo imposible, es un error que tiene que verse.
 */
class ElectronicDocument extends Model
{
    use BelongsToCompany;

    public const SIGNED = 'signed';

    public const SUBMITTING = 'submitting';

    public const SUBMITTED = 'submitted';

    public const SUBMIT_UNKNOWN = 'submit_unknown';

    public const SUBMIT_FAILED = 'submit_failed';

    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    public const NEEDS_ATTENTION = 'needs_attention';

    public const SUPERSEDED = 'superseded';

    public const LABELS = [
        self::SIGNED => 'Firmado, por enviar',
        self::SUBMITTING => 'Enviando a Hacienda',
        self::SUBMITTED => 'En proceso en Hacienda',
        self::SUBMIT_UNKNOWN => 'Envío sin confirmar',
        self::SUBMIT_FAILED => 'Hacienda no lo recibió',
        self::ACCEPTED => 'Aceptado',
        self::REJECTED => 'Rechazado',
        self::NEEDS_ATTENTION => 'Requiere atención',
        self::SUPERSEDED => 'Sustituido',
    ];

    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        // A «requiere atención» sin haber salido: Hacienda rechazó las
        // credenciales antes de poder enviarlo.
        self::SIGNED => [self::SUBMITTING, self::NEEDS_ATTENTION],
        self::SUBMITTING => [self::SUBMITTED, self::SUBMIT_UNKNOWN, self::SUBMIT_FAILED, self::NEEDS_ATTENTION, self::ACCEPTED, self::REJECTED],
        self::SUBMIT_UNKNOWN => [self::SUBMITTING, self::SUBMITTED, self::ACCEPTED, self::REJECTED, self::NEEDS_ATTENTION],
        self::SUBMITTED => [self::ACCEPTED, self::REJECTED, self::NEEDS_ATTENTION],
        self::NEEDS_ATTENTION => [self::SUBMITTING, self::SUBMITTED, self::SUBMIT_UNKNOWN, self::ACCEPTED, self::REJECTED],
        self::SUBMIT_FAILED => [self::SUPERSEDED],
        self::REJECTED => [self::SUPERSEDED],
        self::ACCEPTED => [],
        self::SUPERSEDED => [],
    ];

    protected $fillable = [
        'company_id', 'sales_document_id', 'superseded_by_id', 'environment', 'document_type',
        'clave', 'consecutive', 'issued_at', 'status', 'signed_xml', 'signed_xml_sha256',
        'emitter_id_type', 'emitter_id_number', 'receiver_id_type', 'receiver_id_number',
        'submit_attempts', 'poll_attempts', 'next_attempt_at', 'submitted_at', 'last_checked_at',
        'accepted_at', 'rejected_at', 'hacienda_status', 'hacienda_message', 'response_xml',
        'response_signature_valid', 'delivery_status', 'delivered_at', 'delivery_error', 'delivery_recipients',
    ];

    // El XML firmado y la respuesta pesan decenas de KB: no viajan en un
    // listado ni en una respuesta de Inertia. Se descargan aparte.
    protected $hidden = ['signed_xml', 'response_xml'];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'submitted_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'accepted_at' => 'datetime',
            'rejected_at' => 'datetime',
            'delivered_at' => 'datetime',
            'delivery_recipients' => 'array',
            'response_signature_valid' => 'boolean',
            'submit_attempts' => 'integer',
            'poll_attempts' => 'integer',
        ];
    }

    public function salesDocument(): BelongsTo
    {
        return $this->belongsTo(SalesDocument::class);
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ElectronicDocumentEvent::class)->orderBy('id');
    }

    public function exchanges(): HasMany
    {
        return $this->hasMany(ElectronicDocumentExchange::class)->orderBy('id');
    }

    public function label(): string
    {
        return self::LABELS[$this->status] ?? $this->status;
    }

    public function isFinal(): bool
    {
        return in_array($this->status, [self::ACCEPTED, self::SUPERSEDED], true);
    }

    /** Lo que la cola tiene que enviar o consultar. */
    public function isPending(): bool
    {
        return in_array($this->status, [self::SIGNED, self::SUBMITTING, self::SUBMIT_UNKNOWN, self::SUBMITTED], true);
    }

    /** Hacienda no lo tiene o lo rechazó: se corrige con una clave nueva. */
    public function canBeReissued(): bool
    {
        return in_array($this->status, [self::REJECTED, self::SUBMIT_FAILED], true)
            && $this->superseded_by_id === null;
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    /**
     * Cambia el estado y lo anota. Quedarse en el mismo estado no es una
     * transición (una consulta que vuelve a decir «procesando»): no lanza ni
     * anota nada.
     *
     * @param  array<string, mixed>  $attributes  lo que cambia junto con el estado
     */
    public function transitionTo(string $status, ?string $message = null, ?int $userId = null, array $attributes = []): void
    {
        if ($status === $this->status) {
            if ($attributes !== []) {
                $this->update($attributes);
            }

            return;
        }

        if (! $this->canTransitionTo($status)) {
            throw new InvalidElectronicTransitionException(sprintf(
                'El comprobante %s no puede pasar de «%s» a «%s».',
                $this->clave, $this->label(), self::LABELS[$status] ?? $status,
            ));
        }

        $from = $this->status;

        $this->update([...$attributes, 'status' => $status]);

        $this->events()->create([
            'from_status' => $from,
            'to_status' => $status,
            'message' => $message === null ? null : mb_substr($message, 0, 2000),
            'user_id' => $userId,
            'created_at' => now(),
        ]);
    }
}
