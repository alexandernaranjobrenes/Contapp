<?php

namespace Database\Seeders;

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\CostAllocationRule;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\BusinessPartners\Models\BpOpenItem;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Support\CurrentCompany;
use App\Models\User;
use DateTime;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Asientos de ejemplo YA CONTABILIZADOS, para que la compañía de prueba
 * tenga saldos reales contra los cuales mirar balance de comprobación,
 * estado de resultados, mayor auxiliar, antigüedad de saldos y reportes de
 * IVA — en vez de una compañía correctamente configurada pero en cero.
 *
 * Corre DESPUÉS de TestChartOfAccountsSeeder y TestCompanySetupSeeder.
 *
 * Todo pasa por PostJournalService::post(), el mismo camino que el
 * formulario de asientos: nada se inserta a mano en journal_entries. Eso
 * garantiza que los saldos, las partidas de CxC/CxP, el reparto por centro
 * de costo, los impuestos y la triple moneda queden exactamente como los
 * dejaría un usuario digitando, y que cualquier regla que se agregue después
 * también se aplique a estos datos.
 *
 * Todo el bloque va en UNA transacción: si un solo asiento falla, no queda
 * ninguno a medias. Con $dryRun = true se revierte siempre — sirve para
 * comprobar que el juego completo contabiliza sin dejar nada escrito.
 *
 * Idempotente por omisión: si ya existen asientos con el prefijo de prueba,
 * no vuelve a crearlos.
 *
 * Uso:
 *   SEED_COMPANY_ID=604 php artisan db:seed --class=TestJournalEntriesSeeder
 */
class TestJournalEntriesSeeder extends Seeder
{
    /** Prefijo que marca estos asientos como datos de prueba. */
    private const MARKER = '[PRUEBA]';

    /** Compañía destino. Si queda en null se lee de SEED_COMPANY_ID. */
    public ?int $companyId = null;

    /** true: contabiliza todo y revierte al final, sin dejar nada. */
    public bool $dryRun = false;

    public function __construct(private readonly PostJournalService $poster) {}

