<?php

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Accounting\Models\FiscalPeriod;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Billing\DataTransferObjects\SalesDocumentInput;
use App\Domains\Billing\DataTransferObjects\SalesLineInput;
use App\Domains\Billing\DataTransferObjects\SalesTaxInput;
use App\Domains\Billing\Models\BillingTaxAccount;
use App\Domains\Billing\Models\CompanyEconomicActivity;
use App\Domains\Billing\Models\SalesDocument;
use App\Domains\Billing\Services\PostSalesDocumentService;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Models\Module;
use App\Domains\Core\Models\ModulePermission;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Inventory\DataTransferObjects\StockLineInput;
use App\Domains\Inventory\Models\GlDetermination;
use App\Domains\Inventory\Models\InventoryDocument;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Inventory\Services\PostStockMovementService;
use App\Domains\Licensing\Models\Propietario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Crea (o usa) una compañía, un usuario perteneciente a ella como default, y
 * autentica al TestCase actual como ese usuario. Común a los tests HTTP que
 * necesitan una sesión con SetCurrentCompany ya resuelto.
 *
 * Otorga de oficio 'read_write' en TODOS los módulos del rollout de
 * enforcement (module_permissions/EnsureModuleAccess, ver docs/decisiones.md
 * — arrancó con "reports", se va extendiendo módulo por módulo): este
 * helper representa "un usuario normal de la compañía para probar cualquier
 * otra cosa", no el propio gate de permisos (eso lo prueban tests dedicados,
 * ver ReportsModuleAccessHttpTest/TaxModuleAccessHttpTest) — sin este
 * grant amplio, CADA módulo nuevo que se conecte a enforcement rompería de
 * golpe todos los tests HTTP existentes de ese módulo. Generalizado a
 * "todos los módulos" (en vez de otorgar uno por uno a medida que se
 * conecta cada módulo) para no tener que volver a tocar este helper en
 * cada entrega futura del rollout.
 */
function logInAsCompanyUser(?Company $company = null, array $userAttributes = []): array
{
    $company ??= Company::factory()->create();

    $user = User::factory()->create(array_merge([
        'default_company_id' => $company->id,
    ], $userAttributes));

    $company->users()->attach($user->id, ['is_default' => true]);

    grantAllModuleAccess($user, $company);

    test()->actingAs($user);

    return compact('user', 'company');
}

/**
 * Ver logInAsCompanyUser(): otorga 'read_write' en cada módulo del catálogo
 * (ver Database\Seeders\ModuleSeeder) al usuario, creando los módulos con
 * firstOrCreate si todavía no existen (los tests no dependen de que el
 * seeder haya corrido).
 */
function grantAllModuleAccess(User $user, Company $company): void
{
    $modules = [
        ['code' => 'accounting', 'name' => 'Contabilidad (asientos, catálogo, cierres)'],
        ['code' => 'business_partners', 'name' => 'Socios de negocio y cartera'],
        ['code' => 'banking', 'name' => 'Bancos y conciliaciones'],
        ['code' => 'tax', 'name' => 'Impuestos (IVA)'],
        ['code' => 'reports', 'name' => 'Reportería'],
        ['code' => 'inventory', 'name' => 'Inventario (artículos, almacenes)'],
        ['code' => 'billing', 'name' => 'Facturación electrónica'],
    ];

    foreach ($modules as $moduleData) {
        $module = Module::firstOrCreate(['code' => $moduleData['code']], $moduleData);

        ModulePermission::firstOrCreate(
            [
                'company_id' => $company->id,
                'module_id' => $module->id,
                'subject_type' => 'user',
                'subject_id' => $user->id,
            ],
            ['access_level' => 'read_write']
        );
    }
}

/**
 * Crea un Propietario (CLAUDE.md secc. 11) y lo autentica en el guard
 * 'propietario' — completamente separado del guard 'web' de
 * logInAsCompanyUser(). Ambos pueden coexistir en el mismo test (dos guards,
 * dos sesiones independientes) cuando hace falta armar un fixture de
 * compañía y además actuar como Propietario en el mismo test.
 */
function loginAsPropietario(array $attributes = []): Propietario
{
    $propietario = Propietario::factory()->create($attributes);

    test()->actingAs($propietario, 'propietario');

    // actingAs() llama internamente a Auth::shouldUse('propietario'), que
    // muta auth.defaults.guard para el resto del proceso de test — un
    // efecto secundario que NUNCA ocurre en producción para una request
    // real fuera de /backoffice/* (ahí solo lo hace, transitoriamente,
    // el propio middleware Authenticate de esa request). Sin este reset,
    // cualquier resolución de guard "default" (auth sin guard explícito,
    // o actingAs($user) sin guard) en el resto del test quedaría
    // apuntando a 'propietario' en vez de 'web', dando falsos positivos.
    config(['auth.defaults.guard' => 'web']);

    return $propietario;
}

