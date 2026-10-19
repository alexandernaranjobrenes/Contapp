<?php

namespace App\Domains\Billing\Services\Hacienda;

use App\Domains\Billing\Exceptions\ElectronicInvoicingException;
use App\Domains\Billing\Models\ElectronicDocument;
use App\Domains\Billing\Models\FiscalSequence;
use App\Domains\Billing\Models\SalesDocument;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Scopes\CompanyScope;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * El número fiscal que sigue, por sucursal, terminal y tipo de comprobante.
 *
 * La fila de fiscal_sequences se bloquea (FOR UPDATE) dentro de la misma
 * transacción que crea el comprobante: dos ventas simultáneas esperan una a
 * la otra en vez de tomar el mismo número, y si la venta falla el número
 * vuelve, porque la transacción se deshace entera. Un MAX() + 1 sin bloqueo
 * no hace ninguna de las dos cosas.
 *
 * La primera vez que se pide una serie, arranca después del número más alto
 * que ya se usó con ese prefijo (los comprobantes emitidos antes de encender
 * la facturación electrónica, o los sustituidos), para no repetir ninguno.
 */
class FiscalSequenceAllocator
{
    public function next(Company $company, string $branch, string $terminal, string $documentType): int
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('El consecutivo fiscal se asigna dentro de la transacción del comprobante.');
        }

        $sequence = $this->lockedRow($company, $branch, $terminal, $documentType);
        $next = $sequence->last_number + 1;

        if ($next > 9999999999) {
            throw new ElectronicInvoicingException(
                "La numeración del tipo {$documentType} en la sucursal {$branch}, terminal {$terminal}, llegó a su máximo de 10 dígitos."
            );
        }

        $sequence->update(['last_number' => $next]);

        return $next;
    }

    /** El próximo número, sin reservarlo: para mostrarlo en pantalla. */
    public function peek(Company $company, string $branch, string $terminal, string $documentType): int
    {
        $last = $this->query($company, $branch, $terminal, $documentType)->value('last_number');

        return ($last ?? $this->highestUsed($company, $branch, $terminal, $documentType)) + 1;
    }

    /**
     * El Superusuario fija desde qué número sigue una serie (para continuar la
     * numeración que traía de TribuCR o de otro sistema). Nunca hacia atrás
     * de uno ya usado: repetiría un consecutivo.
     */
    public function setNext(Company $company, string $branch, string $terminal, string $documentType, int $next): void
    {
        DB::transaction(function () use ($company, $branch, $terminal, $documentType, $next) {
            $sequence = $this->lockedRow($company, $branch, $terminal, $documentType);
            $highest = max($sequence->last_number, $this->highestUsed($company, $branch, $terminal, $documentType));

            if ($next <= $highest && $next - 1 !== $sequence->last_number) {
                throw new ElectronicInvoicingException(
                    "El número {$next} ya se usó o quedaría detrás del último emitido ({$highest}). El próximo tiene que ser mayor a {$highest}."
                );
            }

            if ($next < 1 || $next > 9999999999) {
                throw new ElectronicInvoicingException('El próximo número tiene que estar entre 1 y 9999999999.');
            }

            $sequence->update(['last_number' => $next - 1]);
        });
    }

    private function lockedRow(Company $company, string $branch, string $terminal, string $documentType): FiscalSequence
    {
        $sequence = $this->query($company, $branch, $terminal, $documentType)->lockForUpdate()->first();

        if ($sequence) {
            return $sequence;
        }

        try {
            // Un savepoint propio: si otra transacción la crea primero, el
            // error de unicidad no aborta la transacción del comprobante
            // (en PostgreSQL lo haría).
            DB::transaction(fn () => FiscalSequence::withoutGlobalScope(CompanyScope::class)->create([
                'company_id' => $company->id,
                'branch' => $branch,
                'terminal' => $terminal,
                'document_type' => $documentType,
                'last_number' => $this->highestUsed($company, $branch, $terminal, $documentType),
            ]));
        } catch (UniqueConstraintViolationException) {
            // La creó otra venta al mismo tiempo: se usa esa.
        }

        return $this->query($company, $branch, $terminal, $documentType)->lockForUpdate()->firstOrFail();
    }

    private function query(Company $company, string $branch, string $terminal, string $documentType)
    {
        return FiscalSequence::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->where('branch', $branch)
            ->where('terminal', $terminal)
            ->where('document_type', $documentType);
    }

    /** El número más alto que ya lleva un comprobante con este prefijo. */
    private function highestUsed(Company $company, string $branch, string $terminal, string $documentType): int
    {
        $prefix = $branch.$terminal.$documentType;

        $fromDocuments = SalesDocument::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->where('consecutive', 'like', $prefix.'%')
            ->max('consecutive');

        $fromElectronic = ElectronicDocument::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->where('consecutive', 'like', $prefix.'%')
            ->max('consecutive');

        return max((int) substr((string) $fromDocuments, 10), (int) substr((string) $fromElectronic, 10));
    }
}
