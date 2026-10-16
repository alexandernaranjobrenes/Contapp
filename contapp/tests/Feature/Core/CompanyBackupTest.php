<?php

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Accounting\Models\FiscalPeriod;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\Accounting\Services\TrialBalanceService;
use App\Domains\BusinessPartners\Models\BpOpenItem;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\BusinessPartners\Services\ApplyPaymentService;
use App\Domains\Core\Backup\BackupCatalog;
use App\Domains\Core\Backup\BackupExporter;
use App\Domains\Core\Backup\BackupFile;
use App\Domains\Core\Backup\BackupImporter;
use App\Domains\Core\Backup\BackupOptions;
use App\Domains\Core\Backup\BackupSchema;
use App\Domains\Core\Backup\Exceptions\InvalidBackupException;
use App\Domains\Core\Models\AuditLog;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Models\Role;
use App\Domains\Core\Scopes\CompanyScope;
use App\Domains\Core\Services\PermissionGrantService;
use App\Domains\Inventory\DataTransferObjects\StockLineInput;
use App\Domains\Inventory\Models\GlDetermination;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Inventory\Services\PostStockMovementService;
use App\Domains\Licensing\Models\License;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\VacationMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| El respaldo de una compañía, de ida y vuelta (CLAUDE.md secc. 37)
|--------------------------------------------------------------------------
|
| Se exporta una compañía con movimientos de verdad y se importa en una
| compañía nueva: el balance de comprobación, las partidas abiertas, las
| existencias y las vacaciones tienen que dar lo mismo.
|
*/

const BACKUP_PASSWORD = 'clave-del-respaldo-1';

/**
 * Una compañía con su licencia y un 2026 con movimientos:
 * - 05/01 factura 1 000 (partida), 20/01 cobro de 400.
 * - 10/02 entran 10 unidades a 100; 15/03 salen 4.
 * - 10/03 factura 500 (partida), 15/04 cobro de 300.
 * - Vacaciones: 1 día el 31/01 y otro el 31/03.
 */
function backupFixture(): array
{
    $superuser = User::factory()->create(['is_super_admin' => true]);
    $license = License::factory()->create(['superuser_id' => $superuser->id, 'max_companies' => 5]);
    $company = Company::factory()->create(['license_id' => $license->id, 'legal_name' => 'Ferretería El Clavo S.A.']);
    $superuser->companies()->attach($company->id, ['is_default' => true]);
    $superuser->update(['default_company_id' => $company->id]);

    ExchangeRate::factory()->create(['company_id' => $company->id, 'currency_id' => $company->foreign_currency_id, 'rate_date' => '2026-01-01', 'rate' => '520.000000']);
    $year = FiscalYear::factory()->create(['company_id' => $company->id, 'year' => 2026]);

    foreach (range(1, 12) as $month) {
        $start = Carbon\Carbon::create(2026, $month, 1);
        FiscalPeriod::factory()->create(['fiscal_year_id' => $year->id, 'period_number' => $month, 'start_date' => $start->format('Y-m-d'), 'end_date' => $start->copy()->endOfMonth()->format('Y-m-d'), 'status' => 'open']);
    }

    $account = fn (string $code, string $type, array $extra = []) => ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => $code, 'account_type' => $type, 'normal_balance' => in_array($type, ['asset', 'expense'], true) ? 'debit' : 'credit'] + $extra);
    $accounts = [
        'cash' => $account('1-01-01-01-001', 'asset'),
        'receivable' => $account('1-01-02-01-001', 'asset'),
        'inventory' => $account('1-01-03-01-001', 'asset'),
        'sales' => $account('4-01-01-01-001', 'income'),
        'stock_increase' => $account('4-02-01-01-001', 'income'),
        'stock_decrease' => $account('5-01-01-01-001', 'expense'),
    ];

    foreach (['inventory', 'stock_increase', 'stock_decrease'] as $category) {
        GlDetermination::factory()->create(['company_id' => $company->id, 'scope_level' => 'company', 'scope_id' => null, 'category' => $category, 'account_id' => $accounts[$category]->id]);
    }

    $types = [
        'FVE' => DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'FVE', 'origin_module' => 'ventas']),
        'TRB' => DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'TRB', 'origin_module' => 'bancos']),
        'INV' => DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'INV', 'origin_module' => 'inventario']),
    ];

    $client = BusinessPartner::factory()->create(['company_id' => $company->id, 'code' => 'CLI-0001', 'gl_account_id' => $accounts['receivable']->id, 'currency_id' => $company->local_currency_id]);
    $item = Item::factory()->create(['company_id' => $company->id, 'code' => 'ART-1']);
    $warehouse = Warehouse::factory()->create(['company_id' => $company->id, 'code' => 'ALM1']);

    $f = compact('superuser', 'license', 'company', 'accounts', 'types', 'client', 'item', 'warehouse');

    $invoice1 = backupInvoice($f, '2026-01-05', '1000');
    backupPayment($f, '2026-01-20', '400', $invoice1);
    backupStock($f, 'goods_receipt', '2026-02-10', 10, 100);
    $invoice2 = backupInvoice($f, '2026-03-10', '500');
    backupStock($f, 'goods_issue', '2026-03-15', 4);
    backupPayment($f, '2026-04-15', '300', $invoice2);

    $employee = Employee::create([
        'company_id' => $company->id, 'code' => 'EMP-0001', 'identification_type' => 'cedula', 'identification_number' => '101110111',
        'first_name' => 'Ana', 'last_name1' => 'Mora', 'hire_date' => '2025-01-01', 'salary_type' => 'mensual', 'base_salary' => '600000.00',
        'weekly_hours' => '48', 'payment_method' => 'efectivo', 'status' => 'active',
    ]);
    VacationMovement::create(['company_id' => $company->id, 'employee_id' => $employee->id, 'type' => 'accrual', 'movement_date' => '2026-01-31', 'days' => '1.0000']);
    VacationMovement::create(['company_id' => $company->id, 'employee_id' => $employee->id, 'type' => 'accrual', 'movement_date' => '2026-03-31', 'days' => '1.0000']);

    return $f + ['employee' => $employee];
}

