<?php

namespace Database\Seeders;

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Inventory\DataTransferObjects\StockLineInput;
use App\Domains\Inventory\Models\InventoryDocument;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Inventory\Services\PostStockMovementService;
use App\Domains\Inventory\Services\PostSupplierInvoiceService;
use App\Models\User;
use DateTime;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * La compra de mercadería y el costo de la venta, movidos POR EL MÓDULO DE
 * INVENTARIO en vez de asentados a mano contra la cuenta de inventario.
 *
 * Por qué importa: un asiento manual contra la cuenta de inventario mueve el
 * saldo CONTABLE pero no el kardex, así que la valoración de existencias y
 * la contabilidad dejan de coincidir. Haciéndolo por el módulo, cada
 * movimiento escribe las dos cosas en la misma transacción y no hay forma de
 * que se separen.
 *
 * El ciclo de compra queda en sus dos tiempos reales:
 *   1. Entrada por compra (ECP): D Inventario / C Transitoria GR/IR. La
 *      mercancía ya está, la factura todavía no.
 *   2. Factura del proveedor (FCP): D GR/IR + D IVA / C Proveedores, y abre
 *      la partida en CxP. Liquida la cuenta puente.
 * Y la venta descarga existencias con la salida por venta (SIN), que lleva
 * el costo a resultados por la categoría 'cogs' de la matriz — al costo
 * PROMEDIO del artículo, no a uno digitado.
 *
 * Corre DESPUÉS de TestInventoryStockSeeder: la salida por venta necesita
 * existencias que descargar.
 *
 * Si encuentra los dos asientos manuales de una corrida vieja de
 * TestJournalEntriesSeeder (que ya no los crea), los ANULA primero con
 * PostJournalService::reverse() — nunca los borra: un asiento contabilizado
 * no se borra, se reversa. En una base nueva no hay nada que anular y ese
 * paso simplemente no hace nada.
 *
 * Todo el bloque va en UNA transacción; con SEED_DRY_RUN=1 se revierte.
 *
 * Uso:
 *   SEED_COMPANY_ID=604 php artisan db:seed --class=TestInventoryMovementsSeeder
 */
class TestInventoryMovementsSeeder extends Seeder
{
    private const MARKER = '[PRUEBA]';

    /** Descripciones de los asientos manuales que este seeder viene a reemplazar. */
    private const LEGACY = [
        '[PRUEBA] Compra de mercadería',
        '[PRUEBA] Costo de la mercadería vendida en marzo',
    ];

    /** Compañía destino. Si queda en null se lee de SEED_COMPANY_ID. */
    public ?int $companyId = null;

    /** true: contabiliza todo y revierte al final, sin dejar nada. */
    public bool $dryRun = false;

    /**
     * Compra a los mismos costos unitarios que las existencias iniciales: un
     * costo distinto movería el promedio del artículo y la salida por venta
     * de abajo ya no daría los ₡3.200.000 que espera el margen del ejemplo.
     */
    private const COMPRA = [
        ['ART-0001', 10, '450000'],   // 4.500.000
        ['ART-0005', 25, '140000'],   // 3.500.000
    ];

    /** Lo que sale por la venta de marzo, al costo promedio de cada artículo. */
    private const VENTA = [
        ['ART-0001', 6],   // 6 x 450.000 = 2.700.000
        ['ART-0002', 4],   // 4 x  95.000 =   380.000
        ['ART-0003', 10],  // 10 x 12.000 =   120.000
    ];

    public function __construct(
        private readonly PostStockMovementService $stock,
        private readonly PostSupplierInvoiceService $supplierInvoice,
        private readonly PostJournalService $journal,
    ) {}

