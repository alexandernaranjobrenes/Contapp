<?php

namespace Database\Seeders;

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\CostAllocationRule;
use App\Domains\Accounting\Models\CostAllocationRuleLine;
use App\Domains\Accounting\Models\CostCenter;
use App\Domains\Accounting\Models\Currency;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Services\PeriodCloseService;
use App\Domains\BusinessPartners\Models\BpCategory;
use App\Domains\BusinessPartners\Models\BpFamily;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Support\CurrentCompany;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Catálogos de apoyo de PRUEBA que una compañía necesita para poder
 * contabilizar contra el catálogo de cuentas de TestChartOfAccountsSeeder:
 * año fiscal con sus períodos, tipos de documento, centros de costo con sus
 * normas de reparto, y socios de negocio con su clasificación.
 *
 * Corre DESPUÉS de TestChartOfAccountsSeeder: los socios de negocio exigen
 * gl_account_id (BusinessPartnerController::validated) y acá se resuelve por
 * código de cuenta, así que sin el catálogo cargado esto falla de una y con
 * un mensaje claro, en vez de dejar datos a medias.
 *
 * Dos cosas que a propósito NO se siembran, porque la app las crea "de
 * oficio" la primera vez que hacen falta y duplicarlas acá solo abre la
 * puerta a que queden distintas:
 *   - APE (saldos iniciales), que crea OpeningBalanceBulkImporter.
 *   - El tipo de conciliación, que crea AccountReconciliationController.
 * ACC sí se siembra, pero con exactamente los mismos valores que le pondría
 * PeriodCloseService::closingDocumentType(), para que su firstOrCreate
 * encuentre este mismo registro y no intente otro.
 *
 * Idempotente: se puede correr las veces que haga falta.
 *
 * Uso:
 *   SEED_COMPANY_ID=604 php artisan db:seed --class=TestCompanySetupSeeder
 */
class TestCompanySetupSeeder extends Seeder
{
    /** Compañía destino. Si queda en null se lee de SEED_COMPANY_ID. */
    public ?int $companyId = null;

    public function __construct(private readonly PeriodCloseService $periodClose) {}

    public function run(): void
    {
        $companyId = $this->companyId ?? (int) env('SEED_COMPANY_ID');

        if ($companyId <= 0) {
            throw new RuntimeException(
                'Indicá la compañía destino: SEED_COMPANY_ID=<id> php artisan db:seed --class=TestCompanySetupSeeder'
            );
        }

        $company = Company::findOrFail($companyId);

        // Sin esto CompanyScope falla cerrado y ningún firstOrCreate
        // encontraría lo ya sembrado (ver TestChartOfAccountsSeeder).
        app(CurrentCompany::class)->set($company->id);

        $year = $this->seedFiscalYear($company);
        $this->seedExchangeRates($company, $year);
        $this->seedDocumentTypes($company);
        $centers = $this->seedCostCenters($company, $year);
        $this->seedCostAllocationRules($company, $year, $centers);
        $this->seedBusinessPartners($company, $year);

        $this->command?->info("Catálogos de apoyo cargados en «{$company->legal_name}» (id {$company->id}).");
    }

    /**
     * Reutiliza el service de la app en vez de armar el año a mano: es el
     * mismo camino que usa el usuario desde la pantalla de cierre, y ya deja
     * los 12 períodos mensuales en 'open'. Solo se invoca si la compañía no
     * tiene ningún año todavía — createNextYear() crea el SIGUIENTE al más
     * reciente, así que llamarlo dos veces agregaría 2027, 2028...
     *
     * @return int el año fiscal vigente de la compañía
     */
    private function seedFiscalYear(Company $company): int
    {
        $existing = FiscalYear::max('year');

        if ($existing !== null) {
            $this->command?->line("  Año fiscal {$existing} ya existía; no se crea otro.");

            return (int) $existing;
        }

        $fiscalYear = $this->periodClose->createNextYear($company);

        $this->command?->line("  Año fiscal {$fiscalYear->year} creado con {$fiscalYear->periods->count()} períodos abiertos.");

        return (int) $fiscalYear->year;
    }

