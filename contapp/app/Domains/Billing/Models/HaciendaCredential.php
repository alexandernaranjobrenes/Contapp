<?php

namespace App\Domains\Billing\Models;

use App\Domains\Core\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Las credenciales de una compañía en UN ambiente de Hacienda: el usuario y
 * la contraseña del API del ATV, la llave criptográfica (.p12) con su PIN, y
 * los tokens vigentes.
 *
 * Todo lo secreto está cifrado con la APP_KEY y oculto ($hidden): no sale en
 * un toArray(), ni en una respuesta de Inertia, ni en la Bitácora (que además
 * ignora este modelo). Se descifra solo en memoria, al firmar o al pedir un
 * token. Si cambia la APP_KEY, el Superusuario tiene que volver a cargarlas.
 */
class HaciendaCredential extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id', 'environment', 'username', 'password',
        'certificate', 'certificate_pin', 'certificate_subject', 'certificate_issuer',
        'certificate_serial', 'certificate_holder_id', 'certificate_valid_from', 'certificate_valid_to',
        'certificate_fingerprint', 'certificate_uploaded_at', 'certificate_warned_days',
        'access_token', 'refresh_token', 'token_expires_at', 'refresh_expires_at',
        'last_tested_at', 'last_test_ok', 'last_test_message', 'updated_by',
    ];

    protected $hidden = ['password', 'certificate', 'certificate_pin', 'access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'certificate' => 'encrypted',
            'certificate_pin' => 'encrypted',
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'certificate_valid_from' => 'datetime',
            'certificate_valid_to' => 'datetime',
            'certificate_uploaded_at' => 'datetime',
            'token_expires_at' => 'datetime',
            'refresh_expires_at' => 'datetime',
            'last_tested_at' => 'datetime',
            'last_test_ok' => 'boolean',
        ];
    }

    public function hasApiCredentials(): bool
    {
        return filled($this->username) && filled($this->getRawOriginal('password'));
    }

    public function hasCertificate(): bool
    {
        return filled($this->getRawOriginal('certificate')) && filled($this->getRawOriginal('certificate_pin'));
    }

    public function certificateExpired(?\DateTimeInterface $at = null): bool
    {
        return $this->certificate_valid_to !== null
            && $this->certificate_valid_to->lessThanOrEqualTo($at ?? now());
    }

    /** Los bytes del .p12, descifrados. Solo para firmar. */
    public function certificateBytes(): string
    {
        return base64_decode((string) $this->certificate, true) ?: '';
    }

    /**
     * Lo que la pantalla puede mostrar: nada secreto, solo si está cargado y
     * los datos públicos del certificado.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'environment' => $this->environment,
            'username' => $this->username,
            'has_password' => filled($this->getRawOriginal('password')),
            'has_certificate' => $this->hasCertificate(),
            'certificate_subject' => $this->certificate_subject,
            'certificate_issuer' => $this->certificate_issuer,
            'certificate_serial' => $this->certificate_serial,
            'certificate_holder_id' => $this->certificate_holder_id,
            'certificate_valid_from' => $this->certificate_valid_from?->toIso8601String(),
            'certificate_valid_to' => $this->certificate_valid_to?->toIso8601String(),
            'certificate_expired' => $this->certificateExpired(),
            'certificate_uploaded_at' => $this->certificate_uploaded_at?->toIso8601String(),
            'last_tested_at' => $this->last_tested_at?->toIso8601String(),
            'last_test_ok' => $this->last_test_ok,
            'last_test_message' => $this->last_test_message,
        ];
    }
}
