<?php

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Accounting\Models\FiscalPeriod;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Reports\AccountingReportRegistry;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Reporting\Reports\ReportColumn;
use App\Domains\Reporting\Reports\ReportResult;

/**
 * Los libros legales: mayor y diario.
 *
 * Fixture propio del archivo: los helpers de Pest comparten un espacio de
 * nombres global. Se contabilizan asientos de verdad —no se insertan filas a
 * mano— porque lo que estos reportes tienen que acertar es justamente cómo
 * leer lo que el motor dejó.
 */
function booksFixture(): array
{
    ['user' => $user, 'company' => $company] = logInAsCompanyUser();

    ExchangeRate::factory()->create([
        'company_id' => $company->id,
        'currency_id' => $company->foreign_currency_id,
        'rate_date' => '2026-01-01',
        'rate' => '520.000000',
    ]);

    // Una de activo (naturaleza deudora) y una de pasivo (acreedora): el
    // signo del saldo depende de eso y es lo que más se equivoca.
    $banco = ChartOfAccount::factory()->create([
        'company_id' => $company->id, 'code' => '1-01-01-01-001',
        'account_type' => 'asset', 'normal_balance' => 'debit', 'accepts_posting' => true,
    ]);
    $proveedores = ChartOfAccount::factory()->create([
        'company_id' => $company->id, 'code' => '2-01-01-01-001',
        'account_type' => 'liability', 'normal_balance' => 'credit', 'accepts_posting' => true,
    ]);

    $add = DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'ADD']);

    $fiscalYear = FiscalYear::factory()->create(['company_id' => $company->id, 'year' => 2026]);

    foreach (range(1, 12) as $month) {
        FiscalPeriod::factory()->create([
            'fiscal_year_id' => $fiscalYear->id,
            'period_number' => $month,
            'start_date' => "2026-{$month}-01",
            'end_date' => date('Y-m-t', strtotime("2026-{$month}-01")),
            'status' => 'open',
        ]);
    }

    $service = app(PostJournalService::class);

    // Enero: lo que va a quedar como SALDO ANTERIOR de un reporte de febrero.
    $service->post($company, $add, new DateTime('2026-01-10'), new DateTime('2026-01-10'), [
        new JournalLineInput($banco->id, $company->local_currency_id, debit: 1000, credit: 0),
        new JournalLineInput($proveedores->id, $company->local_currency_id, debit: 0, credit: 1000),
    ], 'Compra a crédito de enero');

    // Febrero: dos movimientos dentro del rango que se va a consultar.
    $service->post($company, $add, new DateTime('2026-02-05'), new DateTime('2026-02-05'), [
        new JournalLineInput($banco->id, $company->local_currency_id, debit: 500, credit: 0),
        new JournalLineInput($proveedores->id, $company->local_currency_id, debit: 0, credit: 500),
    ], 'Compra de febrero');

    $service->post($company, $add, new DateTime('2026-02-20'), new DateTime('2026-02-20'), [
        new JournalLineInput($proveedores->id, $company->local_currency_id, debit: 300, credit: 0),
        new JournalLineInput($banco->id, $company->local_currency_id, debit: 0, credit: 300),
    ], 'Pago a proveedor');

    return compact('user', 'company', 'banco', 'proveedores', 'add');
}

function runBookReport(string $code, array $fx, array $filters = []): ReportResult
{
    $report = app(AccountingReportRegistry::class)->find($code);

    $resolved = [];

    foreach ($report->filters() as $filter) {
        $resolved[$filter->key] = $filters[$filter->key] ?? match ($filter->default) {
            'today' => now()->format('Y-m-d'),
            'first_day_of_month' => now()->startOfMonth()->format('Y-m-d'),
            'first_day_of_year' => now()->startOfYear()->format('Y-m-d'),
            default => $filter->default,
        };
    }

    return $report->build($fx['company'], $resolved);
}

// ── El contrato, sobre los dos de una vez ───────────────────────────────

it('EL CONTRATO: los dos libros corren y devuelven una estructura válida', function () {
    $fx = booksFixture();

    foreach (app(AccountingReportRegistry::class)->all() as $report) {
        $result = runBookReport($report->code(), $fx, ['from' => '2026-01-01', 'to' => '2026-12-31']);

        expect($report->code())->not->toBeEmpty()
            ->and($report->label())->not->toBeEmpty()
            ->and($report->decision())->not->toBeEmpty()
            ->and($report->group())->toBeIn(AccountingReportRegistry::GROUP_ORDER)
            ->and($result->columns)->not->toBeEmpty()
            ->and($result->rows)->not->toBeEmpty();

        foreach ($result->columns as $column) {
            expect($column)->toBeInstanceOf(ReportColumn::class)
                ->and($column->label)->not->toBeEmpty();
        }
    }
});