    /**
     * Los tipos de cambio son POR COMPAÑÍA (exchange_rates.company_id), así
     * que una compañía nueva nace sin ninguno y NO puede contabilizar: aunque
     * el asiento sea íntegramente en colones, PostJournalService::rateOnOrBefore()
     * necesita la tasa de la moneda extranjera para derivar los importes
     * foreign/system de cada línea, y sin ella lanza MissingExchangeRateException.
     *
     * Se siembra una serie MENSUAL con variación real (no una tasa plana):
     * el diferencial cambiario y la revaluación solo producen diferencias si
     * la tasa se mueve entre la fecha del movimiento y la de corte.
     *
     * rate_type 'reference' es el que usa el sincronizador del BCCR
     * (SyncBccrExchangeRatesService); rateOnOrBefore() no discrimina por tipo,
     * toma la más reciente en o antes de la fecha.
     */
    private function seedExchangeRates(Company $company, int $year): void
    {
        $foreignCurrencyId = $company->foreign_currency_id;

        if (! $foreignCurrencyId) {
            $this->command?->warn('  La compañía no tiene moneda extranjera configurada; se omiten tipos de cambio.');

            return;
        }

        // Colón por dólar, mes a mes: arranca en 505 y sube hasta 527.
        $rates = [
            1 => '505.250000', 2 => '508.400000', 3 => '511.750000', 4 => '509.900000',
            5 => '513.300000', 6 => '516.800000', 7 => '514.150000', 8 => '518.600000',
            9 => '521.050000', 10 => '523.700000', 11 => '525.400000', 12 => '527.150000',
        ];

        foreach ($rates as $month => $rate) {
            ExchangeRate::updateOrCreate(
                [
                    'company_id' => $company->id,
                    'currency_id' => $foreignCurrencyId,
                    'rate_date' => sprintf('%d-%02d-01', $year, $month),
                    'rate_type' => 'reference',
                ],
                ['rate' => $rate, 'source' => 'manual', 'is_locked' => false]
            );
        }

        $this->command?->line("  Tipos de cambio: 12 tasas mensuales de {$year} (505,25 → 527,15).");
    }

    /**
     * bp_line_requirement es lo que decide si una línea con socio abre
     * partida ('due_date'), cancela una existente ('application') o puede
     * hacer cualquiera de las dos ('either') — es la base de antigüedad de
     * saldos y estados de cuenta, así que se siembra explícito por tipo.
     */
    private function seedDocumentTypes(Company $company): void
    {
        $types = [
            ['ADD', 'Asiento de diario', 'contable', 'libre', 'either', false],
            ['ADC', 'Asiento diferencial cambiario', 'contable', 'libre', 'none', false],
            ['FVE', 'Factura de venta', 'ventas', 'libre', 'due_date', true],
            ['FCP', 'Factura de compra', 'compras', 'libre', 'due_date', false],
            ['REC', 'Recibo de dinero', 'cxc', 'libre', 'application', false],
            ['PAG', 'Pago a proveedor', 'cxp', 'libre', 'application', false],
            ['TRB', 'Transferencia bancaria', 'bancos', 'libre', 'none', false],
            // origin_module 'inventario' es lo que hace que estos tipos
            // aparezcan en la pantalla de movimientos de existencias
            // (InventoryDocumentController los filtra por ahí). 'local_fija'
            // porque el kardex siempre asienta en moneda local, con el tipo
            // de cambio implícito del costo promedio, no el del día.
            ['EIN', 'Entrada de inventario', 'inventario', 'local_fija', 'none', false],
            ['SIN', 'Salida de inventario', 'inventario', 'local_fija', 'none', false],
            ['TRA', 'Traslado entre almacenes', 'inventario', 'local_fija', 'none', false],
        ];

        foreach ($types as [$code, $name, $module, $currencyMode, $bpRequirement, $electronic]) {
            DocumentType::firstOrCreate(
                ['company_id' => $company->id, 'code' => $code],
                [
                    'name' => $name,
                    'origin_module' => $module,
                    'generates_journal' => true,
                    'requires_electronic_key' => $electronic,
                    'bp_line_requirement' => $bpRequirement,
                    'numbering_mask' => '99999999',
                    'field_count' => 8,
                    'next_consecutive' => 1,
                    'consecutive_on_save' => true,
                    'currency_mode' => $currencyMode,
                    'status' => 'active',
                ]
            );
        }

        // Mismos valores exactos que PeriodCloseService::closingDocumentType().
        DocumentType::firstOrCreate(
            ['company_id' => $company->id, 'code' => 'ACC'],
            [
                'name' => 'Asiento de cierre contable',
                'origin_module' => 'contable',
                'generates_journal' => true,
                'currency_mode' => 'local_fija',
                'is_closing_type' => true,
                'status' => 'active',
            ]
        );

        $this->command?->line('  Tipos de documento: ADD, ADC, FVE, FCP, REC, PAG, TRB, EIN, SIN, TRA, ACC.');
    }