    public function run(): void
    {
        $companyId = $this->companyId ?? (int) env('SEED_COMPANY_ID');

        if ($companyId <= 0) {
            throw new RuntimeException(
                'Indicá la compañía destino: SEED_COMPANY_ID=<id> php artisan db:seed --class=TestJournalEntriesSeeder'
            );
        }

        // SEED_DRY_RUN=1 permite pedir la simulación desde la misma línea de
        // comandos, sin tener que instanciar el seeder a mano.
        $this->dryRun = $this->dryRun || (bool) env('SEED_DRY_RUN');

        $company = Company::findOrFail($companyId);
        app(CurrentCompany::class)->set($company->id);

        $existing = JournalEntry::where('description', 'like', self::MARKER.'%')->count();

        if ($existing > 0 && ! $this->dryRun) {
            $this->command?->warn("  Ya hay {$existing} asientos de prueba en esta compañía; no se crean de nuevo.");

            return;
        }

        // El autor de los asientos: el superusuario de la licencia de esta
        // compañía si se puede resolver, para que el rastro de auditoría
        // apunte a alguien real y no a un id inventado.
        $createdBy = $company->license?->superuser_id
            ?? User::whereHas('companies', fn ($q) => $q->where('companies.id', $company->id))->value('id');

        DB::beginTransaction();

        try {
            $posted = $this->postAll($company, $createdBy);

            if ($this->dryRun) {
                DB::rollBack();
                $this->command?->info('  SIMULACIÓN: '.count($posted).' asientos contabilizaron bien. Nada quedó escrito.');

                return;
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            throw new RuntimeException(
                'Ningún asiento quedó escrito. Falló: '.get_class($e).' — '.$e->getMessage(),
                previous: $e
            );
        }

        foreach ($posted as $line) {
            $this->command?->line("  {$line}");
        }

        $this->command?->info('  '.count($posted).' asientos de ejemplo contabilizados.');
    }

    /**
     * @return array<int, string> una línea descriptiva por asiento
     */
    private function postAll(Company $company, ?int $createdBy): array
    {
        $crc = $company->local_currency_id;
        $usd = $company->foreign_currency_id;

        $acc = fn (string $code) => ChartOfAccount::where('code', $code)->firstOrFail()->id;
        $doc = fn (string $code) => DocumentType::where('code', $code)->firstOrFail();
        $bp = fn (string $code) => BusinessPartner::where('code', $code)->firstOrFail()->id;
        $rule = fn (string $code) => CostAllocationRule::where('code', $code)->firstOrFail()->id;
        $ivaRate = fn (string $code) => ChartOfAccount::where('code', $code)->firstOrFail()->tax_rate_id;

        $log = [];

        // ── 1. Aporte inicial de capital ──────────────────────────────────
        $log[] = $this->post($company, $doc('ADD'), '2026-01-05', 'Aporte inicial de capital', $createdBy, [
            new JournalLineInput(accountId: $acc('1-01-01-02-001'), currencyId: $crc, debit: '25000000.00', credit: 0,
                description: 'Depósito de los socios'),
            new JournalLineInput(accountId: $acc('3-01-01-01-001'), currencyId: $crc, debit: 0, credit: '25000000.00',
                description: 'Capital social suscrito y pagado'),
        ]);

        // ── 2. Compra de equipo de cómputo a crédito (con IVA soportado) ──
        $compraEquipo = $this->post($company, $doc('FCP'), '2026-01-20', 'Compra de equipo de cómputo', $createdBy, [
            new JournalLineInput(accountId: $acc('1-02-01-02-002'), currencyId: $crc, debit: '3000000.00', credit: 0,
                description: 'Tres estaciones de trabajo'),
            new JournalLineInput(accountId: $acc('1-01-04-01-001'), currencyId: $crc, debit: '390000.00', credit: 0,
                description: 'IVA soportado 13%',
                taxRateId: $ivaRate('1-01-04-01-001'), taxableBase: '3000000.00'),
            new JournalLineInput(accountId: $acc('2-01-01-01-001'), currencyId: $crc, debit: 0, credit: '3390000.00',
                description: 'Suministros Industriales del Sur',
                businessPartnerId: $bp('PRV-001'), dueDate: '2026-02-19', opensItem: true),
        ], returnEntry: true);

        $log[] = $this->describe($compraEquipo, 'Compra de equipo de cómputo');

        // La compra de mercadería y el costo de la venta NO se asientan acá:
        // los mueve TestInventoryMovementsSeeder por el módulo de inventario
        // (entrada por compra + factura del proveedor, y salida por venta).
        // Asentarlos a mano contra la cuenta de inventario dejaría el saldo
        // contable por encima de la valoración del kardex, que es justo el
        // descuadre que este módulo existe para no tener.

        // ── 3. Factura de venta a crédito ─────────────────────────────────
        $venta1 = $this->post($company, $doc('FVE'), '2026-03-05', 'Factura de venta a Distribuidora La Central', $createdBy, [
            new JournalLineInput(accountId: $acc('1-01-02-01-001'), currencyId: $crc, debit: '5650000.00', credit: 0,
                description: 'Distribuidora La Central S.A.',
                businessPartnerId: $bp('CLI-001'), dueDate: '2026-04-04', opensItem: true),
            new JournalLineInput(accountId: $acc('4-01-01-01-001'), currencyId: $crc, debit: 0, credit: '5000000.00',
                description: 'Venta de mercadería gravada 13%', costAllocationRuleId: $rule('VEN100')),
            new JournalLineInput(accountId: $acc('2-01-02-01-001'), currencyId: $crc, debit: 0, credit: '650000.00',
                description: 'IVA devengado 13%',
                taxRateId: $ivaRate('2-01-02-01-001'), taxableBase: '5000000.00'),
        ], returnEntry: true);

        $log[] = $this->describe($venta1, 'Factura de venta a Distribuidora La Central');

        // ── 4. Cobro de la factura de venta (cancela la partida de CxC) ───
        $log[] = $this->post($company, $doc('REC'), '2026-04-02', 'Cobro de la factura de venta de marzo', $createdBy, [
            new JournalLineInput(accountId: $acc('1-01-01-02-001'), currencyId: $crc, debit: '5650000.00', credit: 0,
                description: 'Transferencia recibida'),
            new JournalLineInput(accountId: $acc('1-01-02-01-001'), currencyId: $crc, debit: 0, credit: '5650000.00',
                description: 'Cancelación total de la factura',
                businessPartnerId: $bp('CLI-001'),
                applyToOpenItemId: $this->openItemOf($venta1, $bp('CLI-001'))),
        ]);

        // ── 5. Planilla de abril (gasto repartido con la norma GRAL) ──────
        $log[] = $this->post($company, $doc('ADD'), '2026-04-30', 'Planilla de abril', $createdBy, [
            new JournalLineInput(accountId: $acc('6-01-01-01-001'), currencyId: $crc, debit: '4000000.00', credit: 0,
                description: 'Salarios de abril', costAllocationRuleId: $rule('GRAL')),
            new JournalLineInput(accountId: $acc('6-01-01-01-004'), currencyId: $crc, debit: '1060000.00', credit: 0,
                description: 'Cargas sociales patronales', costAllocationRuleId: $rule('GRAL')),
            new JournalLineInput(accountId: $acc('2-01-01-02-002'), currencyId: $crc, debit: 0, credit: '3650000.00',
                description: 'Salarios netos por pagar'),
            new JournalLineInput(accountId: $acc('2-01-03-02-001'), currencyId: $crc, debit: 0, credit: '1410000.00',
                description: 'CCSS obrera y patronal por pagar'),
        ]);

        // ── 6. Gastos operativos de mayo a crédito ────────────────────────
        $log[] = $this->post($company, $doc('FCP'), '2026-05-15', 'Gastos operativos de mayo', $createdBy, [
            new JournalLineInput(accountId: $acc('6-01-02-01-001'), currencyId: $crc, debit: '800000.00', credit: 0,
                description: 'Alquiler de oficinas', costAllocationRuleId: $rule('ADM100')),
            new JournalLineInput(accountId: $acc('6-01-02-01-002'), currencyId: $crc, debit: '250000.00', credit: 0,
                description: 'Agua, luz y teléfono', costAllocationRuleId: $rule('ADM100')),
            new JournalLineInput(accountId: $acc('1-01-04-01-001'), currencyId: $crc, debit: '136500.00', credit: 0,
                description: 'IVA soportado 13%',
                taxRateId: $ivaRate('1-01-04-01-001'), taxableBase: '1050000.00'),
            new JournalLineInput(accountId: $acc('2-01-01-01-001'), currencyId: $crc, debit: 0, credit: '1186500.00',
                description: 'Transportes Rápidos Mora',
                businessPartnerId: $bp('PRV-002'), dueDate: '2026-06-14', opensItem: true),
        ]);

        // ── 7. Segunda factura de venta ───────────────────────────────────
        $log[] = $this->post($company, $doc('FVE'), '2026-06-18', 'Factura de venta a Comercial El Roble', $createdBy, [
            new JournalLineInput(accountId: $acc('1-01-02-01-001'), currencyId: $crc, debit: '9040000.00', credit: 0,
                description: 'Comercial El Roble S.A.',
                businessPartnerId: $bp('CLI-002'), dueDate: '2026-07-18', opensItem: true),
            new JournalLineInput(accountId: $acc('4-01-01-01-001'), currencyId: $crc, debit: 0, credit: '8000000.00',
                description: 'Venta de mercadería gravada 13%', costAllocationRuleId: $rule('VEN100')),
            new JournalLineInput(accountId: $acc('2-01-02-01-001'), currencyId: $crc, debit: 0, credit: '1040000.00',
                description: 'IVA devengado 13%',
                taxRateId: $ivaRate('2-01-02-01-001'), taxableBase: '8000000.00'),
        ]);

        // ── 8. Depreciación del primer semestre ──────────────────────────
        $log[] = $this->post($company, $doc('ADD'), '2026-06-30', 'Depreciación del primer semestre', $createdBy, [
            new JournalLineInput(accountId: $acc('6-01-03-01-003'), currencyId: $crc, debit: '300000.00', credit: 0,
                description: 'Depreciación de equipo de cómputo', costAllocationRuleId: $rule('ADM100')),
            new JournalLineInput(accountId: $acc('1-02-02-01-003'), currencyId: $crc, debit: 0, credit: '300000.00',
                description: 'Depreciación acumulada de equipo de cómputo'),
        ]);

        // ── 9. Venta de exportación EN DÓLARES ───────────────────────────
        // Ambas líneas se digitan en la moneda extranjera: PostJournalService
        // deriva el equivalente en colones con la tasa vigente al 2026-08-01.
        // Deja saldo en USD en una cuenta de CxC, que es justo lo que después
        // se revalúa con la pantalla de diferencial cambiario.
        $log[] = $this->post($company, $doc('FVE'), '2026-08-12', 'Factura de exportación a Northbridge Trading', $createdBy, [
            new JournalLineInput(accountId: $acc('1-01-02-01-002'), currencyId: $usd, debit: '12000.00', credit: 0,
                description: 'Northbridge Trading LLC',
                businessPartnerId: $bp('CLI-005'), dueDate: '2026-09-26', opensItem: true),
            new JournalLineInput(accountId: $acc('4-01-01-02-002'), currencyId: $usd, debit: 0, credit: '12000.00',
                description: 'Venta de exportación (exenta)', costAllocationRuleId: $rule('VEN100')),
        ]);

        // ── 10. Honorarios profesionales a crédito ────────────────────────
        $log[] = $this->post($company, $doc('FCP'), '2026-09-05', 'Honorarios profesionales de setiembre', $createdBy, [
            new JournalLineInput(accountId: $acc('6-01-02-02-001'), currencyId: $crc, debit: '1450000.00', credit: 0,
                description: 'Asesoría contable y fiscal', costAllocationRuleId: $rule('ADM100')),
            new JournalLineInput(accountId: $acc('1-01-04-01-001'), currencyId: $crc, debit: '188500.00', credit: 0,
                description: 'IVA soportado 13%',
                taxRateId: $ivaRate('1-01-04-01-001'), taxableBase: '1450000.00'),
            new JournalLineInput(accountId: $acc('2-01-01-01-001'), currencyId: $crc, debit: 0, credit: '1638500.00',
                description: 'Consultores Asociados Quirós y Cía.',
                businessPartnerId: $bp('PRV-003'), dueDate: '2026-10-05', opensItem: true),
        ]);

        // ── 11. Pago total al proveedor del equipo de cómputo ─────────────
        $log[] = $this->post($company, $doc('PAG'), '2026-09-12', 'Pago de la compra de equipo de cómputo', $createdBy, [
            new JournalLineInput(accountId: $acc('2-01-01-01-001'), currencyId: $crc, debit: '3390000.00', credit: 0,
                description: 'Cancelación total al proveedor',
                businessPartnerId: $bp('PRV-001'),
                applyToOpenItemId: $this->openItemOf($compraEquipo, $bp('PRV-001'))),
            new JournalLineInput(accountId: $acc('1-01-01-02-001'), currencyId: $crc, debit: 0, credit: '3390000.00',
                description: 'Transferencia enviada'),
        ]);

        return $log;
    }

    /**
     * @param  array<int, JournalLineInput>  $lines
     * @return ($returnEntry is true ? JournalEntry : string)
     */
    private function post(
        Company $company,
        DocumentType $documentType,
        string $date,
        string $description,
        ?int $createdBy,
        array $lines,
        bool $returnEntry = false,
    ): JournalEntry|string {
        $on = new DateTime($date);

        $entry = $this->poster->post(
            $company,
            $documentType,
            $on,
            $on,
            $lines,
            self::MARKER.' '.$description,
            $createdBy,
        );

        return $returnEntry ? $entry : $this->describe($entry, $description);
    }

    private function describe(JournalEntry $entry, string $description): string
    {
        return sprintf(
            '%s  %s-%s  %s',
            $entry->document_date?->format('Y-m-d') ?? '',
            $entry->documentType?->code ?? '',
            $entry->document_number,
            $description
        );
    }

    /**
     * La partida abierta que dejó un asiento, para poder cancelarla después
     * desde un recibo o un pago (bp_line_requirement 'application').
     */
    private function openItemOf(JournalEntry $entry, int $businessPartnerId): int
    {
        $detailIds = $entry->details()->pluck('id');

        $item = BpOpenItem::whereIn('origin_journal_detail_id', $detailIds)
            ->where('business_partner_id', $businessPartnerId)
            ->first();

        if (! $item) {
            throw new RuntimeException(
                "El asiento #{$entry->id} no dejó ninguna partida abierta para el socio id {$businessPartnerId}."
            );
        }

        return $item->id;
    }
}