it('los dos libros se consultan y salen a XLSX, PDF e impresión', function () {
    booksFixture();

    foreach (app(AccountingReportRegistry::class)->all() as $report) {
        $this->get(route('accounting-reports.show', $report->code()))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Accounting/Reports/Show')
                ->where('report.code', $report->code())
                ->has('columns')
            );

        $xlsx = $this->get(route('accounting-reports.export', $report->code()));
        $xlsx->assertOk();
        expect($xlsx->headers->get('Content-Disposition'))->toContain($report->code().'.xlsx');

        $pdf = $this->get(route('accounting-reports.export-pdf', $report->code()));
        $pdf->assertOk();
        expect($pdf->headers->get('Content-Type'))->toBe('application/pdf');

        $print = $this->get(route('accounting-reports.print', $report->code()));
        $print->assertOk();
        expect($print->headers->get('Content-Disposition'))->toContain('inline');
    }
});

it('un libro que no existe da 404', function () {
    booksFixture();

    $this->get(route('accounting-reports.show', 'no-existe'))->assertNotFound();
});

it('el índice enlaza los reportes financieros que ya tenían pantalla', function () {
    booksFixture();

    $this->get(route('accounting-reports.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Accounting/Reports/Index')
            ->has('groups')
            ->has('related', 4)
        );
});

// ── Libro mayor ─────────────────────────────────────────────────────────

it('EL SALDO ANTERIOR: el mayor arranca cada cuenta con lo que traía', function () {
    $fx = booksFixture();

    $rows = runBookReport('general-ledger', $fx, ['from' => '2026-02-01', 'to' => '2026-02-28'])->rows;

    $banco = array_values(array_filter($rows, fn ($r) => $r['account_code'] === '1-01-01-01-001'));

    // Primera fila: el saldo de enero, sin débito ni crédito propios.
    expect($banco[0]['description'])->toBe('Saldo anterior')
        ->and($banco[0]['balance'])->toBe(1000.0)
        ->and($banco[0]['debit'])->toBeNull()
        ->and($banco[0]['credit'])->toBeNull();

    // Y después los movimientos de febrero, con el saldo corriendo encima.
    expect($banco[1]['debit'])->toBe(500.0)
        ->and($banco[1]['balance'])->toBe(1500.0)
        ->and($banco[2]['credit'])->toBe(300.0)
        ->and($banco[2]['balance'])->toBe(1200.0);
});

it('EL SIGNO: una cuenta de pasivo suma con el crédito, no con el débito', function () {
    $fx = booksFixture();

    $rows = runBookReport('general-ledger', $fx, ['from' => '2026-02-01', 'to' => '2026-02-28'])->rows;

    $proveedores = array_values(array_filter($rows, fn ($r) => $r['account_code'] === '2-01-01-01-001'));

    // Saldo anterior acreedor de 1000. Un crédito de 500 lo SUBE a 1500 y un
    // débito de 300 lo BAJA a 1200. Con débito−crédito a secas, el pasivo
    // saldría en negativo y el mayor sería ilegible.
    expect($proveedores[0]['balance'])->toBe(1000.0)
        ->and($proveedores[1]['credit'])->toBe(500.0)
        ->and($proveedores[1]['balance'])->toBe(1500.0)
        ->and($proveedores[2]['debit'])->toBe(300.0)
        ->and($proveedores[2]['balance'])->toBe(1200.0);
});

it('EL ANULADO: el mayor cuenta el asiento anulado y su reversión, que netean en cero', function () {
    $fx = booksFixture();

    $erroneo = app(PostJournalService::class)->post(
        $fx['company'], $fx['add'], new DateTime('2026-02-10'), new DateTime('2026-02-10'),
        [
            new JournalLineInput($fx['banco']->id, $fx['company']->local_currency_id, debit: 900, credit: 0),
            new JournalLineInput($fx['proveedores']->id, $fx['company']->local_currency_id, debit: 0, credit: 900),
        ],
        'Asiento por error'
    );

    app(PostJournalService::class)->reverse($fx['company'], $erroneo->fresh(), new DateTime('2026-02-11'));

    $rows = runBookReport('general-ledger', $fx, ['from' => '2026-02-01', 'to' => '2026-02-28'])->rows;

    $banco = array_values(array_filter($rows, fn ($r) => $r['account_code'] === '1-01-01-01-001'));

    // El saldo final es el mismo que sin el par: 1000 + 500 − 300 = 1200.
    // Si entrara solo la reversión, saldría 300.
    expect(end($banco)['balance'])->toBe(1200.0);
});

it('el mayor filtra por cuenta y por rango de códigos', function () {
    $fx = booksFixture();

    $unaCuenta = runBookReport('general-ledger', $fx, [
        'from' => '2026-01-01', 'to' => '2026-12-31', 'account_id' => $fx['banco']->id,
    ])->rows;

    expect(array_unique(array_column($unaCuenta, 'account_code')))->toBe(['1-01-01-01-001']);

    // Por rango de códigos: todo el activo (1-…) y nada del pasivo (2-…).
    $soloActivo = runBookReport('general-ledger', $fx, [
        'from' => '2026-01-01', 'to' => '2026-12-31', 'account_from' => '1', 'account_to' => '1-zzz',
    ])->rows;

    expect(array_unique(array_column($soloActivo, 'account_code')))->toBe(['1-01-01-01-001']);
});