function backupInvoice(array $f, string $date, string $amount): JournalEntry
{
    $currency = $f['company']->local_currency_id;

    return app(PostJournalService::class)->post($f['company'], $f['types']['FVE'], new DateTime($date), new DateTime($date), [
        new JournalLineInput($f['accounts']['receivable']->id, $currency, debit: $amount, credit: 0, businessPartnerId: $f['client']->id, dueDate: date('Y-m-d', strtotime($date.' +30 days')), opensItem: true),
        new JournalLineInput($f['accounts']['sales']->id, $currency, debit: 0, credit: $amount),
    ], 'Factura');
}

function backupPayment(array $f, string $date, string $amount, JournalEntry $invoice): void
{
    $currency = $f['company']->local_currency_id;
    $payment = app(PostJournalService::class)->post($f['company'], $f['types']['TRB'], new DateTime($date), new DateTime($date), [
        new JournalLineInput($f['accounts']['cash']->id, $currency, debit: $amount, credit: 0),
        new JournalLineInput($f['accounts']['receivable']->id, $currency, debit: 0, credit: $amount, businessPartnerId: $f['client']->id),
    ], 'Cobro');

    $openItem = BpOpenItem::where('origin_journal_detail_id', $invoice->details()->where('debit_local', '>', 0)->value('id'))->sole();
    app(ApplyPaymentService::class)->apply($openItem, $payment, $amount, new DateTime($date));
}

function backupStock(array $f, string $operation, string $date, int $quantity, ?int $cost = null): void
{
    app(PostStockMovementService::class)->post($f['company'], $f['types']['INV'], $operation, new DateTime($date), new DateTime($date), [
        new StockLineInput($f['item']->id, $f['warehouse']->id, $quantity, $cost),
    ]);
}

