<?php

namespace Database\Seeders;

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\Currency;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Accounting\Models\FiscalPeriod;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Core\Models\AccountMaskConfig;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Licensing\Models\License;
use App\Domains\Licensing\Models\LicenseCategory;
use App\Domains\Licensing\Models\Propietario;
use App\Domains\Tax\Models\TaxRate;
use App\Domains\Tax\Models\TaxType;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Compañía de demostración para poder entrar a la app y ver datos reales:
 * catálogo de cuentas mínimo, año fiscal abierto y un usuario super admin.
 * No sustituye a un onboarding real (eso vendría con OpeningBalanceLoadService
 * + UI de configuración inicial), es solo para desarrollo/demo.
 */
class DemoCompanySeeder extends Seeder
{
    public function run(): void
    {
        $this->call(CurrencySeeder::class);

        $crc = Currency::where('code', 'CRC')->firstOrFail();
        $usd = Currency::where('code', 'USD')->firstOrFail();

        $company = Company::firstOrCreate(
            ['tax_id' => '3-101-000000'],
            [
                'legal_name' => 'CONTAPP Demo S.A.',
                'trade_name' => 'CONTAPP Demo',
                'country_code' => 'CR',
                'local_currency_id' => $crc->id,
                'foreign_currency_id' => $usd->id,
                'system_currency_id' => $usd->id,
                'timezone' => 'America/Costa_Rica',
                'status' => 'active',
            ]
        );

        // Sin esto, cada firstOrCreate() de abajo sobre un modelo con
        // BelongsToCompany busca con CompanyScope fallando cerrado (no hay
        // CurrentCompany en un comando de consola), nunca encuentra la fila
        // ya creada en una corrida anterior, e intenta insertarla de nuevo
        // -> viola el unique constraint. Detectado corriendo el seeder dos
        // veces contra Docker/MySQL real (ver docs/decisiones.md 2026-08-05).
        app(CurrentCompany::class)->set($company->id);

        AccountMaskConfig::firstOrCreate(
            ['company_id' => $company->id],
            ['segment_lengths' => [1, 2, 2, 2, 3]]
        );

        $admin = User::firstOrCreate(
            ['email' => 'admin@contapp.test'],
            [
                'name' => 'Administrador CONTAPP',
                'password' => Hash::make('password'),
                'is_super_admin' => true,
                'default_company_id' => $company->id,
                'status' => 'active',
            ]
        );

        $company->users()->syncWithoutDetaching([$admin->id => ['is_default' => true]]);

        // Propietario es un actor completamente aparte de $admin (CLAUDE.md
        // secc. 11/14, guard 'propietario' separado de 'web') — en este
        // entorno de desarrollo se crea igual con el mismo correo/clave que
        // el super usuario demo, solo para poder probar /backoffice/login
        // sin tener que aprovisionar una cuenta a mano cada vez. En un
        // despliegue real sería la cuenta personal del operador de CONTAPP,
        // sin relación con ningún usuario de ninguna compañía cliente.
        $propietario = Propietario::firstOrCreate(
            ['email' => 'admin@contapp.test'],
            ['name' => 'Propietario CONTAPP', 'password' => Hash::make('password')]
        );

        // Licencia real de la compañía demo (distinta de $sampleLicense más
        // abajo, que es un código suelto pensado para probar /activate) —
        // sin esto companies.license_id queda null y el pie de página de
        // licencia (CLAUDE.md secc. 17, ver HandleInertiaRequests) no tiene
        // nada que mostrar en el entorno de desarrollo.
        $demoCategory = LicenseCategory::firstOrCreate(
            ['name' => 'Profesional'],
            ['max_companies' => 3, 'duration_months' => 12, 'description' => 'Plan intermedio de demostración', 'is_active' => true]
        );

        $demoLicense = License::firstOrCreate(
            ['code' => 'CONTAPP-DEMO-COMP-0001'],
            [
                'category_id' => $demoCategory->id,
                'max_companies' => $demoCategory->max_companies,
                'expires_at' => now()->addYear()->format('Y-m-d'),
                'status' => 'active',
                'notes' => 'Licencia real de la compañía demo',
                'issued_by' => $propietario->id,
            ]
        );
        $demoLicense->forceFill(['superuser_id' => $admin->id])->save();

        if (! $company->license_id) {
            $company->update(['license_id' => $demoLicense->id]);
        }

        ExchangeRate::firstOrCreate(
            [
                'company_id' => $company->id,
                'currency_id' => $usd->id,
                'rate_date' => now()->format('Y-m-d'),
                'rate_type' => 'reference',
            ],
            ['rate' => '520.000000', 'source' => 'manual']
        );

        // Las 8 clases del gabinete de cuentas: docs/decisiones.md 2026-08-14.
        $accounts = [
            ['code' => '1-01-01-01-001', 'name' => 'Caja general', 'type' => 'asset', 'balance' => 'debit', 'cash' => true],
            ['code' => '1-01-01-02-001', 'name' => 'Banco Nacional CRC', 'type' => 'asset', 'balance' => 'debit', 'cash' => true],
            ['code' => '1-01-01-02-002', 'name' => 'Banco Nacional USD', 'type' => 'asset', 'balance' => 'debit', 'currency_mode' => 'foreign', 'cash' => true],
            ['code' => '1-01-02-01-001', 'name' => 'Cuentas por cobrar clientes', 'type' => 'asset', 'balance' => 'debit', 'bp' => true],
            ['code' => '2-01-01-01-001', 'name' => 'Cuentas por pagar proveedores', 'type' => 'liability', 'balance' => 'credit', 'bp' => true],
            ['code' => '2-01-03-01-001', 'name' => 'IVA por pagar (devengado)', 'type' => 'liability', 'balance' => 'credit', 'tax' => 'iva_devengado'],
            ['code' => '2-01-03-02-001', 'name' => 'IVA crédito fiscal (soportado)', 'type' => 'asset', 'balance' => 'debit', 'tax' => 'iva_soportado'],
            ['code' => '3-01-01-01-001', 'name' => 'Capital social', 'type' => 'equity', 'balance' => 'credit'],
            ['code' => '3-02-01-01-001', 'name' => 'Utilidades acumuladas', 'type' => 'equity', 'balance' => 'credit'],
            ['code' => '4-01-01-01-001', 'name' => 'Ventas de servicios', 'type' => 'income', 'balance' => 'credit', 'tax' => 'sales'],
            ['code' => '5-01-01-01-001', 'name' => 'Costo de servicios vendidos', 'type' => 'cost_of_sales', 'balance' => 'debit'],
            ['code' => '6-01-01-01-001', 'name' => 'Gastos de operación', 'type' => 'expense', 'balance' => 'debit', 'tax' => 'purchases'],
            ['code' => '7-01-01-01-001', 'name' => 'Diferencial cambiario', 'type' => 'other_income', 'balance' => 'credit'],
            ['code' => '8-01-01-01-001', 'name' => 'Gastos financieros y comisiones', 'type' => 'other_expense', 'balance' => 'debit'],
        ];

        $accountIds = [];

        foreach ($accounts as $a) {
            $account = ChartOfAccount::firstOrCreate(
                ['company_id' => $company->id, 'code' => $a['code']],
                [
                    'description_es' => $a['name'],
                    'level' => 1,
                    'account_type' => $a['type'],
                    'normal_balance' => $a['balance'],
                    'currency_mode' => $a['currency_mode'] ?? 'local',
                    'accepts_posting' => true,
                    'requires_business_partner' => $a['bp'] ?? false,
                    'is_cash_account' => $a['cash'] ?? false,
                    'tax_classification' => $a['tax'] ?? 'none',
                ]
            );
            $accountIds[$a['code']] = $account->id;
        }

        $documentTypes = [
            ['code' => 'ADD', 'name' => 'Asiento de diario', 'module' => 'contable'],
            ['code' => 'ADC', 'name' => 'Asiento diferencial cambiario', 'module' => 'contable'],
            ['code' => 'ACC', 'name' => 'Asiento de cierre contable', 'module' => 'contable'],
            ['code' => 'FVE', 'name' => 'Factura de venta', 'module' => 'ventas'],
            ['code' => 'TRB', 'name' => 'Transferencia bancaria', 'module' => 'bancos'],
        ];

        foreach ($documentTypes as $dt) {
            DocumentType::firstOrCreate(
                ['company_id' => $company->id, 'code' => $dt['code']],
                [
                    'name' => $dt['name'],
                    'origin_module' => $dt['module'],
                    'generates_journal' => true,
                    'numbering_mask' => '99999999',
                    'field_count' => 8,
                    'next_consecutive' => 1,
                    'consecutive_on_save' => true,
                    'currency_mode' => 'libre',
                    'status' => 'active',
                    // ACC es el tipo reservado del asiento de cierre anual
                    // (ver PeriodCloseService::closingDocumentType()) — se
                    // marca acá también para que la compañía demo no quede
                    // con un ACC sin el flag que la aísla del formulario
                    // manual y del mayor auxiliar de resultados.
                    'is_closing_type' => $dt['code'] === 'ACC',
                ]
            );
        }

        $ivaType = TaxType::firstOrCreate(['code' => 'IVA'], ['name' => 'Impuesto al Valor Agregado']);

        // Las 5 tarifas oficiales de la Ley del IVA (9635, vigente desde
        // 2019-07-01): catálogo global (sin company_id, ver TaxType), así
        // que sembrarlo acá lo deja disponible de una vez para cualquier
        // compañía que se cree, sin depender de que el Propietario las
        // cargue a mano desde /backoffice/tax-rates.
        foreach ([
            ['code' => 'IVA-13', 'name' => 'IVA tarifa general 13%', 'percentage' => '13.00', 'grants_fiscal_credit' => true, 'fiscal_credit_note' => null],
            ['code' => 'IVA-4', 'name' => 'IVA tarifa reducida 4%', 'percentage' => '4.00', 'grants_fiscal_credit' => true, 'fiscal_credit_note' => null],
            ['code' => 'IVA-2', 'name' => 'IVA tarifa reducida 2%', 'percentage' => '2.00', 'grants_fiscal_credit' => true, 'fiscal_credit_note' => null],
            ['code' => 'IVA-1', 'name' => 'IVA tarifa reducida 1%', 'percentage' => '1.00', 'grants_fiscal_credit' => true, 'fiscal_credit_note' => null],
            ['code' => 'IVA-0', 'name' => 'IVA exento 0%', 'percentage' => '0.00', 'grants_fiscal_credit' => false, 'fiscal_credit_note' => 'Bienes y servicios exentos por ley — no genera crédito fiscal.'],
        ] as $rate) {
            TaxRate::firstOrCreate(
                ['tax_type_id' => $ivaType->id, 'code' => $rate['code']],
                [
                    'name' => $rate['name'],
                    'percentage' => $rate['percentage'],
                    'grants_fiscal_credit' => $rate['grants_fiscal_credit'],
                    'fiscal_credit_note' => $rate['fiscal_credit_note'],
                    'effective_from' => '2019-07-01',
                    'effective_to' => null,
                ]
            );
        }

        $year = (int) now()->format('Y');

        $fiscalYear = FiscalYear::firstOrCreate(
            ['company_id' => $company->id, 'year' => $year],
            ['status' => 'open']
        );

        for ($month = 1; $month <= 12; $month++) {
            $start = \Illuminate\Support\Carbon::create($year, $month, 1)->startOfMonth();

            FiscalPeriod::firstOrCreate(
                ['fiscal_year_id' => $fiscalYear->id, 'period_number' => $month],
                [
                    'start_date' => $start->format('Y-m-d'),
                    'end_date' => $start->copy()->endOfMonth()->format('Y-m-d'),
                    'status' => 'open',
                ]
            );
        }

        $sampleLicense = License::firstOrCreate(
            ['code' => 'CONTAPP-DEMO-0000-0000'],
            [
                'max_companies' => 1,
                'expires_at' => now()->addYear()->format('Y-m-d'),
                'status' => 'active',
                'notes' => 'Licencia de muestra para probar /activate',
                'issued_by' => $propietario->id,
            ]
        );

        $this->command?->info('Demo: admin@contapp.test / password — compañía "'.$company->trade_name.'"');
        $this->command?->info('Backoffice (Propietario): admin@contapp.test / password — /backoffice/login');
        $this->command?->info('Licencia de muestra para /activate: '.$sampleLicense->code);
    }
}
