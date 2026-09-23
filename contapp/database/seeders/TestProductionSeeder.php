<?php

namespace Database\Seeders;

use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Scopes\CompanyScope;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Inventory\DataTransferObjects\StockLineInput;
use App\Domains\Inventory\Models\BillOfMaterial;
use App\Domains\Inventory\Models\BillOfMaterialLine;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\ProductionOrder;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Inventory\Services\PostProductionService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Recetas y órdenes de fabricación de ejemplo, para recorrer el ciclo en
 * pantalla en vez de leerlo.
 *
 * Deja TRES órdenes que muestran los tres desenlaces posibles:
 *
 *   1. Una ABIERTA y sin emitir — para que el usuario haga él mismo la
 *      emisión y vea las líneas precargadas y escaladas desde la receta.
 *   2. Una COMPLETA — emitida y recibida, con el WIP en cero y el costo
 *      unitario del producto calculado a partir de lo realmente consumido.
 *   3. Una FALLIDA — se emitió materia prima, no salió producto, y al
 *      cerrarla todo el costo acumulado se fue a desviación de fabricación.
 *
 * Como los seeders anteriores: parametrizado por SEED_COMPANY_ID e
 * idempotente — si las recetas ya existen no hace nada, para poder correrlo
 * dos veces sin duplicar documentos contables.
 *
 *   docker compose exec -T app php artisan db:seed \
 *       --class=Database\\Seeders\\TestProductionSeeder
 */
class TestProductionSeeder extends Seeder
{
    public function run(): void
    {
        $companyId = (int) (env('SEED_COMPANY_ID') ?: 604);

        $company = Company::withoutGlobalScope(CompanyScope::class)->find($companyId);

        if ($company === null) {
            $this->command?->error("No existe la compañía {$companyId}.");

            return;
        }

        // Los services leen CurrentCompany para resolver scopes; sin esto
        // fallan cerrado (docs/decisiones.md 2026-08-05).
        app(CurrentCompany::class)->set($company->id);

        if (BillOfMaterial::withoutGlobalScope(CompanyScope::class)->where('company_id', $company->id)->exists()) {
            $this->command?->warn('La compañía ya tiene recetas; no se hace nada para no duplicar documentos.');

            return;
        }

        $items = Item::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->get()
            ->keyBy('code');

        $required = ['PTE-0001', 'PTE-0002', 'MAT-0001', 'MAT-0002', 'MAT-0003'];

        foreach ($required as $code) {
            if (! $items->has($code)) {
                $this->command?->error("Falta el artículo {$code}; corré primero TestInventorySeeder.");

                return;
            }
        }

        $warehouse = Warehouse::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)->where('code', 'ALM01')->firstOrFail();

        $salida = $this->documentType($company->id, 'SIN');
        $entrada = $this->documentType($company->id, 'EIN');

        $recipes = $this->createRecipes($company->id, $items);

        $this->command?->info('Recetas creadas: '.implode(', ', array_keys($recipes)));

        $this->orderReadyToIssue($company, $items, $warehouse, $recipes['GAB-STD']);
        $this->completedOrder($company, $items, $warehouse, $recipes['GAB-STD'], $salida, $entrada);
        $this->failedOrder($company, $items, $warehouse, $recipes['RACK-STD'], $salida, $entrada);

