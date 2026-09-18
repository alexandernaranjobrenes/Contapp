<?php

namespace Database\Seeders;

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\CostAllocationRule;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Inventory\Models\GlDetermination;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\ItemGroup;
use App\Domains\Inventory\Models\UnitOfMeasure;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Inventory\Models\WarehouseBin;
use App\Domains\Tax\Models\TaxRate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Datos de PRUEBA del módulo de inventario: unidades de medida, grupos,
 * almacenes (uno con ubicaciones), artículos con su código, y la MATRIZ DE
 * DETERMINACIÓN DE CUENTAS, que es lo que decide contra qué cuenta contable
 * se asienta cada movimiento de existencias.
 *
 * Corre DESPUÉS de TestChartOfAccountsSeeder (la matriz resuelve las cuentas
 * por código) y de TestCompanySetupSeeder (la categoría 'cogs' apunta a una
 * cuenta que exige norma de reparto).
 *
 * La matriz se siembra COMPLETA a nivel de compañía —las 8 categorías que
 * GlDetermination::CATEGORIES declara hoy— y además con tres reglas más
 * específicas, para que se vea funcionando la precedencia que aplica
 * GlDeterminationResolver: artículo > grupo > almacén > compañía.
 *
 * Idempotente: se puede correr las veces que haga falta.
 *
 * Uso:
 *   SEED_COMPANY_ID=604 php artisan db:seed --class=TestInventorySeeder
 */
class TestInventorySeeder extends Seeder
{
    /** Compañía destino. Si queda en null se lee de SEED_COMPANY_ID. */
    public ?int $companyId = null;

    public function run(): void
    {
        $companyId = $this->companyId ?? (int) env('SEED_COMPANY_ID');

        if ($companyId <= 0) {
            throw new RuntimeException(
                'Indicá la compañía destino: SEED_COMPANY_ID=<id> php artisan db:seed --class=TestInventorySeeder'
            );
        }

        $company = Company::findOrFail($companyId);
        app(CurrentCompany::class)->set($company->id);

        DB::transaction(function () use ($company): void {
            $this->seedUnits($company);
            $this->seedGroups($company);
            $this->seedWarehouses($company);
            $this->seedItems($company);
            $this->seedGlDeterminations($company);
        });

        $this->command?->info("Inventario de prueba cargado en «{$company->legal_name}» (id {$company->id}).");
    }

    private function seedUnits(Company $company): void
    {
        // decimals: cuántos decimales admite la cantidad. Una unidad que se
        // vende entera (caja, servicio) no debería aceptar fracciones.
        $units = [
            ['UND', 'Unidad', 0],
            ['CAJ', 'Caja', 0],
            ['KG', 'Kilogramo', 3],
            ['LT', 'Litro', 3],
            ['MT', 'Metro', 2],
            ['HRA', 'Hora', 2],
            ['SRV', 'Servicio', 0],
        ];

        foreach ($units as [$code, $name, $decimals]) {
            UnitOfMeasure::updateOrCreate(
                ['company_id' => $company->id, 'code' => $code],
                ['name' => $name, 'decimals' => $decimals, 'status' => 'active']
            );
        }

        $this->command?->line('  Unidades de medida: '.count($units).'.');
    }

    private function seedGroups(Company $company): void
    {
        $groups = [
            ['MERC', 'Mercadería para reventa'],
            ['MATP', 'Materia prima'],
            ['PTER', 'Producto terminado'],
            ['SUMI', 'Suministros y consumibles'],
            ['SERV', 'Servicios'],
        ];

        foreach ($groups as [$code, $name]) {
            ItemGroup::updateOrCreate(
                ['company_id' => $company->id, 'code' => $code],
                ['name' => $name, 'status' => 'active']
            );
        }

        $this->command?->line('  Grupos de artículos: '.count($groups).'.');
    }

    private function seedWarehouses(Company $company): void
    {
        // Un solo almacén predeterminado: si hubiera dos, el resolvedor de
        // almacén no tendría un criterio único (ver WarehouseController,
        // que apaga el is_default anterior al marcar uno nuevo).
        $warehouses = [
            ['ALM01', 'Almacén principal', 'San José, oficinas centrales', true, false],
            ['ALM02', 'Bodega de tránsito', 'Zona franca, Alajuela', false, false],
            ['ALM03', 'Almacén con ubicaciones', 'Cartago, centro de distribución', false, true],
        ];

        foreach ($warehouses as [$code, $name, $address, $isDefault, $usesBins]) {
            Warehouse::updateOrCreate(
                ['company_id' => $company->id, 'code' => $code],
                [
                    'name' => $name,
                    'address' => $address,
                    'is_default' => $isDefault,
                    'uses_bins' => $usesBins,
                    'status' => 'active',
                ]
            );
        }

        // Ubicaciones solo del almacén que las usa: crearlas en uno con
        // uses_bins = false dejaría datos que ninguna pantalla muestra.
        $binned = Warehouse::where('code', 'ALM03')->firstOrFail();

        $bins = [
            ['A-01-01', 'Pasillo A, estante 1, nivel 1'],
            ['A-01-02', 'Pasillo A, estante 1, nivel 2'],
            ['A-02-01', 'Pasillo A, estante 2, nivel 1'],
            ['B-01-01', 'Pasillo B, estante 1, nivel 1'],
            ['REC-01', 'Área de recepción'],
        ];

        foreach ($bins as [$code, $name]) {
            WarehouseBin::updateOrCreate(
                ['warehouse_id' => $binned->id, 'code' => $code],
                ['name' => $name, 'status' => 'active']
            );
        }

        $this->command?->line('  Almacenes: 3 (ALM03 con '.count($bins).' ubicaciones).');
    }

