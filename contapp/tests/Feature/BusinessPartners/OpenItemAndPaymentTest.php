<?php

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Accounting\Models\FiscalPeriod;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\BusinessPartners\Exceptions\InvalidNettingLineException;
use App\Domains\BusinessPartners\Exceptions\OpenItemAlreadyClosedException;
use App\Domains\BusinessPartners\Exceptions\OpenItemOverpaymentException;
use App\Domains\BusinessPartners\Exceptions\UnbalancedOpenItemNettingException;
use App\Domains\BusinessPartners\Models\BpOpenItem;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\BusinessPartners\Services\ApplyPaymentService;
use App\Domains\BusinessPartners\Services\OpenItemNettingService;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;

function cxcFixture(): array
{
    $company = Company::factory()->create();

    ExchangeRate::factory()->create([
        'company_id' => $company->id,
        'currency_id' => $company->foreign_currency_id,
        'rate_date' => '2026-01-01',
        'rate' => '520.000000',
    ]);

    $cash = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '1-01-01-01-001']);
    $cxc = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '1-01-02-01-001']);
    $sales = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '4-01-01-01-001']);

    $client = BusinessPartner::factory()->create([
        'company_id' => $company->id,
        'gl_account_id' => $cxc->id,
        'currency_id' => $company->local_currency_id,
    ]);

    $fve = DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'FVE', 'origin_module' => 'ventas']);
    $trb = DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'TRB', 'origin_module' => 'bancos']);

    $fiscalYear = FiscalYear::factory()->create(['company_id' => $company->id, 'year' => 2026]);
    FiscalPeriod::factory()->create([
        'fiscal_year_id' => $fiscalYear->id,
        'period_number' => 1,
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
        'status' => 'open',
    ]);

    return compact('company', 'cash', 'cxc', 'sales', 'client', 'fve', 'trb');
}

function postInvoice(array $fx, string $amount = '1000'): \App\Domains\Accounting\Models\JournalEntry
{
    return app(PostJournalService::class)->post(
        $fx['company'],
        $fx['fve'],
        new DateTime('2026-01-05'),
        new DateTime('2026-01-05'),
        [
            new JournalLineInput(
                $fx['cxc']->id,
                $fx['company']->local_currency_id,
                debit: $amount,
                credit: 0,
                businessPartnerId: $fx['client']->id,
                dueDate: '2026-02-04',
                opensItem: true,
            ),
            new JournalLineInput($fx['sales']->id, $fx['company']->local_currency_id, debit: 0, credit: $amount),
        ],
        'Factura de venta'
    );
}

it('abre una partida pendiente al contabilizar una línea con opensItem', function () {
    $fx = cxcFixture();

    postInvoice($fx);

    $openItem = BpOpenItem::sole();

    expect($openItem->business_partner_id)->toBe($fx['client']->id)
        ->and($openItem->document_type_code)->toBe('FVE')
        ->and($openItem->original_amount)->toEqual('1000.00')
        ->and($openItem->balance)->toEqual('1000.00')
        ->and($openItem->status)->toBe('open');
});

it('cierra totalmente una partida al aplicar un pago por el monto completo', function () {
    $fx = cxcFixture();
    postInvoice($fx);
    $openItem = BpOpenItem::sole();

    $payment = app(PostJournalService::class)->post(
        $fx['company'],
        $fx['trb'],
        new DateTime('2026-01-20'),
        new DateTime('2026-01-20'),
        [
            new JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: 1000, credit: 0),
            new JournalLineInput($fx['cxc']->id, $fx['company']->local_currency_id, debit: 0, credit: 1000),
        ],
        'Cobro de factura'
    );

    app(ApplyPaymentService::class)->apply($openItem, $payment, 1000, new DateTime('2026-01-20'));

    $openItem->refresh();

    expect($openItem->applied_amount)->toEqual('1000.00')
        ->and($openItem->balance)->toEqual('0.00')
        ->and($openItem->status)->toBe('closed');
});

