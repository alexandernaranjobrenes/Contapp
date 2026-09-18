<?php

namespace Database\Seeders;

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\JournalDetail;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Inventory\DataTransferObjects\StockLineInput;
use App\Domains\Inventory\Models\GlDetermination;
use App\Domains\Inventory\Models\InventoryDocument;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Inventory\Models\WarehouseBin;
use App\Domains\Inventory\Services\PostStockMovementService;
use App\Models\User;
use DateTime;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Existencias iniciales de PRUEBA: entradas de mercancía que le dan saldo y
 * costo a los artículos de TestInventorySeeder, para que el kardex, la
 * valoración de existencias y la matriz de determinación de cuentas tengan
 * datos reales contra los cuales probar.
 *
 * Corre DESPUÉS de TestInventorySeeder (necesita los artículos y la matriz)
 * y de TestCompanySetupSeeder (necesita el tipo de documento EIN y el
 * período fiscal abierto).
 *
 * Todo pasa por PostStockMovementService::post(), que es el ÚNICO punto de
 * escritura del kardex y que a su vez llama al contabilizador: cada entrada
 * deja simultáneamente el movimiento de existencias y su asiento. No se
 * insertan filas a mano en stock_journals ni en item_warehouses.
 *
 * Se cargan tres entradas a propósito, una por almacén, porque cada una cae
 * en una rama distinta de la matriz de determinación:
 *   - ALM01 → cuenta de inventario de la compañía, salvo los suministros,
 *     que se desvían por la regla del grupo SUMI.
 *   - ALM02 → la regla de almacén los manda a mercadería en tránsito.
 *   - ALM03 → igual que ALM01, pero exigiendo ubicación en cada línea.
 *
 * Todo el bloque va en UNA transacción; con $dryRun = true (o
 * SEED_DRY_RUN=1) se revierte siempre.
 *
 * Uso:
 *   SEED_COMPANY_ID=604 php artisan db:seed --class=TestInventoryStockSeeder
 */
class TestInventoryStockSeeder extends Seeder
{
    private const MARKER = '[PRUEBA]';

    /**
     * 10 de enero: dentro del primer período fiscal abierto y después del
     * aporte de capital, para que la compañía tenga con qué haberlas comprado
     * cuando se mire la línea de tiempo.
     */
    private const OPENING_DATE = '2026-01-10';

    /** Contrapartida patrimonial correcta de una carga de apertura. */
    private const OPENING_ACCOUNT = '3-03-01-01-001';

    /** La de resultados a la que apunta 'stock_increase' el resto del tiempo. */
    private const ADJUSTMENT_ACCOUNT = '5-01-03-01-001';

    /** Compañía destino. Si queda en null se lee de SEED_COMPANY_ID. */
    public ?int $companyId = null;

    /** true: contabiliza todo y revierte al final, sin dejar nada. */
    public bool $dryRun = false;

    public function __construct(
        private readonly PostStockMovementService $stock,
        private readonly PostJournalService $journal,
    ) {}

    /**
     * Existencias del almacén principal. [código, cantidad, costo unitario].
     * Los SUM-* caen en la regla del grupo SUMI y se valoran contra
     * "Materiales y suministros", no contra inventario de mercadería.
     */
    private const ALM01 = [
        ['ART-0001', 10, '450000'],
        ['ART-0002', 15, '95000'],
        ['ART-0003', 40, '12000'],
        ['ART-0004', 50, '6500'],
        ['ART-0005', 8, '140000'],
        ['ART-0006', 60, '3500'],
        ['ART-0007', 20, '38000'],
        ['ART-0008', 12, '85000'],
        ['MAT-0001', 500, '1800'],
        ['MAT-0002', 120, '4200'],
        ['MAT-0003', 25, '9500'],
        ['PTE-0001', 6, '210000'],
        ['PTE-0002', 3, '620000'],
        ['SUM-0001', 30, '18000'],
        ['SUM-0002', 14, '22000'],
        ['SUM-0003', 20, '7800'],
    ];

    /** Mercancía en la bodega de tránsito, todavía no disponible. */
    private const ALM02 = [
        ['ART-0001', 5, '450000'],
        ['ART-0005', 3, '140000'],
    ];

    /**
     * Almacén con ubicaciones: cada línea DEBE indicar su bin, o
     * PostStockMovementService la rechaza (valida contra warehouses.uses_bins).
     * Mismo costo unitario que en ALM01 a propósito: el costo promedio es
     * global por artículo, así que meter otro costo acá lo movería.
     */
    private const ALM03 = [
        ['ART-0003', 20, '12000', 'A-01-01'],
        ['ART-0004', 25, '6500', 'A-01-02'],
        ['ART-0006', 30, '3500', 'B-01-01'],
    ];

