<?php

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\JournalEntrySchedule;
use App\Domains\Accounting\Services\JournalEntryScheduleService;
use App\Domains\Core\Scopes\CompanyScope;

// journalHttpFixture() viene de JournalEntryHttpTest.php (mismo directorio,
// Pest carga todas las funciones globales del suite junto): user, company,
// cash, capital, add (DocumentType) listos, con TC y período fiscal abierto.

it('lista las programaciones activas y los borradores pendientes de revisión', function () {
    $fx = journalHttpFixture();

    $this->post(route('journal-entry-schedules.store'), [
        'document_type_id' => $fx['add']->id,
        'description' => 'Depreciación mensual',
        'frequency_type' => 'months',
        'interval_count' => 1,
        'start_date' => now()->subDay()->format('Y-m-d'),
        'lines' => [
            ['account_id' => $fx['cash']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 100, 'credit' => 0],
        ],
    ])->assertRedirect(route('journal-entry-schedules.index'));

    $schedule = JournalEntrySchedule::sole();

    $this->post(route('journal-entry-schedules.process-now'));

    $this->get(route('journal-entry-schedules.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('JournalEntrySchedules/Index')
            ->has('schedules', 1)
            ->where('schedules.0.id', $schedule->id)
            ->has('pendingEntries', 1)
            ->where('pendingEntries.0.schedule_id', $schedule->id)
        );
});

it('crea una programación y no contabiliza nada todavía', function () {
    $fx = journalHttpFixture();

    $this->post(route('journal-entry-schedules.store'), [
        'document_type_id' => $fx['add']->id,
        'frequency_type' => 'days',
        'interval_count' => 30,
        'start_date' => now()->addMonth()->format('Y-m-d'),
        'expires_at' => now()->addYear()->format('Y-m-d'),
        'lines' => [
            ['account_id' => $fx['cash']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 500, 'credit' => 0],
        ],
    ])->assertRedirect(route('journal-entry-schedules.index'));

    $schedule = JournalEntrySchedule::sole();
    expect($schedule->status)->toBe('active')
        ->and($schedule->frequency_type)->toBe('days')
        ->and($schedule->interval_count)->toBe(30)
        ->and(JournalEntry::withoutGlobalScope(CompanyScope::class)->count())->toBe(0);
});

it('rechaza crear una programación con una frecuencia inválida', function () {
    $fx = journalHttpFixture();

    $this->post(route('journal-entry-schedules.store'), [
        'document_type_id' => $fx['add']->id,
        'frequency_type' => 'weeks',
        'interval_count' => 1,
        'start_date' => now()->format('Y-m-d'),
        'lines' => [
            ['account_id' => $fx['cash']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 100, 'credit' => 0],
        ],
    ])->assertSessionHasErrors('frequency_type');

    expect(JournalEntrySchedule::count())->toBe(0);
});

it('"procesar ahora" genera un borrador preliminar cuando la próxima corrida ya llegó', function () {
    $fx = journalHttpFixture();
    $service = app(JournalEntryScheduleService::class);
    $schedule = $service->create(
        $fx['company'],
        $fx['add'],
        [new JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: 100, credit: 0, allowZeroAmount: true)],
        'days',
        1,
        new DateTime(now()->subDay()->format('Y-m-d')),
    );

    $this->post(route('journal-entry-schedules.process-now'))
        ->assertRedirect()
        ->assertSessionHas('success');

    $entry = JournalEntry::withoutGlobalScope(CompanyScope::class)->sole();
    expect($entry->status)->toBe('draft')
        ->and($entry->schedule_id)->toBe($schedule->id);
});

it('cancela una programación activa, y "procesar ahora" ya no la toca', function () {
    $fx = journalHttpFixture();
    $service = app(JournalEntryScheduleService::class);
    $schedule = $service->create(
        $fx['company'],
        $fx['add'],
        [new JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: 100, credit: 0, allowZeroAmount: true)],
        'days',
        1,
        new DateTime(now()->subDay()->format('Y-m-d')),
    );

    $this->post(route('journal-entry-schedules.cancel', $schedule->id))->assertRedirect();
    expect($schedule->fresh()->status)->toBe('cancelled');

    $this->post(route('journal-entry-schedules.process-now'));
    expect(JournalEntry::withoutGlobalScope(CompanyScope::class)->count())->toBe(0);
});

it('rechaza cancelar una programación que ya no está activa', function () {
    $fx = journalHttpFixture();
    $service = app(JournalEntryScheduleService::class);
    $schedule = $service->create(
        $fx['company'],
        $fx['add'],
        [new JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: 100, credit: 0, allowZeroAmount: true)],
        'days',
        1,
        new DateTime(now()->format('Y-m-d')),
    );
    $service->cancel($schedule);

    $this->post(route('journal-entry-schedules.cancel', $schedule->id))->assertSessionHasErrors('schedule');
});