    private function seedItems(Company $company): void
    {
        $groups = ItemGroup::pluck('id', 'code');
        $units = UnitOfMeasure::pluck('id', 'code');

        $rates = TaxRate::withoutGlobalScopes()
            ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $company->id))
            ->pluck('id', 'code');

        // [código, nombre, grupo, unidad, tarifa, inventariable, vendible,
        //  comprable, CAByS, unidad fiscal, código de tarifa de IVA, estado]
        //
        // Los tres campos fiscales (CAByS, unidad y tarifa de Hacienda) los
        // LEE la pantalla de facturación electrónica para precargar la línea
        // (SalesDocumentController, que exige un CAByS de 13 dígitos por
        // línea), pero todavía ninguna pantalla los escribe. Se cargan acá
        // fuera de fillable, asignando atributo por atributo, justamente
        // porque no son asignación masiva desde un formulario.
        $items = [
            ['ART-0001', 'Laptop 14" 16GB RAM', 'MERC', 'UND', 'IVA-13', true, true, true, '8471300000000', 'Unid', '08', 'active'],
            ['ART-0002', 'Monitor 24" Full HD', 'MERC', 'UND', 'IVA-13', true, true, true, '8528520000000', 'Unid', '08', 'active'],
            ['ART-0003', 'Teclado inalámbrico', 'MERC', 'UND', 'IVA-13', true, true, true, '8471600000000', 'Unid', '08', 'active'],
            ['ART-0004', 'Mouse óptico USB', 'MERC', 'UND', 'IVA-13', true, true, true, '8471600000001', 'Unid', '08', 'active'],
            ['ART-0005', 'Impresora multifuncional', 'MERC', 'UND', 'IVA-13', true, true, true, '8443310000000', 'Unid', '08', 'active'],
            ['ART-0006', 'Cable HDMI 2 m', 'MERC', 'UND', 'IVA-13', true, true, true, '8544420000000', 'Unid', '08', 'active'],
            ['ART-0007', 'Disco duro externo 1 TB', 'MERC', 'UND', 'IVA-13', true, true, true, '8471700000000', 'Unid', '08', 'active'],
            ['ART-0008', 'Silla ergonómica de oficina', 'MERC', 'UND', 'IVA-13', true, true, true, '9401300000000', 'Unid', '08', 'active'],

            ['MAT-0001', 'Lámina de acero 1,2 mm', 'MATP', 'KG', 'IVA-13', true, false, true, '7209160000000', 'kg', '08', 'active'],
            ['MAT-0002', 'Pintura electrostática negra', 'MATP', 'KG', 'IVA-13', true, false, true, '3208100000000', 'kg', '08', 'active'],
            ['MAT-0003', 'Tornillería surtida', 'MATP', 'CAJ', 'IVA-13', true, false, true, '7318150000000', 'Unid', '08', 'active'],

            ['PTE-0001', 'Gabinete metálico ensamblado', 'PTER', 'UND', 'IVA-13', true, true, false, '9403200000000', 'Unid', '08', 'active'],
            ['PTE-0002', 'Rack de servidores 42U', 'PTER', 'UND', 'IVA-13', true, true, false, '9403200000001', 'Unid', '08', 'active'],

            ['SUM-0001', 'Resma de papel bond carta', 'SUMI', 'CAJ', 'IVA-13', true, false, true, '4802560000000', 'Unid', '08', 'active'],
            ['SUM-0002', 'Tóner negro compatible', 'SUMI', 'UND', 'IVA-13', true, false, true, '3215900000000', 'Unid', '08', 'active'],
            // Canasta básica: tarifa reducida del 1%, para que haya al menos
            // un artículo que no vaya al 13% general.
            ['SUM-0003', 'Café molido 1 kg (cafetería)', 'SUMI', 'KG', 'IVA-1', true, false, true, '0901210000000', 'kg', '02', 'active'],

            // Servicios: NO inventariables — no generan movimiento de
            // existencias ni tocan la matriz de determinación.
            ['SRV-0001', 'Hora de soporte técnico', 'SERV', 'HRA', 'IVA-13', false, true, false, '8020000000000', 'Sp', '08', 'active'],
            ['SRV-0002', 'Instalación y configuración', 'SERV', 'SRV', 'IVA-13', false, true, false, '8020000000001', 'Sp', '08', 'active'],
            ['SRV-0003', 'Capacitación exenta a entidad pública', 'SERV', 'HRA', 'IVA-0', false, true, false, '9200000000000', 'Sp', '10', 'active'],

            // Descontinuado a propósito: para probar que un artículo inactivo
            // no se ofrezca al digitar un documento.
            ['ART-0099', 'Adaptador VGA (descontinuado)', 'MERC', 'UND', 'IVA-13', true, false, false, '8471800000000', 'Unid', '08', 'inactive'],
        ];

        foreach ($items as $i) {
            [$code, $name, $group, $unit, $rate, $isInv, $isSale, $isPurch, $cabys, $fiscalUnit, $ivaCode, $status] = $i;

            if (! isset($rates[$rate])) {
                throw new RuntimeException("El artículo {$code} referencia la tarifa {$rate}, que no existe en esta base.");
            }

            $item = Item::updateOrCreate(
                ['company_id' => $company->id, 'code' => $code],
                [
                    'name' => $name,
                    'item_group_id' => $groups[$group],
                    'uom_id' => $units[$unit],
                    'tax_rate_id' => $rates[$rate],
                    'is_inventory_item' => $isInv,
                    'is_sales_item' => $isSale,
                    'is_purchase_item' => $isPurch,
                    'status' => $status,
                ]
            );

            $item->cabys_code = $cabys;
            $item->fiscal_unit_code = $fiscalUnit;
            $item->iva_rate_code = $ivaCode;
            $item->save();
        }

        $this->command?->line('  Artículos: '.count($items).'.');
    }

    /**
     * La matriz que usa GlDeterminationResolver. Sin ella, ningún movimiento
     * de existencias sabe contra qué cuenta asentar y el módulo queda
     * inutilizable aunque los artículos existan.
     */
    private function seedGlDeterminations(Company $company): void
    {
        $acc = function (string $code) use ($company): int {
            $account = ChartOfAccount::where('code', $code)->first();

            if (! $account) {
                throw new RuntimeException(
                    "Falta la cuenta {$code}. Corré antes: SEED_COMPANY_ID={$company->id} ".
                    'php artisan db:seed --class=TestChartOfAccountsSeeder'
                );
            }

            return $account->id;
        };

        // 'cogs' apunta a una cuenta con requires_cost_center: la regla debe
        // traer la norma de reparto, o el asiento que la use sería rechazado
        // por PostJournalService con MissingCostAllocationRuleException.
        $ventas = CostAllocationRule::where('code', 'VEN100')->firstOrFail()->id;

        // Nivel compañía: el respaldo general, el que aplica cuando ninguna
        // regla más específica casa.
        $base = [
            'inventory' => [$acc('1-01-03-01-001'), null],
            'gr_ir_clearing' => [$acc('2-01-01-02-004'), null],
            'stock_increase' => [$acc('5-01-03-01-001'), null],
            'stock_decrease' => [$acc('5-01-03-01-002'), null],
            'price_difference' => [$acc('5-01-03-01-003'), null],
            'wip' => [$acc('1-01-03-01-003'), null],
            'production_variance' => [$acc('5-01-03-01-004'), null],
            'cogs' => [$acc('5-01-01-01-001'), $ventas],
        ];

        foreach ($base as $category => [$accountId, $ruleId]) {
            GlDetermination::updateOrCreate(
                ['company_id' => $company->id, 'scope_level' => 'company', 'scope_id' => null, 'category' => $category],
                ['account_id' => $accountId, 'cost_allocation_rule_id' => $ruleId]
            );
        }

        // Reglas más específicas, una por nivel, para tener la precedencia
        // completa a la vista (artículo > grupo > almacén > compañía).
        $specific = [
            // Los suministros no se valoran contra el inventario de
            // mercadería, sino contra su propia cuenta de materiales.
            ['item_group', ItemGroup::where('code', 'SUMI')->firstOrFail()->id, 'inventory', $acc('1-01-03-02-001'), null],
            // Lo que está en la bodega de tránsito todavía no es inventario
            // disponible: va contra mercadería en tránsito.
            ['warehouse', Warehouse::where('code', 'ALM02')->firstOrFail()->id, 'inventory', $acc('1-01-03-01-002'), null],
            // Un artículo que se factura como servicio lleva su costo a la
            // cuenta de servicios prestados, no a la de mercadería.
            ['item', Item::where('code', 'PTE-0001')->firstOrFail()->id, 'cogs', $acc('5-01-01-01-002'), $ventas],
        ];

        foreach ($specific as [$level, $scopeId, $category, $accountId, $ruleId]) {
            GlDetermination::updateOrCreate(
                ['company_id' => $company->id, 'scope_level' => $level, 'scope_id' => $scopeId, 'category' => $category],
                ['account_id' => $accountId, 'cost_allocation_rule_id' => $ruleId]
            );
        }

        $this->command?->line('  Determinación de cuentas: '.count($base).' reglas de compañía + '.count($specific).' específicas.');
    }
}