    /**
     * @return array<string, CostCenter> code => centro
     */
    private function seedCostCenters(Company $company, int $year): array
    {
        $from = "{$year}-01-01";

        $centers = [
            ['ADM', 'Administración', null, true],
            ['VEN', 'Ventas y mercadeo', null, true],
            ['OPE', 'Operaciones', null, true],
            ['PRY', 'Proyectos especiales', null, true],
            // Vencido a propósito: sirve para comprobar que CostCenter::isPostableOn()
            // lo rechaza en una fecha posterior a su cierre.
            ['TMP', 'Proyecto temporal (cerrado)', "{$year}-06-30", true],
        ];

        $created = [];

        foreach ($centers as [$code, $name, $until, $active]) {
            $created[$code] = CostCenter::firstOrCreate(
                ['company_id' => $company->id, 'code' => $code],
                [
                    'name' => $name,
                    'start_date' => $from,
                    'end_date' => $until,
                    'is_active' => $active,
                ]
            );
        }

        $this->command?->line('  Centros de costo: '.implode(', ', array_keys($created)).'.');

        return $created;
    }

    /**
     * Las cuentas con requires_cost_center NO piden un centro de costo
     * directo: piden una NORMA DE REPARTO (PostJournalService::post() lanza
     * MissingCostAllocationRuleException). Sin estas normas, todas las
     * cuentas de gasto, venta y costo del catálogo de prueba serían
     * inusables.
     *
     * @param  array<string, CostCenter>  $centers
     */
    private function seedCostAllocationRules(Company $company, int $year, array $centers): void
    {
        $from = "{$year}-01-01";

        // code => [nombre, vigencia_hasta, activa, [código de centro => %]]
        $rules = [
            'ADM100' => ['100% Administración', null, true, ['ADM' => '100.00']],
            'VEN100' => ['100% Ventas', null, true, ['VEN' => '100.00']],
            'OPE100' => ['100% Operaciones', null, true, ['OPE' => '100.00']],
            'GRAL' => ['Reparto general 40/30/30', null, true, ['ADM' => '40.00', 'VEN' => '30.00', 'OPE' => '30.00']],
            'MITAD' => ['Mitad ventas / mitad operaciones', null, true, ['VEN' => '50.00', 'OPE' => '50.00']],
            // Vencida a propósito: comprueba el rechazo por vigencia en
            // CostAllocationRule::isEffectiveOn().
            'VENC' => ['Norma vencida (prueba)', "{$year}-06-30", true, ['ADM' => '100.00']],
        ];

        foreach ($rules as $code => [$name, $until, $active, $split]) {
            // Los porcentajes deben sumar exactamente 100 (misma regla que
            // CostAllocationRuleController::linesSumTo100); se verifica acá
            // para que un typo en la tabla de arriba no pase silencioso.
            $sum = array_reduce($split, fn (string $carry, string $p) => bcadd($carry, $p, 2), '0.00');

            if (bccomp($sum, '100.00', 2) !== 0) {
                throw new RuntimeException("La norma {$code} suma {$sum}%, debe sumar exactamente 100%.");
            }

            DB::transaction(function () use ($company, $code, $name, $from, $until, $active, $split, $centers): void {
                $rule = CostAllocationRule::firstOrCreate(
                    ['company_id' => $company->id, 'code' => $code],
                    ['name' => $name, 'valid_from' => $from, 'valid_until' => $until, 'is_active' => $active]
                );

                // Se reemplazan siempre, igual que replaceLines() en el
                // controlador: así una corrida posterior corrige el reparto
                // en vez de duplicar líneas.
                CostAllocationRuleLine::where('cost_allocation_rule_id', $rule->id)->delete();

                $position = 1;

                foreach ($split as $centerCode => $percentage) {
                    CostAllocationRuleLine::create([
                        'cost_allocation_rule_id' => $rule->id,
                        'cost_center_id' => $centers[$centerCode]->id,
                        'percentage' => $percentage,
                        'position' => $position++,
                    ]);
                }
            });
        }

        $this->command?->line('  Normas de reparto: '.implode(', ', array_keys($rules)).'.');
    }