/** Exporta, importa en una compañía nueva de la misma licencia y devuelve las dos. */
function backupRoundTrip(array $f, ?string $from = null, ?string $to = null): array
{
    $options = new BackupOptions($f['company'], array_keys(BackupCatalog::SECTIONS), $from, $to);
    $path = tempnam(sys_get_temp_dir(), 'respaldo');
    $out = fopen($path, 'wb');
    BackupFile::write(app(BackupExporter::class)->lines($options, ['name' => 'Prueba', 'email' => 'prueba@example.com', 'role' => 'Superusuario']), BACKUP_PASSWORD, ['company' => 'X', 'period' => $options->describePeriod()], $out);
    fclose($out);

    $inspection = app(BackupImporter::class)->inspect($path, BACKUP_PASSWORD);
    $result = app(BackupImporter::class)->import($path, BACKUP_PASSWORD, $f['license'], ['legal_name' => 'Ferretería El Clavo (restaurada)']);
    unlink($path);

    return ['inspection' => $inspection, 'result' => $result, 'copy' => $result['company']];
}

/** El balance de comprobación por código de cuenta: saldo inicial, movimientos y saldo final. */
function backupTrialBalance(Company $company, ?string $from = null, ?string $to = null): array
{
    return collect(app(TrialBalanceService::class)->build($company, $from, $to)->rows)
        ->mapWithKeys(fn ($row) => [$row->code => [$row->openingBalance, $row->periodDebit, $row->periodCredit, $row->closingBalance]])
        ->all();
}

function backupOpenItems(Company $company): array
{
    return BpOpenItem::query()
        ->join('business_partners', 'business_partners.id', '=', 'bp_open_items.business_partner_id')
        ->where('business_partners.company_id', $company->id)
        ->orderBy('bp_open_items.document_number')
        ->get(['bp_open_items.document_number', 'bp_open_items.balance', 'bp_open_items.status'])
        ->map(fn ($item) => [(int) $item->document_number, (string) $item->balance, $item->status])
        ->all();
}

function backupStockOf(Company $company): array
{
    return DB::table('item_warehouses')
        ->join('items', 'items.id', '=', 'item_warehouses.item_id')
        ->where('items.company_id', $company->id)
        ->get(['items.code', 'item_warehouses.on_hand', 'items.avg_cost_local'])
        ->map(fn ($row) => [$row->code, (float) $row->on_hand, (float) $row->avg_cost_local])
        ->all();
}

function backupVacations(Company $company): float
{
    return (float) DB::table('vacation_movements')->where('company_id', $company->id)->sum('days');
}

it('cada tabla de la base está en el respaldo o tiene su motivo para no estar', function () {
    $tables = collect(Schema::getTableListing())->map(fn (string $table) => str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table);

    foreach ($tables as $table) {
        expect(BackupCatalog::includes($table) || isset(BackupCatalog::EXCLUDED[$table]))
            ->toBeTrue("{$table}: agregala a BackupCatalog::TABLES (o a EXCLUDED con el motivo)");
    }

    // Y cada referencia de lo que va apunta a algo que también va, a algo
    // de CONTAPP o a una persona (por su correo).
    $schema = app(BackupSchema::class);

    foreach (array_keys(BackupCatalog::TABLES) as $table) {
        foreach ($schema->references($table) as $column => $target) {
            $ok = BackupCatalog::includes($target)
                || in_array($target, [...BackupCatalog::GLOBAL_TABLES, 'users', 'companies'], true)
                || $schema->isNullable($table, $column)
                || ($table === 'audit_logs' && $target === 'propietarios');

            expect($ok)->toBeTrue("{$table}.{$column} apunta a {$target}, que no va en el respaldo");
        }
    }
});

