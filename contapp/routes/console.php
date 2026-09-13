<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Genera los borradores de las programaciones de asientos ("Programable")
// vencidas cada día. Declarar esto no basta por sí solo: requiere un cron
// real corriendo `php artisan schedule:run` cada minuto en el contenedor
// (este proyecto no tiene ese proceso corriendo todavía — ver
// docs/decisiones.md 2026-08-24) — mientras tanto, "Registros pendientes
// programados" también ofrece un botón para dispararlo a mano.
Schedule::command('contapp:process-journal-schedules')->daily();