    private function seedBusinessPartners(Company $company, int $year): void
    {
        $categories = [
            'A' => 'Clientes categoría A',
            'B' => 'Clientes categoría B',
            'PRV' => 'Proveedores nacionales',
            'EXT' => 'Proveedores del exterior',
        ];

        $families = [
            'MERC' => 'Mercadería',
            'SERV' => 'Servicios',
            'GAST' => 'Gastos generales',
        ];

        foreach ($categories as $code => $name) {
            BpCategory::firstOrCreate(['company_id' => $company->id, 'code' => $code], ['name' => $name]);
        }

        foreach ($families as $code => $name) {
            BpFamily::firstOrCreate(['company_id' => $company->id, 'code' => $code], ['name' => $name]);
        }

        $categoryIds = BpCategory::pluck('id', 'code');
        $familyIds = BpFamily::pluck('id', 'code');
        $centerIds = CostCenter::pluck('id', 'code');
        $currencyIds = Currency::whereIn('code', ['CRC', 'USD'])->pluck('id', 'code');

        // Cuenta de mayor por socio: es obligatoria (gl_account_id) y debe
        // aceptar movimientos. Se resuelve por CÓDIGO contra el catálogo de
        // prueba; si falta, es que no se corrió TestChartOfAccountsSeeder.
        $accountIds = ChartOfAccount::whereIn('code', [
            '1-01-02-01-001', // Clientes locales
            '1-01-02-01-002', // Clientes del exterior
            '2-01-01-01-001', // Proveedores locales
            '2-01-01-01-002', // Proveedores del exterior
        ])->pluck('id', 'code');

        foreach (['1-01-02-01-001', '1-01-02-01-002', '2-01-01-01-001', '2-01-01-01-002'] as $needed) {
            if (! isset($accountIds[$needed])) {
                throw new RuntimeException(
                    "Falta la cuenta {$needed} en la compañía. Corré antes: ".
                    'SEED_COMPANY_ID='.$company->id.' php artisan db:seed --class=TestChartOfAccountsSeeder'
                );
            }
        }

        // [código, nombre, tipo, cédula, cuenta, moneda, categoría, familia, centro, límite, plazo, estado]
        $partners = [
            ['CLI-001', 'Distribuidora La Central S.A.', 'client', '3101123456', '1-01-02-01-001', 'CRC', 'A', 'MERC', 'VEN', '5000000.00', 30, 'active'],
            ['CLI-002', 'Comercial El Roble S.A.', 'client', '3101234567', '1-01-02-01-001', 'CRC', 'A', 'MERC', 'VEN', '3000000.00', 30, 'active'],
            ['CLI-003', 'Servicios Técnicos Lumen S.A.', 'client', '3101345678', '1-01-02-01-001', 'CRC', 'B', 'SERV', 'VEN', '1500000.00', 15, 'active'],
            ['CLI-004', 'María Fernanda Rojas Vega', 'client', '108790456', '1-01-02-01-001', 'CRC', 'B', 'SERV', 'VEN', '500000.00', 8, 'active'],
            ['CLI-005', 'Northbridge Trading LLC', 'client', 'US-882314', '1-01-02-01-002', 'USD', 'A', 'MERC', 'VEN', '25000.00', 45, 'active'],
            ['CLI-006', 'Antigua Importaciones S.A. (inactivo)', 'client', '3101456789', '1-01-02-01-001', 'CRC', 'B', 'MERC', 'VEN', '0.00', 0, 'inactive'],
            ['PRV-001', 'Suministros Industriales del Sur S.A.', 'supplier', '3101567890', '2-01-01-01-001', 'CRC', 'PRV', 'MERC', 'OPE', null, 30, 'active'],
            ['PRV-002', 'Transportes Rápidos Mora S.A.', 'supplier', '3101678901', '2-01-01-01-001', 'CRC', 'PRV', 'GAST', 'OPE', null, 15, 'active'],
            ['PRV-003', 'Consultores Asociados Quirós y Cía.', 'supplier', '3101789012', '2-01-01-01-001', 'CRC', 'PRV', 'SERV', 'ADM', null, 30, 'active'],
            ['PRV-004', 'Pacific Components Inc.', 'supplier', 'US-774102', '2-01-01-01-002', 'USD', 'EXT', 'MERC', 'OPE', null, 60, 'active'],
            // 'both': el mismo socio es cliente y proveedor — caso que conviene
            // tener a mano para probar compensaciones y estados de cuenta.
            ['AMB-001', 'Grupo Logístico Talamanca S.A.', 'both', '3101890123', '1-01-02-01-001', 'CRC', 'A', 'SERV', 'OPE', '2000000.00', 30, 'active'],
        ];

        foreach ($partners as $p) {
            [$code, $name, $type, $taxId, $accountCode, $currency, $category, $family, $center, $limit, $terms, $status] = $p;

            BusinessPartner::updateOrCreate(
                ['company_id' => $company->id, 'code' => $code],
                [
                    'name' => $name,
                    'type' => $type,
                    'tax_id' => $taxId,
                    'gl_account_id' => $accountIds[$accountCode],
                    'currency_id' => $currencyIds[$currency],
                    'category_id' => $categoryIds[$category] ?? null,
                    'family_id' => $familyIds[$family] ?? null,
                    'cost_center_id' => $centerIds[$center] ?? null,
                    'credit_limit' => $limit,
                    'payment_terms_days' => $terms,
                    'partner_since' => "{$year}-01-01",
                    'status' => $status,
                ]
            );
        }

        $this->command?->line('  Socios de negocio: '.count($partners).' (clientes, proveedores y uno mixto).');
    }
}
