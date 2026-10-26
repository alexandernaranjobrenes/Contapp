<?php

use Illuminate\Console\Scheduling\Schedule;

/*
|--------------------------------------------------------------------------
| Las colas desde el programador (Hostinger, docs/produccion.md)
|--------------------------------------------------------------------------
|
| En el hosting compartido no puede quedar un worker corriendo: con
| QUEUE_WORKERS_IN_SCHEDULER, el programador arranca uno por cola cada
| minuto. En Docker cada cola tiene su servicio y no corren.
|
*/

function queueWorkerEvents(): array
{
    return collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains((string) $event->command, 'queue:work'))
        ->mapWithKeys(fn ($event) => [preg_match('/--queue=(\w+)/', $event->command, $match) ? $match[1] : '?' => $event])
        ->all();
}

it('hay un worker por cola, cada minuto, de a uno y en segundo plano', function () {
    $events = queueWorkerEvents();

    expect(array_keys($events))->toEqualCanonicalizing(['hacienda', 'default', 'backups']);

    foreach ($events as $event) {
        expect($event->expression)->toBe('* * * * *')
            ->and($event->withoutOverlapping)->toBeTrue()
            ->and($event->runInBackground)->toBeTrue()
            ->and($event->command)->toContain('--stop-when-empty')->toContain('--max-time=50');
    }

    // Un respaldo grande puede tardar una hora: su candado dura más.
    expect($events['backups']->expiresAt)->toBe(70)
        ->and($events['hacienda']->expiresAt)->toBe(10);
});

it('solo corren con QUEUE_WORKERS_IN_SCHEDULER encendido', function (bool $enabled) {
    config(['queue.workers_in_scheduler' => $enabled]);

    foreach (queueWorkerEvents() as $event) {
        expect($event->filtersPass(app()))->toBe($enabled);
    }
})->with([
    'en Hostinger' => true,
    'en Docker' => false,
]);
