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
Schedule::command('pivox:process-journal-schedules')->daily();

// El tipo de cambio del día, del BCCR, para todas las compañías (el BCCR lo
// publica la tarde anterior). Dos veces: si a las 6 el BCCR no respondió, a
// la 1 se reintenta; repetirlo no cambia nada. También completa los días
// anteriores que falten. En Docker lo corre el servicio `scheduler`
// (schedule:work); en un servidor, el cron de `schedule:run` cada minuto.
// Los respaldos subidos que nadie confirmó: el archivo y su contraseña no
// quedan guardados más de un día (CLAUDE.md secc. 37).
Schedule::command('pivox:prune-backups')->hourly();

// Facturación electrónica (CLAUDE.md secc. 38): cada minuto se despacha a la
// cola «hacienda» lo que toca enviar, consultar o entregar. La máquina de
// estados deja la hora de cada uno en next_attempt_at; esto la cumple.
Schedule::command('pivox:hacienda-sync')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// Las llaves criptográficas de Hacienda por vencer: aviso al Superusuario a
// los 30, 15, 7 y 1 día.
Schedule::command('pivox:hacienda-certificates')
    ->dailyAt('07:00')
    ->timezone('America/Costa_Rica');

Schedule::command('pivox:sync-bccr-rates')
    ->twiceDaily(6, 13)
    ->timezone('America/Costa_Rica')
    ->withoutOverlapping();