it('deja una partida parcial cuando el pago es menor al saldo, y la cierra al completar el resto', function () {
    $fx = cxcFixture();
    postInvoice($fx);
    $openItem = BpOpenItem::sole();

    $payment = app(PostJournalService::class)->post(
        $fx['company'], $fx['trb'], new DateTime('2026-01-20'), new DateTime('2026-01-20'),
        [
            new JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: 400, credit: 0),
            new JournalLineInput($fx['cxc']->id, $fx['company']->local_currency_id, debit: 0, credit: 400),
        ],
    );

    $service = app(ApplyPaymentService::class);
    $service->apply($openItem, $payment, 400, new DateTime('2026-01-20'));
    $openItem->refresh();

    expect($openItem->balance)->toEqual('600.00')->and($openItem->status)->toBe('partial');

    $service->apply($openItem, $payment, 600, new DateTime('2026-01-25'));
    $openItem->refresh();

    expect($openItem->balance)->toEqual('0.00')->and($openItem->status)->toBe('closed');
});

it('rechaza aplicar un monto mayor al saldo pendiente', function () {
    $fx = cxcFixture();
    postInvoice($fx);
    $openItem = BpOpenItem::sole();

    $payment = app(PostJournalService::class)->post(
        $fx['company'], $fx['trb'], new DateTime('2026-01-20'), new DateTime('2026-01-20'),
        [
            new JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: 1500, credit: 0),
            new JournalLineInput($fx['cxc']->id, $fx['company']->local_currency_id, debit: 0, credit: 1500),
        ],
    );

    app(ApplyPaymentService::class)->apply($openItem, $payment, 1500, new DateTime('2026-01-20'));
})->throws(OpenItemOverpaymentException::class);

it('rechaza aplicar pagos a una partida ya cerrada', function () {
    $fx = cxcFixture();
    postInvoice($fx);
    $openItem = BpOpenItem::sole();

    $payment = app(PostJournalService::class)->post(
        $fx['company'], $fx['trb'], new DateTime('2026-01-20'), new DateTime('2026-01-20'),
        [
            new JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: 1000, credit: 0),
            new JournalLineInput($fx['cxc']->id, $fx['company']->local_currency_id, debit: 0, credit: 1000),
        ],
    );

    $service = app(ApplyPaymentService::class);
    $service->apply($openItem, $payment, 1000, new DateTime('2026-01-20'));

    $service->apply($openItem, $payment, 1, new DateTime('2026-01-21'));
})->throws(OpenItemAlreadyClosedException::class);

it('calcula el diferencial cambiario realizado al cobrar en moneda extranjera con otro tipo de cambio', function () {
    $fx = cxcFixture();

    $entry = app(PostJournalService::class)->post(
        $fx['company'], $fx['fve'], new DateTime('2026-01-05'), new DateTime('2026-01-05'),
        [
            new JournalLineInput(
                $fx['cxc']->id,
                $fx['company']->foreign_currency_id,
                debit: 100,
                credit: 0,
                businessPartnerId: $fx['client']->id,
                dueDate: '2026-02-04',
                opensItem: true,
            ),
            new JournalLineInput($fx['sales']->id, $fx['company']->foreign_currency_id, debit: 0, credit: 100),
        ],
    );

    $openItem = BpOpenItem::sole();
    expect($openItem->currency_id)->toBe($fx['company']->foreign_currency_id);

    ExchangeRate::factory()->create([
        'company_id' => $fx['company']->id,
        'currency_id' => $fx['company']->foreign_currency_id,
        'rate_date' => '2026-01-25',
        'rate' => '530.000000',
    ]);

    $payment = app(PostJournalService::class)->post(
        $fx['company'], $fx['trb'], new DateTime('2026-01-25'), new DateTime('2026-01-25'),
        [
            new JournalLineInput($fx['cash']->id, $fx['company']->foreign_currency_id, debit: 100, credit: 0),
            new JournalLineInput($fx['cxc']->id, $fx['company']->foreign_currency_id, debit: 0, credit: 100),
        ],
    );

    $application = app(ApplyPaymentService::class)->apply(
        $openItem, $payment, 100, new DateTime('2026-01-25'), exchangeRate: '530.000000'
    );

    expect($application->realized_fx_difference)->toEqual('1000.00');
});

// --- Reconciliación interna de partidas (OpenItemNettingService) ---------

