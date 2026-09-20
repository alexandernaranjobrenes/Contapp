<?php

namespace App\Domains\Inventory\Services;

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Scopes\CompanyScope;
use App\Domains\Inventory\Exceptions\InvalidImportCostException;
use App\Domains\Inventory\Models\ImportCostDocument;
use Illuminate\Support\Facades\DB;

/**
 * Fase 1 del costeo de importaciones: acumular el rubro.
 *
 *     Debe   Costos de importación por asignar   (landed_cost_clearing)
 *     Haber  Cuentas por pagar del proveedor     (abre partida, con vencimiento)
 *
 * El rubro entra sin saber todavía a qué importación pertenece. La deuda con
 * la agencia aduanal es real desde ya —hay que pagarla— pero el costo aún no
 * tiene mercancía a la cual sumarse; queda esperando en la transitoria.
 *
 * Es la misma bisagra que GR/IR, en el otro sentido: allá la mercancía llega
 * antes que su factura, acá la factura llega antes de saber sobre qué carga.
 */
class PostImportCostService
{
    public function __construct(
        private readonly PostJournalService $postJournalService,
        private readonly GlDeterminationResolver $glResolver,
    ) {}

    public function accrue(
        Company $company,
        DocumentType $documentType,
        int $businessPartnerId,
        string $concept,
        int|float|string $amount,
        \DateTimeInterface $documentDate,
        \DateTimeInterface $postingDate,
        ?\DateTimeInterface $dueDate = null,
        ?string $description = null,
        ?int $createdBy = null,
    ): ImportCostDocument {
        if ($documentType->company_id !== $company->id) {
            throw new \InvalidArgumentException('El tipo de documento no pertenece a la compañía indicada.');
        }

        if (! array_key_exists($concept, ImportCostDocument::CONCEPTS)) {
            throw new InvalidImportCostException("Rubro de importación desconocido: {$concept}.");
        }

        $total = number_format((float) $amount, 2, '.', '');

        if (bccomp($total, '0.00', 2) <= 0) {
            throw new InvalidImportCostException('El monto del rubro debe ser mayor a cero.');
        }

        return DB::transaction(function () use ($company, $documentType, $businessPartnerId, $concept, $total, $documentDate, $postingDate, $dueDate, $description, $createdBy) {
            $supplier = BusinessPartner::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $company->id)
                ->find($businessPartnerId);

            if (! $supplier) {
                throw new InvalidImportCostException("El socio de negocio id {$businessPartnerId} no existe en la compañía.");
            }

            if (! in_array($supplier->type, ['supplier', 'both'], true)) {
                throw new InvalidImportCostException(
                    "El socio de negocio {$supplier->code} ({$supplier->name}) no está registrado como proveedor."
                );
            }

            // La transitoria se resuelve a nivel de compañía: no depende del
            // artículo ni del almacén, porque en este momento todavía no se
            // sabe sobre qué mercancía va a caer el costo.
            $clearing = $this->glResolver->resolveForCompany(
                $this->glResolver->load($company), 'landed_cost_clearing', $documentType, 'debit'
            );

            $label = $description ?? ImportCostDocument::CONCEPTS[$concept].' — '.$supplier->name;

            $entry = $this->postJournalService->post(
                company: $company,
                documentType: $documentType,
                documentDate: $documentDate,
                postingDate: $postingDate,
                lines: [
                    new JournalLineInput(
                        accountId: $clearing['account_id'],
                        currencyId: $company->local_currency_id,
                        debit: $total,
                        credit: 0,
                        description: $label,
                        costAllocationRuleId: $clearing['cost_allocation_rule_id'],
                    ),
                    new JournalLineInput(
                        accountId: $supplier->gl_account_id,
                        currencyId: $company->local_currency_id,
                        debit: 0,
                        credit: $total,
                        description: $label,
                        businessPartnerId: $supplier->id,
                        dueDate: $dueDate?->format('Y-m-d'),
                        // La deuda con la agencia es real desde ya, aunque el
                        // costo todavía no tenga mercancía asignada.
                        opensItem: true,
                    ),
                ],
                description: $label,
                createdBy: $createdBy,
                dueDate: $dueDate,
            );

            return ImportCostDocument::create([
                'company_id' => $company->id,
                'number' => $this->nextNumber($company),
                'document_type_id' => $documentType->id,
                'journal_entry_id' => $entry->id,
                'business_partner_id' => $supplier->id,
                'concept' => $concept,
                'document_date' => $documentDate->format('Y-m-d'),
                'posting_date' => $postingDate->format('Y-m-d'),
                'due_date' => $dueDate?->format('Y-m-d'),
                'amount' => $total,
                'allocated_amount' => '0.00',
                'status' => 'pending',
                'description' => $description,
                'created_by' => $createdBy,
            ]);
        });
    }

    /**
     * Cancela un rubro que todavía no se asignó a ninguna importación. No
     * borra el asiento: lo revierte, igual que cualquier cosa contabilizada.
     */
    public function cancel(Company $company, ImportCostDocument $document, \DateTimeInterface $postingDate, ?int $createdBy = null): ImportCostDocument
    {
        return DB::transaction(function () use ($company, $document, $postingDate, $createdBy) {
            $fresh = ImportCostDocument::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $company->id)
                ->lockForUpdate()
                ->findOrFail($document->id);

            if ($fresh->status === 'cancelled') {
                throw new InvalidImportCostException('El rubro ya fue cancelado.');
            }

            if (bccomp((string) $fresh->allocated_amount, '0.00', 2) > 0) {
                throw new InvalidImportCostException(
                    'El rubro ya tiene monto asignado a una importación; ese costo está dentro del inventario '.
                    'y no se deshace cancelando: hay que revertir el costeo primero.'
                );
            }

            if ($fresh->journal_entry_id) {
                $this->postJournalService->reverse(
                    company: $company,
                    original: $fresh->journalEntry()->withoutGlobalScope(CompanyScope::class)->firstOrFail(),
                    postingDate: $postingDate,
                    description: "Anulación de rubro de importación #{$fresh->number}",
                    createdBy: $createdBy,
                );
            }

            $fresh->update(['status' => 'cancelled']);

            return $fresh->fresh();
        });
    }

    private function nextNumber(Company $company): string
    {
        $last = ImportCostDocument::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->lockForUpdate()
            ->max('number');

        return str_pad((string) (((int) $last) + 1), 8, '0', STR_PAD_LEFT);
    }
}