        $this->command?->info('Listo. Revisá Inventario → Órdenes de fabricación.');
    }

    /**
     * Tres recetas: dos del mismo producto —para que se vea para qué sirve
     * marcar una como predeterminada— y una de otro.
     *
     * @return array<string, BillOfMaterial>
     */
    private function createRecipes(int $companyId, $items): array
    {
        $recipes = [];

        // Gabinete metálico: la fórmula de siempre. Rinde 10 por tanda, y
        // los insumos se declaran POR TANDA, tal como está escrita la
        // fórmula, no divididos entre 10.
        $recipes['GAB-STD'] = $this->recipe($companyId, [
            'code' => 'GAB-STD',
            'name' => 'Gabinete metálico — fórmula estándar',
            'item_id' => $items['PTE-0001']->id,
            'output_quantity' => 10,
            'is_default' => true,
            'notes' => 'La que se usa normalmente.',
        ], [
            // 4% de merma: de cada 100 kg de lámina se pierden 4 en los
            // cortes, así que hay que emitir 104 para que queden 100.
            [$items['MAT-0001']->id, 25, 4, 'Cortes y troquelado'],
            [$items['MAT-0002']->id, 3, 0, null],
            [$items['MAT-0003']->id, 2, 0, null],
        ]);

        // La misma pieza con lámina más gruesa: existe, pero no es la
        // predeterminada, así que hay que elegirla a propósito.
        $recipes['GAB-REF'] = $this->recipe($companyId, [
            'code' => 'GAB-REF',
            'name' => 'Gabinete metálico — reforzado',
            'item_id' => $items['PTE-0001']->id,
            'output_quantity' => 10,
            'is_default' => false,
            'notes' => 'Para instalación a la intemperie: 40% más de lámina.',
        ], [
            [$items['MAT-0001']->id, 35, 4, 'Lámina reforzada'],
            [$items['MAT-0002']->id, 4, 0, null],
            [$items['MAT-0003']->id, 3, 0, null],
        ]);

        $recipes['RACK-STD'] = $this->recipe($companyId, [
            'code' => 'RACK-STD',
            'name' => 'Rack de servidores 42U — fórmula estándar',
            'item_id' => $items['PTE-0002']->id,
            'output_quantity' => 5,
            'is_default' => true,
            'notes' => null,
        ], [
            [$items['MAT-0001']->id, 60, 3, null],
            [$items['MAT-0003']->id, 4, 0, null],
        ]);

        return $recipes;
    }

    /**
     * @param  array<int, array{0: int, 1: float, 2: float, 3: ?string}>  $lines
     */
    private function recipe(int $companyId, array $attributes, array $lines): BillOfMaterial
    {
        $bom = BillOfMaterial::create([...$attributes, 'company_id' => $companyId, 'status' => 'active']);

        foreach ($lines as [$itemId, $quantity, $scrap, $notes]) {
            BillOfMaterialLine::create([
                'bill_of_material_id' => $bom->id,
                'component_item_id' => $itemId,
                'quantity' => $quantity,
                'scrap_percentage' => $scrap,
                'notes' => $notes,
            ]);
        }

        return $bom;
    }

    /**
     * ORDEN 1 — abierta y sin emitir.
     *
     * Es la que el usuario va a recorrer: al abrir "Emitir materia prima",
     * las líneas llegan precargadas y escaladas a 30 unidades (factor 3
     * sobre una receta que rinde 10) y con la merma ya aplicada.
     */
    private function orderReadyToIssue(Company $company, $items, Warehouse $warehouse, BillOfMaterial $bom): void
    {
        ProductionOrder::create([
            'company_id' => $company->id,
            'item_id' => $items['PTE-0001']->id,
            'bill_of_material_id' => $bom->id,
            'warehouse_id' => $warehouse->id,
            'planned_quantity' => 30,
            'order_date' => now()->format('Y-m-d'),
            'description' => 'EJEMPLO 1 — lista para emitir: probá el botón "Emitir materia prima"',
            'status' => 'open',
        ]);

        $this->command?->info('  #1 Gabinetes x30 — abierta, lista para emitir.');
    }

    /**
     * ORDEN 2 — el ciclo completo.
     *
     * Emite la materia prima de una tanda de 10 y recibe las 10 unidades.
     * El costo unitario del gabinete NO se digita: sale de dividir el WIP
     * acumulado entre las unidades recibidas.
     */
    private function completedOrder(
        Company $company,
        $items,
        Warehouse $warehouse,
        BillOfMaterial $bom,
        DocumentType $salida,
        DocumentType $entrada,
    ): void {
        $order = ProductionOrder::create([
            'company_id' => $company->id,
            'item_id' => $items['PTE-0001']->id,
            'bill_of_material_id' => $bom->id,
            'warehouse_id' => $warehouse->id,
            'planned_quantity' => 10,
            'order_date' => now()->subDays(10)->format('Y-m-d'),
            'description' => 'EJEMPLO 2 — ciclo completo: emitida, recibida y cerrada sin desviación',
            'status' => 'open',
        ]);

        $service = app(PostProductionService::class);

        // Las cantidades salen de la receta para una tanda de 10: factor 1,
        // con la merma del 4% aplicada a la lámina (25 × 1,04 = 26).
        $service->issue(
            $company, $salida, $order,
            now()->subDays(9), now()->subDays(9),
            [
                new StockLineInput($items['MAT-0001']->id, $warehouse->id, quantity: 26),
                new StockLineInput($items['MAT-0002']->id, $warehouse->id, quantity: 3),
                new StockLineInput($items['MAT-0003']->id, $warehouse->id, quantity: 2),
            ],
            description: 'Emisión a producción — tanda de 10 gabinetes',
        );

        $wip = $order->fresh()->wipBalance();

        $service->receive(
            $company, $entrada, $order->fresh(), 10,
            now()->subDays(8), now()->subDays(8),
            description: 'Recibo de producción — 10 gabinetes terminados',
        );

        $service->close(
            $company, $entrada, $order->fresh(), now()->subDays(8),
            description: 'Cierre de orden — sin saldo en proceso',
        );

        $this->command?->info(
            "  #2 Gabinetes x10 — completa. WIP acumulado ₡{$wip}, costo unitario ₡".
            number_format((float) $wip / 10, 2, '.', '')
        );
    }

    /**
     * ORDEN 3 — la tanda que falló.
     *
     * Se emitió la materia prima y no salió producto terminado. Al cerrar,
     * todo el costo acumulado se descarga a desviación de fabricación:
     * dejarlo en proceso sería mantener como activo un costo que ya no tiene
     * producto que lo respalde.
     */
    private function failedOrder(
        Company $company,
        $items,
        Warehouse $warehouse,
        BillOfMaterial $bom,
        DocumentType $salida,
        DocumentType $entrada,
    ): void {
        $order = ProductionOrder::create([
            'company_id' => $company->id,
            'item_id' => $items['PTE-0002']->id,
            'bill_of_material_id' => $bom->id,
            'warehouse_id' => $warehouse->id,
            'planned_quantity' => 5,
            'order_date' => now()->subDays(6)->format('Y-m-d'),
            'description' => 'EJEMPLO 3 — tanda fallida: se cerró sin producto y el costo fue a desviación',
            'status' => 'open',
        ]);

        $service = app(PostProductionService::class);

        // Receta para 5 racks: lámina 60 × 1,03 = 61,8 kg; tornillería 4.
        $service->issue(
            $company, $salida, $order,
            now()->subDays(5), now()->subDays(5),
            [
                new StockLineInput($items['MAT-0001']->id, $warehouse->id, quantity: 61.8),
                new StockLineInput($items['MAT-0003']->id, $warehouse->id, quantity: 4),
            ],
            description: 'Emisión a producción — tanda de 5 racks',
        );

        $wip = $order->fresh()->wipBalance();

        $service->close(
            $company, $entrada, $order->fresh(), now()->subDays(4),
            description: 'Cierre — el lote se desechó por falla de soldadura',
        );

        $this->command?->info("  #3 Racks x5 — fallida. ₡{$wip} a desviación de fabricación.");
    }

    private function documentType(int $companyId, string $code): DocumentType
    {
        return DocumentType::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)
            ->where('code', $code)
            ->firstOrFail();
    }
}
