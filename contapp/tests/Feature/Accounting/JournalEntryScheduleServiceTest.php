<?php

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\JournalEntryScheduleService;
use App\Domains\Core\Scopes\CompanyScope;

// contappFixture() viene de PostJournalServiceTest.php (mismo directorio,
// Pest carga todas las funciones globales del suite junto) — company,
// cash, capital, documentType, fiscalYear, period listos para postear.

it('crea una programación activa con la fecha de inicio como próxima corrida', function () {
    $fx = contappFixture();
    $service = app(JournalEntryScheduleService::class);

    $schedule = $service->create(
        $fx['company'],
        $fx['documentType'],
        [new JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: 100, credit: 0, allowZeroAmount: true)],
        'months',
        1,
        new DateTime('2026-01-15'),
        new DateTime('2026-12-31'),
        'Depreciación mensual',
    );

    expect($schedule->status)->toBe('active')
        ->and($schedule->next_run_date->format('Y-m-d'))->toBe('2026-01-15')
        ->and($schedule->lines)->toHaveCount(1)
        ->and($schedule->lines[0]['account_id'])->toBe($fx['cash']->id);
});

it('rechaza una frecuencia inválida', function () {
    $fx = contappFixture();
    $service = app(JournalEntryScheduleService::class);

    $service->create(
        $fx['company'],
        $fx['documentType'],
        [new JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: 100, credit: 0, allowZeroAmount: true)],
        'weeks',
        1,
        new DateTime('2026-01-15'),
    );
})->throws(InvalidArgumentException::class);

it('rechaza un intervalo menor a 1', function () {
    $fx = contappFixture();
    $service = app(JournalEntryScheduleService::class);

    $service->create(
        $fx['company'],
        $fx['documentType'],
        [new JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: 100, credit: 0, allowZeroAmount: true)],
        'days',
        0,
        new DateTime('2026-01-15'),
    );
})->throws(InvalidArgumentException::class);

it('rechaza una fecha de vencimiento anterior a la fecha de inicio', function () {
    $fx = contappFixture();
    $service = app(JournalEntryScheduleService::class);

    $service->create(
        $fx['company'],
        $fx['documentType'],
        [new JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: 100, credit: 0, allowZeroAmount: true)],
        'days',
        1,
        new DateTime('2026-01-15'),
        new DateTime('2026-01-01'),
    );
})->throws(InvalidArgumentException::class);

it('processDue genera un borrador con schedule_id cuando la próxima corrida ya llegó', function () {
    $fx = contappFixture();
    $service = app(JournalEntryScheduleService::class);

    $schedule = $service->create(
        $fx['company'],
        $fx['documentType'],
        [new JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: 100, credit: 0, allowZeroAmount: true)],
        'days',
        15,
        new DateTime('2026-01-10'),
    );

    $generated = $service->processDue($fx['company'], new DateTime('2026-01-10'));

    expect($generated)->toHaveCount(1)
        ->and($generated[0]->status)->toBe('draft')
        ->and($generated[0]->schedule_id)->toBe($schedule->id)
        ->and($generated[0]->details)->toHaveCount(1);
});

it('processDue no genera nada si la próxima corrida todavía es en el futuro', function () {
    $fx = contappFixture();
    $service = app(JournalEntryScheduleService::class);

    $service->create(
        $fx['company'],
        $fx['documentType'],
        [new JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: 100, credit: 0, allowZeroAmount: true)],
        'days',
        15,
        new DateTime('2026-02-01'),
    );

    $generated = $service->processDue($fx['company'], new DateTime('2026-01-10'));

    expect($generated)->toHaveCount(0);
});

it('processDue avanza next_run_date en días según el intervalo configurado', function () {
    $fx = contappFixture();
    $service = app(JournalEntryScheduleService::class);

    $schedule = $service->create(
        $fx['company'],
        $fx['documentType'],
        [new JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: 100, credit: 0, allowZeroAmount: true)],
        'days',
        15,
        new DateTime('2026-01-10'),
    );

    $service->processDue($fx['company'], new DateTime('2026-01-10'));

    expect($schedule->fresh()->next_run_date->format('Y-m-d'))->toBe('2026-01-25');
});