it('todo el historial: la compañía importada da lo mismo', function () {
    $f = backupFixture();
    ['copy' => $copy, 'result' => $result, 'inspection' => $inspection] = backupRoundTrip($f);

    expect($copy->id)->not->toBe($f['company']->id)
        ->and($copy->legal_name)->toBe('Ferretería El Clavo (restaurada)')
        ->and($copy->license_id)->toBe($f['license']->id)
        ->and($inspection['manifest']['periodo']['texto'])->toBe('Todo el historial')
        ->and($result['dropped'])->toBe([]);

    expect(backupTrialBalance($copy))->toBe(backupTrialBalance($f['company']))
        ->and(backupOpenItems($copy))->toBe(backupOpenItems($f['company']))
        ->and(backupStockOf($copy))->toBe(backupStockOf($f['company']))
        ->and(backupVacations($copy))->toBe(2.0);

    // Los números de documento son los mismos, y la numeración sigue igual.
    $numbers = fn (Company $c) => JournalEntry::withoutGlobalScope(CompanyScope::class)->where('company_id', $c->id)->orderBy('id')->pluck('document_number')->all();
    expect($numbers($copy))->toBe($numbers($f['company']))
        ->and(DocumentType::withoutGlobalScope(CompanyScope::class)->where('company_id', $copy->id)->where('code', 'FVE')->value('next_consecutive'))
        ->toBe(DocumentType::withoutGlobalScope(CompanyScope::class)->where('company_id', $f['company']->id)->where('code', 'FVE')->value('next_consecutive'));

    // El Superusuario de la licencia entra a la compañía nueva.
    expect($f['superuser']->companies()->whereKey($copy->id)->exists())->toBeTrue();
});

it('desde una fecha: arranca con los saldos del día anterior y el resto da lo mismo', function () {
    $f = backupFixture();
    ['copy' => $copy, 'inspection' => $inspection] = backupRoundTrip($f, '2026-02-01');

    expect($inspection['manifest']['saldos_iniciales'])->toMatchArray(['open_items' => 1]);

    // Del 1 de febrero en adelante, el balance es el mismo: saldo inicial,
    // movimientos y saldo final.
    // Las partidas, con el mismo saldo. La de enero arranca con su saldo al
    // 31/01 y sin pagos en el período: en la copia está «abierta».
    $balances = fn (array $items) => array_map(fn (array $item) => [$item[0], $item[1]], $items);

    expect(backupTrialBalance($copy, '2026-02-01'))->toBe(backupTrialBalance($f['company'], '2026-02-01'))
        ->and($balances(backupOpenItems($copy)))->toBe($balances(backupOpenItems($f['company'])))
        ->and(backupStockOf($copy))->toBe(backupStockOf($f['company']))
        ->and(backupVacations($copy))->toBe(2.0);

    // El asiento de apertura, el 31 de enero, cuadrado.
    $opening = JournalEntry::withoutGlobalScope(CompanyScope::class)->where('company_id', $copy->id)->whereDate('posting_date', '2026-01-31')->sole();
    expect($opening->status)->toBe('posted')
        ->and((string) $opening->details()->sum('debit_local'))->toEqual((string) $opening->details()->sum('credit_local'))
        ->and(DocumentType::withoutGlobalScope(CompanyScope::class)->find($opening->document_type_id)->code)->toBe('APE');

    // Lo de antes del período no vino.
    expect(JournalEntry::withoutGlobalScope(CompanyScope::class)->where('company_id', $copy->id)->where('posting_date', '<', '2026-01-31')->exists())->toBeFalse();
});

it('un rango: el estado es el del último día, y lo de después no viene', function () {
    $f = backupFixture();
    ['copy' => $copy] = backupRoundTrip($f, '2026-02-01', '2026-03-31');

    expect(backupTrialBalance($copy, '2026-02-01', '2026-03-31'))->toBe(backupTrialBalance($f['company'], '2026-02-01', '2026-03-31'));

    // Al 31 de marzo: la primera factura con 600 (su saldo al 31/01, sin
    // pagos en el período) y la segunda entera (el cobro de 300 es de abril);
    // 6 unidades a 100.
    expect(backupOpenItems($copy))->toBe([[1, '600.00', 'open'], [2, '500.00', 'open']])
        ->and(backupStockOf($copy))->toBe([['ART-1', 6.0, 100.0]])
        ->and(JournalEntry::withoutGlobalScope(CompanyScope::class)->where('company_id', $copy->id)->where('posting_date', '>', '2026-03-31')->exists())->toBeFalse();
});