// Simula el caso real que motivó esto: una factura (débito, abre partida) y
// una "nota de crédito"/pago que ya se había contabilizado directo contra la
// misma cuenta de control con su PROPIA partida (crédito, abre partida) —
// en vez de aplicarse formalmente vía ApplyPaymentService. Ambas quedan
// abiertas por el mismo monto, en sentido contrario.
function postOffsettingCredit(array $fx, string $amount, string $dueDate = '2026-02-05'): void
{
    app(PostJournalService::class)->post(
        $fx['company'], $fx['fve'], new DateTime('2026-01-06'), new DateTime('2026-01-06'),
        [
            new JournalLineInput($fx['sales']->id, $fx['company']->local_currency_id, debit: $amount, credit: 0),
            new JournalLineInput(
                $fx['cxc']->id, $fx['company']->local_currency_id, debit: 0, credit: $amount,
                businessPartnerId: $fx['client']->id, dueDate: $dueDate, opensItem: true,
            ),
        ],
        'Movimiento en sentido contrario'
    );
}

it('reconcilia entre sí dos partidas del mismo socio cuyo neto con signo da exactamente cero', function () {
    $fx = cxcFixture();
    postInvoice($fx, '1000');
    postOffsettingCredit($fx, '1000');

    $items = BpOpenItem::where('business_partner_id', $fx['client']->id)->get();
    expect($items)->toHaveCount(2);

    $reconciliation = app(OpenItemNettingService::class)->reconcile($items->pluck('id')->all());

    $closed = BpOpenItem::whereIn('id', $items->pluck('id'))->get();
    expect($closed->every(fn ($i) => $i->status === 'closed' && $i->balance === '0.00'))->toBeTrue()
        ->and($closed->every(fn ($i) => $i->applied_amount === $i->original_amount))->toBeTrue()
        ->and($reconciliation->business_partner_id)->toBe($fx['client']->id)
        ->and($reconciliation->lines)->toHaveCount(2);
});

it('rechaza reconciliar cuando el neto con signo no da cero', function () {
    $fx = cxcFixture();
    postInvoice($fx, '1000');
    postOffsettingCredit($fx, '400');

    $items = BpOpenItem::where('business_partner_id', $fx['client']->id)->get();

    app(OpenItemNettingService::class)->reconcile($items->pluck('id')->all());
})->throws(UnbalancedOpenItemNettingException::class);

it('rechaza reconciliar partidas de socios distintos', function () {
    $fx = cxcFixture();
    postInvoice($fx, '1000');

    $otherClient = BusinessPartner::factory()->create([
        'company_id' => $fx['company']->id, 'gl_account_id' => $fx['cxc']->id, 'currency_id' => $fx['company']->local_currency_id,
    ]);
    app(PostJournalService::class)->post(
        $fx['company'], $fx['fve'], new DateTime('2026-01-06'), new DateTime('2026-01-06'),
        [
            new JournalLineInput($fx['sales']->id, $fx['company']->local_currency_id, debit: 1000, credit: 0),
            new JournalLineInput(
                $fx['cxc']->id, $fx['company']->local_currency_id, debit: 0, credit: 1000,
                businessPartnerId: $otherClient->id, dueDate: '2026-02-05', opensItem: true,
            ),
        ],
    );

    $items = BpOpenItem::all();
    expect($items)->toHaveCount(2);

    app(OpenItemNettingService::class)->reconcile($items->pluck('id')->all());
})->throws(InvalidNettingLineException::class);

it('rechaza reconciliar una partida ya cerrada', function () {
    $fx = cxcFixture();
    postInvoice($fx, '1000');
    postOffsettingCredit($fx, '1000');

    $items = BpOpenItem::where('business_partner_id', $fx['client']->id)->get();
    $items->first()->update(['status' => 'closed', 'balance' => '0.00', 'applied_amount' => $items->first()->original_amount]);

    app(OpenItemNettingService::class)->reconcile($items->pluck('id')->all());
})->throws(InvalidNettingLineException::class);

it('rechaza reconciliar menos de dos partidas', function () {
    $fx = cxcFixture();
    postInvoice($fx, '1000');
    $item = BpOpenItem::where('business_partner_id', $fx['client']->id)->sole();

    app(OpenItemNettingService::class)->reconcile([$item->id]);
})->throws(InvalidNettingLineException::class);