    public function run(): void
    {
        $companyId = $this->companyId ?? (int) env('SEED_COMPANY_ID');

        if ($companyId <= 0) {
            throw new RuntimeException(
                'Indicá la compañía destino: SEED_COMPANY_ID=<id> php artisan db:seed --class=TestInventoryMovementsSeeder'
            );
        }

        $this->dryRun = $this->dryRun || (bool) env('SEED_DRY_RUN');

        $company = Company::findOrFail($companyId);
        app(CurrentCompany::class)->set($company->id);

        $already = InventoryDocument::whereIn('operation', ['purchase_receipt', 'sales_issue'])
            ->where('description', 'like', self::MARKER.'%')
            ->count();

        if ($already > 0 && ! $this->dryRun) {
            $this->command?->warn("  Ya hay {$already} movimientos de compra/venta de prueba; no se crean de nuevo.");

            return;
        }

        $createdBy = $company->license?->superuser_id
            ?? User::whereHas('companies', fn ($q) => $q->where('companies.id', $company->id))->value('id');

        DB::beginTransaction();

        try {
            $log = [];

            foreach ($this->reverseLegacy($company, $createdBy) as $line) {
                $log[] = $line;
            }

            $receipt = $this->purchaseReceipt($company, $createdBy);
            $log[] = $receipt['log'];
            $log[] = $this->postSupplierInvoice($company, $createdBy, $receipt['document']);
            $log[] = $this->salesIssue($company, $createdBy);

            if ($this->dryRun) {
                DB::rollBack();
                $this->command?->info('  SIMULACIÓN: todo contabilizó bien. Nada quedó escrito.');

                return;
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            throw new RuntimeException(
                'Nada quedó escrito. Falló: '.get_class($e).' — '.$e->getMessage(),
                previous: $e
            );
        }

        foreach ($log as $line) {
            $this->command?->line("  {$line}");
        }

        $this->command?->info('  Compra y venta rehechas por el módulo de inventario.');
    }

    /**
     * Anula los asientos manuales en su MISMA fecha, para que el original y
     * su reversión se netee dentro del período en el que nació y no ensucie
     * los reportes de un mes al que nunca perteneció.
     *
     * @return array<int, string>
     */
    private function reverseLegacy(Company $company, ?int $createdBy): array
    {
        $log = [];

        foreach (self::LEGACY as $description) {
            $entry = JournalEntry::where('description', $description)->first();

            if (! $entry) {
                continue;
            }

            if ($entry->status !== 'posted') {
                $log[] = "Asiento «{$description}» ya estaba anulado; se deja como está.";

                continue;
            }

            $reversal = $this->journal->reverse(
                $company,
                $entry,
                $entry->posting_date,
                self::MARKER.' Anulación — se rehace por el módulo de inventario',
                $createdBy,
            );

            $log[] = sprintf('ANULADO  asiento #%d  «%s»  (reversión #%d)', $entry->id, $description, $reversal->id);
        }

        return $log;
    }

    /**
     * @return array{document: InventoryDocument, log: string}
     */
    private function purchaseReceipt(Company $company, ?int $createdBy): array
    {
        $warehouse = Warehouse::where('code', 'ALM01')->firstOrFail();
        $lines = [];
        $total = '0.00';

        foreach (self::COMPRA as [$itemCode, $quantity, $unitCost]) {
            $item = $this->item($company, $itemCode);

            $lines[] = new StockLineInput(
                itemId: $item->id,
                warehouseId: $warehouse->id,
                quantity: $quantity,
                unitCostLocal: $unitCost,
                description: "Compra de {$item->code}",
            );

            $total = bcadd($total, bcmul((string) $quantity, $unitCost, 2), 2);
        }

        $on = new DateTime('2026-02-10');

        $document = $this->stock->post(
            $company,
            $this->documentType($company, 'ECP'),
            'purchase_receipt',
            $on,
            $on,
            $lines,
            self::MARKER.' Entrada por compra de mercadería',
            $createdBy,
            // Una entrada por compra EXIGE proveedor: la deuda va a la cuenta
            // puente GR/IR y sin saber a quién se le debe no se puede liquidar.
            businessPartnerId: BusinessPartner::where('code', 'PRV-001')->firstOrFail()->id,
        );

        return [
            'document' => $document,
            'log' => sprintf('ECP  %d líneas  ₡%s  entrada por compra (D Inventario / C GR/IR)',
                count($lines), number_format((float) $total, 2)),
        ];
    }

    private function postSupplierInvoice(Company $company, ?int $createdBy, InventoryDocument $receipt): string
    {
        // La tarifa se DERIVA de la cuenta de IVA elegida (ver
        // PostSupplierInvoiceService): la cuenta debe traer su tax_rate_id,
        // y el monto tiene que cuadrar con base x tarifa o PostJournalService
        // lo rechaza.
        $ivaAccount = ChartOfAccount::where('code', '1-01-04-01-001')->firstOrFail();

        $entry = $this->supplierInvoice->post(
            $company,
            $this->documentType($company, 'FCP'),
            $receipt,
            new DateTime('2026-02-10'),
            new DateTime('2026-02-10'),
            taxAccountId: $ivaAccount->id,
            taxAmount: '1040000.00',
            dueDate: new DateTime('2026-03-12'),
            description: self::MARKER.' Factura de compra de mercadería',
            createdBy: $createdBy,
        );

        return sprintf('FCP-%s  ₡9.040.000,00  factura del proveedor (liquida GR/IR, abre partida en CxP)',
            $entry->document_number);
    }

    private function salesIssue(Company $company, ?int $createdBy): string
    {
        $warehouse = Warehouse::where('code', 'ALM01')->firstOrFail();
        $lines = [];

        foreach (self::VENTA as [$itemCode, $quantity]) {
            $item = $this->item($company, $itemCode);

            // Sin unitCostLocal a propósito: en una salida el costo lo pone
            // el promedio del artículo, no quien digita (ver StockLineInput).
            $lines[] = new StockLineInput(
                itemId: $item->id,
                warehouseId: $warehouse->id,
                quantity: $quantity,
                description: "Costo de venta de {$item->code}",
            );
        }

        $on = new DateTime('2026-03-05');

        $document = $this->stock->post(
            $company,
            $this->documentType($company, 'SIN'),
            'sales_issue',
            $on,
            $on,
            $lines,
            self::MARKER.' Salida por venta de marzo',
            $createdBy,
        );

        $cost = $document->journalEntry
            ?->details()
            ->whereHas('account', fn ($q) => $q->where('account_type', 'cost_of_sales'))
            ->sum('debit_local');

        return sprintf('SIN  %d líneas  ₡%s  salida por venta (D Costo de ventas / C Inventario)',
            count($lines), number_format((float) $cost, 2));
    }

    private function item(Company $company, string $code): Item
    {
        $item = Item::where('code', $code)->first();

        if (! $item) {
            throw new RuntimeException(
                "Falta el artículo {$code}. Corré antes: SEED_COMPANY_ID={$company->id} ".
                'php artisan db:seed --class=TestInventorySeeder'
            );
        }

        return $item;
    }

    private function documentType(Company $company, string $code): DocumentType
    {
        $type = DocumentType::where('code', $code)->first();

        if (! $type) {
            throw new RuntimeException(
                "Falta el tipo de documento {$code}. Corré antes: SEED_COMPANY_ID={$company->id} ".
                'php artisan db:seed --class=TestCompanySetupSeeder'
            );
        }

        return $type;
    }
}
