<?php

namespace App\Domains\Licensing\Services;

use App\Domains\Licensing\Exceptions\InvalidLicenseException;
use App\Domains\Licensing\Models\License;
use App\Domains\Licensing\Models\LicenseCategory;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Emisión/renovación/revocación de licencias. Lo usa exclusivamente
 * LicenseController, protegido por el guard 'propietario' — nunca se expone
 * a un cliente final.
 */
class LicenseService
{
    /**
     * max_companies se copia de la categoría al momento de emitir (no se
     * lee en vivo de category().max_companies en cada chequeo) — igual que
     * ya pasa con expires_at: si la categoría cambia después (ej. sube de
     * 5 a 10 empresas), las licencias YA emitidas bajo ella no deben
     * cambiar de cupo solas por debajo del cliente.
     */
    public function issue(LicenseCategory $category, \DateTimeInterface $expiresAt, ?string $notes, ?int $issuedBy): License
    {
        return License::create([
            'code' => $this->generateCode(),
            'category_id' => $category->id,
            'max_companies' => $category->max_companies,
            'max_admins' => $category->max_admins,
            'max_users' => $category->max_users,
            'expires_at' => $expiresAt->format('Y-m-d'),
            'status' => 'active',
            'notes' => $notes,
            'issued_by' => $issuedBy,
        ]);
    }

    /**
     * Edición de una licencia ya emitida: categoría, cupos y notas. Nunca
     * toca `code`/`superuser_id` (inmutables tras la activación), ni
     * `status`/`expires_at` (tienen su propio flujo: revoke/suspend/
     * reactivate y renew() respectivamente).
     *
     * @param  array{category_id?: int, max_companies?: int, max_admins?: int, max_users?: int, notes?: ?string}  $data
     *
     * @throws InvalidLicenseException si el nuevo cupo queda por debajo de lo ya consumido.
     */
    public function update(License $license, array $data): License
    {
        $data = Arr::only($data, ['category_id', 'max_companies', 'max_admins', 'max_users', 'notes']);

        if (array_key_exists('max_companies', $data) && $data['max_companies'] < $license->companies()->count()) {
            throw new InvalidLicenseException("No podés bajar el cupo de compañías por debajo de las {$license->companies()->count()} ya activas.");
        }
        if (array_key_exists('max_admins', $data) && $data['max_admins'] < $license->adminsCount()) {
            throw new InvalidLicenseException("No podés bajar el cupo de administradores por debajo de los {$license->adminsCount()} ya creados.");
        }
        if (array_key_exists('max_users', $data) && $data['max_users'] < $license->usersCount()) {
            throw new InvalidLicenseException("No podés bajar el cupo de usuarios por debajo de los {$license->usersCount()} ya creados.");
        }

        $license->update($data);

        return $license->fresh();
    }

    public function renew(License $license, \DateTimeInterface $newExpiresAt): License
    {
        $license->update(['expires_at' => $newExpiresAt->format('Y-m-d'), 'status' => 'active']);

        return $license->fresh();
    }

    public function revoke(License $license): License
    {
        $license->update(['status' => 'revoked']);

        return $license->fresh();
    }

    /**
     * Corte administrativo reversible (disputa/impago) — a diferencia de
     * revoke(), que es una baja definitiva. reactivate() es el único camino
     * de vuelta a 'active' desde acá (renew() también reactiva, pero
     * siempre cambiando la fecha de vencimiento; esto no la toca).
     */
    public function suspend(License $license): License
    {
        $license->update(['status' => 'suspended']);

        return $license->fresh();
    }

    public function reactivate(License $license): License
    {
        $license->update(['status' => 'active']);

        return $license->fresh();
    }

    private function generateCode(): string
    {
        do {
            $code = 'CONTAPP-'.strtoupper(Str::random(4)).'-'.strtoupper(Str::random(4)).'-'.strtoupper(Str::random(4));
        } while (License::where('code', $code)->exists());

        return $code;
    }
}