it('el mayor filtra por documento', function () {
    $fx = booksFixture();

    $otroTipo = DocumentType::factory()->create(['company_id' => $fx['company']->id, 'code' => 'XYZ']);

    app(PostJournalService::class)->post(
        $fx['company'], $otroTipo, new DateTime('2026-02-15'), new DateTime('2026-02-15'),
        [
            new JournalLineInput($fx['banco']->id, $fx['company']->local_currency_id, debit: 77, credit: 0),
            new JournalLineInput($fx['proveedores']->id, $fx['company']->local_currency_id, debit: 0, credit: 77),
        ],
        'Documento de otro tipo'
    );

    $rows = runBookReport('general-ledger', $fx, [
        'from' => '2026-02-01', 'to' => '2026-02-28', 'document_type_id' => $otroTipo->id,
    ])->rows;

    $conMovimiento = array_filter($rows, fn ($r) => $r['debit'] !== null || $r['credit'] !== null);
    $documentos = array_unique(array_column($conMovimiento, 'document'));

    expect($documentos)->each->toContain('XYZ-');
});

// ── Libro diario ────────────────────────────────────────────────────────

it('EL DIARIO CUADRA: el total de débitos es igual al de créditos', function () {
    $fx = booksFixture();

    $result = runBookReport('journal-book', $fx, ['from' => '2026-01-01', 'to' => '2026-12-31']);
    $totals = $result->computedTotals();

    expect(round($totals['debit'], 2))->toBe(round($totals['credit'], 2))
        ->and($totals['debit'])->toBeGreaterThan(0);
});

it('EL ASIENTO COMPLETO: filtrar por cuenta trae también las líneas de la otra', function () {
    $fx = booksFixture();

    $rows = runBookReport('journal-book', $fx, [
        'from' => '2026-01-01', 'to' => '2026-12-31', 'account_id' => $fx['banco']->id,
    ])->rows;

    // Se filtró por banco, pero las contrapartidas de proveedores vienen: si
    // no, los asientos no cuadrarían en pantalla y el libro dejaría de serlo.
    $cuentas = array_unique(array_column($rows, 'account_code'));

    expect($cuentas)->toContain('1-01-01-01-001')
        ->and($cuentas)->toContain('2-01-01-01-001');

    // Y sigue cuadrando.
    $debitos = array_sum(array_column($rows, 'debit'));
    $creditos = array_sum(array_column($rows, 'credit'));

    expect(round($debitos, 2))->toBe(round($creditos, 2));
});

it('el diario marca los asientos anulados y deja excluirlos', function () {
    $fx = booksFixture();

    $erroneo = app(PostJournalService::class)->post(
        $fx['company'], $fx['add'], new DateTime('2026-03-10'), new DateTime('2026-03-10'),
        [
            new JournalLineInput($fx['banco']->id, $fx['company']->local_currency_id, debit: 900, credit: 0),
            new JournalLineInput($fx['proveedores']->id, $fx['company']->local_currency_id, debit: 0, credit: 900),
        ],
        'Asiento por error'
    );

    app(PostJournalService::class)->reverse($fx['company'], $erroneo->fresh(), new DateTime('2026-03-11'));

    $conAnulados = runBookReport('journal-book', $fx, [
        'from' => '2026-03-01', 'to' => '2026-03-31', 'include_voided' => true,
    ])->rows;

    expect(array_column($conAnulados, 'status'))->toContain('ANULADO');

    $sinAnulados = runBookReport('journal-book', $fx, [
        'from' => '2026-03-01', 'to' => '2026-03-31', 'include_voided' => false,
    ])->rows;

    expect(array_column($sinAnulados, 'status'))->not->toContain('ANULADO')
        // Y avisa que al excluirlos el libro deja de cuadrar contra el balance.
        ->and(count($sinAnulados))->toBeLessThan(count($conAnulados));
});

it('el diario ordena por fecha y mantiene juntas las líneas de cada asiento', function () {
    $fx = booksFixture();

    $rows = runBookReport('journal-book', $fx, ['from' => '2026-01-01', 'to' => '2026-12-31'])->rows;

    $fechas = array_column($rows, 'date');
    $ordenadas = $fechas;
    sort($ordenadas);

    expect($fechas)->toBe($ordenadas);

    // Las líneas de un asiento nunca aparecen separadas por las de otro.
    $vistos = [];
    $anterior = null;

    foreach (array_column($rows, 'entry') as $entry) {
        if ($entry !== $anterior) {
            expect($vistos)->not->toContain($entry);
            $vistos[] = $entry;
            $anterior = $entry;
        }
    }
});

it('ningún libro cruza compañías', function () {
    $fx = booksFixture();
    $otra = booksFixture();

    // La segunda sesión quedó activa: su mayor solo trae sus propias cuentas.
    $rows = runBookReport('general-ledger', $otra, ['from' => '2026-01-01', 'to' => '2026-12-31'])->rows;

    expect($rows)->not->toBeEmpty()
        ->and($fx['banco']->id)->not->toBe($otra['banco']->id);

    $diario = runBookReport('journal-book', $otra, ['from' => '2026-01-01', 'to' => '2026-12-31'])->rows;

    expect(count($diario))->toBe(6);
});