it('processDue avanza next_run_date en meses según el intervalo configurado', function () {
    $fx = contappFixture();
    $service = app(JournalEntryScheduleService::class);

    $schedule = $service->create(
        $fx['company'],
        $fx['documentType'],
        [new JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: 100, credit: 0, allowZeroAmount: true)],
        'months',
        1,
        new DateTime('2026-01-31'),
    );

    $service->processDue($fx['company'], new DateTime('2026-01-31'));

    // Carbon::addMonths desde el 31 de enero cae en el último día de
    // febrero (no hay 31 de febrero) — comportamiento estándar de Carbon,
    // no un bug: se documenta con el propio valor esperado del test.
    expect($schedule->fresh()->next_run_date->format('Y-m-d'))->toBe('2026-02-28');
});

it('processDue marca la programación como expired cuando la próxima corrida supera el vencimiento', function () {
    $fx = contappFixture();
    $service = app(JournalEntryScheduleService::class);

    $schedule = $service->create(
        $fx['company'],
        $fx['documentType'],
        [new JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: 100, credit: 0, allowZeroAmount: true)],
        'days',
        10,
        new DateTime('2026-01-20'),
        new DateTime('2026-01-25'),
    );

    $service->processDue($fx['company'], new DateTime('2026-01-20'));

    $fresh = $schedule->fresh();
    expect($fresh->status)->toBe('expired')
        ->and($fresh->next_run_date->format('Y-m-d'))->toBe('2026-01-30');
});

it('cancel() marca la programación como cancelled y deja de generar borradores', function () {
    $fx = contappFixture();
    $service = app(JournalEntryScheduleService::class);

    $schedule = $service->create(
        $fx['company'],
        $fx['documentType'],
        [new JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: 100, credit: 0, allowZeroAmount: true)],
        'days',
        1,
        new DateTime('2026-01-10'),
    );

    $service->cancel($schedule);

    expect($schedule->fresh()->status)->toBe('cancelled')
        ->and($service->processDue($fx['company'], new DateTime('2026-01-10')))->toHaveCount(0);
});

it('rechaza cancelar una programación que no está activa', function () {
    $fx = contappFixture();
    $service = app(JournalEntryScheduleService::class);

    $schedule = $service->create(
        $fx['company'],
        $fx['documentType'],
        [new JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: 100, credit: 0, allowZeroAmount: true)],
        'days',
        1,
        new DateTime('2026-01-10'),
    );
    $service->cancel($schedule);

    $service->cancel($schedule->fresh());
})->throws(InvalidArgumentException::class);

it('processDueForAllCompanies procesa cada compañía por separado, sin depender de CurrentCompany ambiental', function () {
    $fxA = contappFixture();
    $fxB = contappFixture();
    $service = app(JournalEntryScheduleService::class);

    $service->create(
        $fxA['company'], $fxA['documentType'],
        [new JournalLineInput($fxA['cash']->id, $fxA['company']->local_currency_id, debit: 100, credit: 0, allowZeroAmount: true)],
        'days', 1, new DateTime('2026-01-10'),
    );
    $service->create(
        $fxB['company'], $fxB['documentType'],
        [new JournalLineInput($fxB['cash']->id, $fxB['company']->local_currency_id, debit: 200, credit: 0, allowZeroAmount: true)],
        'days', 1, new DateTime('2026-01-10'),
    );

    $results = $service->processDueForAllCompanies(new DateTime('2026-01-10'));

    expect($results[$fxA['company']->id])->toHaveCount(1)
        ->and($results[$fxB['company']->id])->toHaveCount(1)
        ->and(JournalEntry::withoutGlobalScope(CompanyScope::class)->where('company_id', $fxA['company']->id)->count())->toBe(1)
        ->and(JournalEntry::withoutGlobalScope(CompanyScope::class)->where('company_id', $fxB['company']->id)->count())->toBe(1);
});