/*
|--------------------------------------------------------------------------
| Fixtures de inventario
|--------------------------------------------------------------------------
|
| Viven acá y no en un archivo de test porque los comparten cinco archivos
| (motor, factura de proveedor, costos de importación, y sus pares HTTP).
| Una función declarada
| dentro de un test solo existe si ESE archivo se cargó: al correr un archivo
| suelto, el helper desaparecía y fallaba todo el archivo. Pest.php siempre
| se carga, así que es el único lugar donde un helper compartido es confiable.
|
*/

/**
 * Compañía con período fiscal abierto, tipo de cambio, un artículo, un
 * almacén y la matriz de determinación mínima a nivel de compañía.
 *
 * Setea CurrentCompany para que las ASERCIONES puedan leer modelos con
 * CompanyScope (que falla cerrado sin compañía activa, ver docs/decisiones.md
 * 2026-08-05). Los services bajo prueba no dependen de eso — bypasean el
 * scope internamente — y hay un test dedicado que lo confirma.
 */
function inventoryFixture(string $rate = '500.000000'): array
{
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    ExchangeRate::factory()->create([
        'company_id' => $company->id,
        'currency_id' => $company->foreign_currency_id,
        'rate_date' => now()->startOfMonth()->format('Y-m-d'),
        'rate' => $rate,
    ]);

    $fiscalYear = FiscalYear::factory()->create([
        'company_id' => $company->id,
        'year' => (int) now()->format('Y'),
    ]);

    FiscalPeriod::factory()->create([
        'fiscal_year_id' => $fiscalYear->id,
        'period_number' => (int) now()->format('n'),
        'start_date' => now()->startOfMonth()->format('Y-m-d'),
        'end_date' => now()->endOfMonth()->format('Y-m-d'),
        'status' => 'open',
    ]);

    $documentType = DocumentType::factory()->create([
        'company_id' => $company->id,
        'code' => 'INV',
        'origin_module' => 'inventario',
    ]);

    $accounts = [
        'inventory' => ChartOfAccount::factory()->create(['company_id' => $company->id, 'account_type' => 'asset']),
        'stock_increase' => ChartOfAccount::factory()->create(['company_id' => $company->id, 'account_type' => 'other_income']),
        'stock_decrease' => ChartOfAccount::factory()->create(['company_id' => $company->id, 'account_type' => 'expense']),
    ];

    foreach ($accounts as $category => $account) {
        GlDetermination::factory()->create([
            'company_id' => $company->id,
            'scope_level' => 'company',
            'scope_id' => null,
            'category' => $category,
            'account_id' => $account->id,
        ]);
    }

    $item = Item::factory()->create(['company_id' => $company->id, 'code' => 'ART-1']);
    $warehouse = Warehouse::factory()->create(['company_id' => $company->id, 'code' => 'ALM1']);

    return compact('company', 'documentType', 'accounts', 'item', 'warehouse');
}

function postMovement(array $f, string $operation, array $lines): InventoryDocument
{
    return app(PostStockMovementService::class)->post(
        $f['company'], $f['documentType'], $operation, now(), now(), $lines
    );
}

/**
 * Como inventoryFixture(), pero además autentica al usuario: los tests HTTP
 * prueban la puerta de entrada, no el motor.
 */
function movementFixture(): array
{
    $f = inventoryFixture();

    logInAsCompanyUser($f['company']);

    return $f;
}

/**
 * Extiende inventoryFixture() con lo que el ciclo de compra necesita: cuenta
 * puente GR/IR, proveedor con su cuenta de control, y un tipo de documento
 * del módulo de compras para la factura y los costos de importación.
 */
function purchaseFixture(string $rate = '500.000000'): array
{
    $f = inventoryFixture($rate);

    $f['accounts']['gr_ir_clearing'] = ChartOfAccount::factory()->create([
        'company_id' => $f['company']->id, 'account_type' => 'liability',
    ]);

    GlDetermination::factory()->create([
        'company_id' => $f['company']->id,
        'scope_level' => 'company',
        'scope_id' => null,
        'category' => 'gr_ir_clearing',
        'account_id' => $f['accounts']['gr_ir_clearing']->id,
    ]);

    $f['accounts']['payable'] = ChartOfAccount::factory()->create([
        'company_id' => $f['company']->id, 'account_type' => 'liability',
    ]);

    $f['supplier'] = BusinessPartner::factory()->create([
        'company_id' => $f['company']->id,
        'code' => 'P-001',
        'type' => 'supplier',
        'gl_account_id' => $f['accounts']['payable']->id,
    ]);

    $f['invoiceType'] = DocumentType::factory()->create([
        'company_id' => $f['company']->id, 'code' => 'FCP', 'origin_module' => 'compras',
    ]);

    return $f;
}

function postPurchaseReceipt(array $f, $quantity = 100, $unitCost = 5000): InventoryDocument
{
    return app(PostStockMovementService::class)->post(
        $f['company'], $f['documentType'], 'purchase_receipt', now(), now(),
        [new StockLineInput(
            $f['item']->id, $f['warehouse']->id, quantity: $quantity, unitCostLocal: $unitCost
        )],
        businessPartnerId: $f['supplier']->id,
    );
}