it('con otra contraseña, o con el archivo tocado, no se abre', function () {
    $f = backupFixture();
    $options = new BackupOptions($f['company'], ['accounting']);
    $path = tempnam(sys_get_temp_dir(), 'respaldo');
    $out = fopen($path, 'wb');
    BackupFile::write(app(BackupExporter::class)->lines($options, ['name' => 'P', 'email' => 'p@x.com', 'role' => 'Superusuario']), BACKUP_PASSWORD, ['company' => 'X', 'period' => 'Todo'], $out);
    fclose($out);

    expect(fn () => app(BackupImporter::class)->inspect($path, 'otra-contraseña'))
        ->toThrow(InvalidBackupException::class, 'La contraseña no es la de este respaldo');

    // Una letra cambiada en el contenido cifrado.
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    $last = count($lines) - 1;
    $lines[$last][10] = $lines[$last][10] === 'A' ? 'B' : 'A';
    file_put_contents($path, implode("\n", $lines)."\n");

    expect(fn () => app(BackupImporter::class)->inspect($path, BACKUP_PASSWORD))->toThrow(InvalidBackupException::class);
    unlink($path);
});

it('un respaldo de otra instalación (otra APP_KEY) no se importa', function () {
    $f = backupFixture();
    $options = new BackupOptions($f['company'], ['accounting']);
    $path = tempnam(sys_get_temp_dir(), 'respaldo');
    $out = fopen($path, 'wb');

    $realKey = config('app.key');
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    BackupFile::write(app(BackupExporter::class)->lines($options, ['name' => 'P', 'email' => 'p@x.com', 'role' => 'Superusuario']), BACKUP_PASSWORD, ['company' => 'X', 'period' => 'Todo'], $out);
    fclose($out);
    config(['app.key' => $realKey]);

    expect(fn () => app(BackupImporter::class)->inspect($path, BACKUP_PASSWORD))
        ->toThrow(InvalidBackupException::class, 'no lo generó esta instalación de CONTAPP');
    unlink($path);
});

it('las personas entran por su correo y la Bitácora se trae con sus referencias', function () {
    $f = backupFixture();
    $ana = User::factory()->create(['email' => 'ana@example.com']);
    $f['company']->users()->attach($ana->id, ['is_default' => false]);
    app(PermissionGrantService::class)->writeScreenLevels($f['company']->id, $ana, ['accounting.journal_entries' => 'read']);
    $role = Role::firstOrCreate(['company_id' => null, 'type' => 'user'], ['name' => 'Usuario', 'can_grant_permissions' => false]);
    DB::table('user_roles')->insert(['user_id' => $ana->id, 'role_id' => $role->id, 'company_id' => $f['company']->id]);
    AuditLog::create(['company_id' => $f['company']->id, 'user_id' => $ana->id, 'action' => AuditLog::ACTIVITY, 'screen' => 'business_partners.partners', 'auditable_type' => BusinessPartner::class, 'auditable_id' => $f['client']->id, 'subject' => 'CLI-0001', 'changes' => ['records' => [['t' => BusinessPartner::class, 'id' => $f['client']->id, 'a' => 'updated', 's' => 'CLI-0001', 'f' => ['gl_account_id' => [null, $f['accounts']['receivable']->id]]]], 'omitted' => []], 'created_at' => '2026-03-01 10:00:00']);

    ['copy' => $copy, 'result' => $result] = backupRoundTrip($f);

    expect($result['people']['added'])->toBe(['ana@example.com'])
        ->and(DB::table('screen_permissions')->where('company_id', $copy->id)->where('user_id', $ana->id)->value('access_level'))->toBe('read');

    $newPartner = BusinessPartner::withoutGlobalScope(CompanyScope::class)->where('company_id', $copy->id)->sole();
    $newAccount = ChartOfAccount::withoutGlobalScope(CompanyScope::class)->where('company_id', $copy->id)->where('code', '1-01-02-01-001')->value('id');
    $row = AuditLog::where('company_id', $copy->id)->where('action', AuditLog::ACTIVITY)->sole();

    expect($row->user_id)->toBe($ana->id)
        ->and($row->auditable_id)->toBe($newPartner->id)
        ->and($row->changes['records'][0]['id'])->toBe($newPartner->id)
        ->and($row->changes['records'][0]['f']['gl_account_id'][1])->toBe($newAccount);
});