    public function run(): void
    {
        $companyId = $this->companyId ?? (int) env('SEED_COMPANY_ID');

        if ($companyId <= 0) {
            throw new RuntimeException(
                'Indicá la compañía destino: SEED_COMPANY_ID=<id> php artisan db:seed --class=TestInventoryStockSeeder'
            );
        }

        $this->dryRun = $this->dryRun || (bool) env('SEED_DRY_RUN');

        $company = Company::findOrFail($companyId);
        app(CurrentCompany::class)->set($company->id);

        $existing = InventoryDocument::where('description', 'like', self::MARKER.'%')
            ->where('operation', 'goods_receipt')
            ->count();

        $createdBy = $company->license?->superuser_id
            ?? User::whereHas('companies', fn ($q) => $q->where('companies.id', $company->id))->value('id');

        $documentType = DocumentType::where('code', 'EIN')->first();

        if (! $documentType) {
            throw new RuntimeException(
                "Falta el tipo de documento EIN. Corré antes: SEED_COMPANY_ID={$company->id} ".
                'php artisan db:seed --class=TestCompanySetupSeeder'
            );
        }

        DB::beginTransaction();

        try {
            $log = [];

            if ($existing > 0 && ! $this->dryRun) {
                $this->command?->warn("  Ya hay {$existing} entradas de existencias de prueba; no se crean de nuevo.");
            } else {
                $docs = $this->withOpeningCounterpart(fn () => [
                    $this->receipt($company, $documentType, $createdBy, 'ALM01',
                        'Existencias iniciales — almacén principal', self::ALM01),
                    $this->receipt($company, $documentType, $createdBy, 'ALM02',
                        'Existencias iniciales — bodega de tránsito', self::ALM02),
                    $this->receipt($company, $documentType, $createdBy, 'ALM03',
                        'Existencias iniciales — almacén con ubicaciones', self::ALM03),
                ]);

                $log = [...$log, ...$docs];
            }

            // Corrige una carga vieja que quedó con la contrapartida en la
            // cuenta de ajuste. En una base nueva no encuentra nada que
            // reclasificar, porque withOpeningCounterpart() ya la mandó a la
            // cuenta correcta desde el principio.
            $reclass = $this->reclassifyLegacyCounterpart($company, $createdBy);

            if ($reclass !== null) {
                $log[] = $reclass;
            }

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

        $this->command?->info('  Existencias iniciales al día.');
    }

    /**
     * Desvía temporalmente la categoría 'stock_increase' a la cuenta
     * patrimonial de saldos iniciales, corre lo que se le pase, y la devuelve
     * a su cuenta de siempre.
     *
     * Por qué el desvío y no cambiar la matriz de una vez: 'stock_increase'
     * es la contrapartida de CUALQUIER entrada sin origen —un sobrante de
     * conteo, un ajuste— y esas sí son resultado del período. La cuenta
     * patrimonial solo corresponde mientras se carga la apertura; dejarla
     * puesta mandaría a patrimonio ajustes que son gasto.
     *
     * El restore va en finally: si una entrada falla, la transacción de
     * run() revierte todo igual, pero así la matriz tampoco queda desviada
     * dentro de la misma corrida.
     *
     * @template T
     *
     * @param  callable(): T  $posting
     * @return T
     */
    private function withOpeningCounterpart(callable $posting): mixed
    {
        $rule = GlDetermination::where('scope_level', 'company')
            ->whereNull('scope_id')
            ->where('category', 'stock_increase')
            ->first();

        if (! $rule) {
            throw new RuntimeException(
                'Falta la regla de determinación "stock_increase" a nivel de compañía. '.
                'Corré antes: php artisan db:seed --class=TestInventorySeeder'
            );
        }

        $opening = $this->openingAccount();
        $previous = $rule->account_id;

        $rule->update(['account_id' => $opening->id]);

        try {
            return $posting();
        } finally {
            $rule->update(['account_id' => $previous]);
        }
    }

    /**
     * Mueve a patrimonio la contrapartida de una carga de apertura anterior
     * que haya quedado en la cuenta de ajuste. Idempotente: si ya existe la
     * reclasificación, no la repite.
     */
    private function reclassifyLegacyCounterpart(Company $company, ?int $createdBy): ?string
    {
        $description = self::MARKER.' Reclasificación de la contrapartida de saldos iniciales';

        if (JournalEntry::where('description', $description)->exists()) {
            return null;
        }

        $opening = $this->openingAccount();

        // Los asientos que nacieron de las entradas de apertura...
        $entryIds = InventoryDocument::where('description', 'like', self::MARKER.'%')
            ->where('operation', 'goods_receipt')
            ->pluck('journal_entry_id')
            ->filter();

        if ($entryIds->isEmpty()) {
            return null;
        }

        // ...y cuánto de ellos quedó acreditado en la cuenta de ajuste. Si
        // dio cero, la carga ya usó la cuenta correcta y no hay nada que hacer.
        $adjustment = ChartOfAccount::where('code', self::ADJUSTMENT_ACCOUNT)->firstOrFail();

        $amount = (string) JournalDetail::whereIn('journal_entry_id', $entryIds)
            ->where('account_id', $adjustment->id)
            ->sum('credit_local');

        if (bccomp($amount, '0.00', 2) <= 0) {
            return null;
        }

        $amount = number_format((float) $amount, 2, '.', '');
        $documentType = DocumentType::where('code', 'ADD')->firstOrFail();
        $on = new DateTime(self::OPENING_DATE);

        $this->journal->post(
            $company,
            $documentType,
            $on,
            $on,
            [
                new JournalLineInput(accountId: $adjustment->id, currencyId: $company->local_currency_id,
                    debit: $amount, credit: 0, description: 'Saca la apertura de resultados'),
                new JournalLineInput(accountId: $opening->id, currencyId: $company->local_currency_id,
                    debit: 0, credit: $amount, description: 'Contrapartida patrimonial de la apertura'),
            ],
            $description,
            $createdBy,
        );

        return sprintf('RECLASIFICADO  ₡%s  de «%s» a «%s»',
            number_format((float) $amount, 2), $adjustment->description_es, $opening->description_es);
    }

    private function openingAccount(): ChartOfAccount
    {
        $account = ChartOfAccount::where('code', self::OPENING_ACCOUNT)->first();

        if (! $account) {
            throw new RuntimeException(
                'Falta la cuenta '.self::OPENING_ACCOUNT.'. '.
                'Corré antes: php artisan db:seed --class=TestChartOfAccountsSeeder'
            );
        }

        return $account;
    }

    /**
     * @param  array<int, array{0:string,1:int|float,2:string,3?:string}>  $rows
     */
    private function receipt(
        Company $company,
        DocumentType $documentType,
        ?int $createdBy,
        string $warehouseCode,
        string $description,
        array $rows,
    ): string {
        $warehouse = Warehouse::where('code', $warehouseCode)->firstOrFail();

        $bins = $warehouse->uses_bins
            ? WarehouseBin::where('warehouse_id', $warehouse->id)->pluck('id', 'code')
            : collect();

        $lines = [];
        $total = '0.00';

        foreach ($rows as $row) {
            [$itemCode, $quantity, $unitCost] = $row;
            $binCode = $row[3] ?? null;

            $item = Item::where('code', $itemCode)->first();

            if (! $item) {
                throw new RuntimeException(
                    "Falta el artículo {$itemCode}. Corré antes: SEED_COMPANY_ID={$company->id} ".
                    'php artisan db:seed --class=TestInventorySeeder'
                );
            }

            if ($warehouse->uses_bins && $binCode === null) {
                throw new RuntimeException("El almacén {$warehouseCode} usa ubicaciones; la línea de {$itemCode} no trae ninguna.");
            }

            $lines[] = new StockLineInput(
                itemId: $item->id,
                warehouseId: $warehouse->id,
                quantity: $quantity,
                unitCostLocal: $unitCost,
                description: "Saldo inicial de {$item->code}",
                warehouseBinId: $binCode !== null ? $bins[$binCode] : null,
            );

            $total = bcadd($total, bcmul((string) $quantity, $unitCost, 2), 2);
        }

        $on = new DateTime(self::OPENING_DATE);

        $document = $this->stock->post(
            $company,
            $documentType,
            'goods_receipt',
            $on,
            $on,
            $lines,
            self::MARKER.' '.$description,
            $createdBy,
        );

        return sprintf(
            '%s  %s  %2d líneas  ₡%s  %s',
            $warehouseCode,
            $documentType->code.'-'.($document->journalEntry?->document_number ?? '?'),
            count($lines),
            number_format((float) $total, 2),
            $description
        );
    }
}