function movementPayload(array $f, string $operation, array $lineOverrides = []): array
{
    return [
        'operation' => $operation,
        'document_type_id' => $f['documentType']->id,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'lines' => [array_merge([
            'item_id' => $f['item']->id,
            'warehouse_id' => $f['warehouse']->id,
            'quantity' => 10,
            'unit_cost_local' => 1000,
        ], $lineOverrides)],
    ];
}

/*
|--------------------------------------------------------------------------
| Fixtures de facturación
|--------------------------------------------------------------------------
|
| Misma razón que los de inventario: los comparten el test del motor de venta
| y el del XML, y una función declarada dentro de un archivo de test solo
| existe si ESE archivo se cargó.
|
*/
/**
 * Extiende inventoryFixture() con lo que la venta necesita: cuenta de costo de
 * ventas, actividad económica con su cuenta de ingresos, cuenta de IVA débito
 * fiscal, un cliente y existencia que vender.
 */
function salesFixture(): array
{
    $f = inventoryFixture();

    $f['company']->update(['tax_id' => '3101123456']);

    $f['accounts']['cogs'] = ChartOfAccount::factory()->create([
        'company_id' => $f['company']->id, 'account_type' => 'cost_of_sales',
    ]);

    GlDetermination::factory()->create([
        'company_id' => $f['company']->id, 'scope_level' => 'company', 'scope_id' => null,
        'category' => 'cogs', 'account_id' => $f['accounts']['cogs']->id,
    ]);

    $f['accounts']['revenue'] = ChartOfAccount::factory()->create([
        'company_id' => $f['company']->id, 'account_type' => 'income',
    ]);

    $f['activity'] = CompanyEconomicActivity::create([
        'company_id' => $f['company']->id,
        'code' => '620100',
        'name' => 'Programación informática',
        'revenue_account_id' => $f['accounts']['revenue']->id,
        'is_default' => true,
    ]);

    $f['accounts']['iva'] = ChartOfAccount::factory()->create([
        'company_id' => $f['company']->id, 'account_type' => 'liability',
    ]);

    BillingTaxAccount::create([
        'company_id' => $f['company']->id,
        'iva_rate_code' => '08',
        'account_id' => $f['accounts']['iva']->id,
    ]);

    $f['accounts']['receivable'] = ChartOfAccount::factory()->create([
        'company_id' => $f['company']->id, 'account_type' => 'asset',
    ]);

    $f['customer'] = BusinessPartner::factory()->create([
        'company_id' => $f['company']->id,
        'code' => 'C-001',
        'type' => 'client',
        'identification_type' => '02',
        'gl_account_id' => $f['accounts']['receivable']->id,
    ]);

    $f['salesType'] = DocumentType::factory()->create([
        'company_id' => $f['company']->id, 'code' => 'FVE', 'origin_module' => 'inventario',
    ]);

    // Existencia: 100 u a ₡1.000 de costo.
    postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 100, unitCostLocal: 1000),
    ]);

    return $f;
}

function saleLine(array $f, $quantity = 10, $price = 2500, array $overrides = []): SalesLineInput
{
    return new SalesLineInput(
        cabysCode: $overrides['cabys'] ?? '2310110000000',
        description: 'Producto de prueba',
        unitCode: 'Unid',
        quantity: $quantity,
        unitPrice: $price,
        taxes: $overrides['taxes'] ?? [new SalesTaxInput(ivaRateCode: '08')],
        itemId: $overrides['itemId'] ?? $f['item']->id,
        warehouseId: $overrides['warehouseId'] ?? $f['warehouse']->id,
        itemCode: 'ART-1',
        isService: $overrides['isService'] ?? false,
        discountCode: $overrides['discountCode'] ?? null,
        discountAmount: $overrides['discountAmount'] ?? 0,
    );
}

function postSale(array $f, array $overrides = []): SalesDocument
{
    $input = new SalesDocumentInput(
        documentTypeId: $f['salesType']->id,
        fiscalDocumentType: $overrides['fiscalType'] ?? '01',
        currencyId: $f['company']->local_currency_id,
        saleCondition: $overrides['condition'] ?? '02',
        documentDate: now(),
        postingDate: now(),
        lines: $overrides['lines'] ?? [saleLine($f)],
        businessPartnerId: array_key_exists('partnerId', $overrides) ? $overrides['partnerId'] : $f['customer']->id,
        // array_key_exists y no ??: un override a null es intencional (probar
        // que la venta a crédito exige plazo) y ?? lo pisaría con el default.
        creditTermDays: array_key_exists('creditTermDays', $overrides) ? $overrides['creditTermDays'] : 30,
        payments: $overrides['payments'] ?? [],
        references: $overrides['references'] ?? [],
    );

    return app(PostSalesDocumentService::class)->post($f['company'], $input);
}
