<?php

use App\Http\Controllers\Api\Conti\ActionController;
use App\Http\Controllers\Api\Conti\ContextController;
use App\Http\Controllers\Api\Conti\RecordController;
use App\Http\Controllers\Api\Conti\ReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API de Conti (CLAUDE.md secc. 32)
|--------------------------------------------------------------------------
|
| La consulta el agente de n8n con el pase que CONTAPP le manda en cada
| mensaje (AuthenticateContiToken). Sin sesión ni CSRF: no es el navegador.
| Todo responde JSON, también los errores (bootstrap/app.php: api/*).
|
*/

Route::prefix('conti')
    ->middleware(['conti-token', 'throttle:conti-api'])
    ->name('api.conti.')
    ->group(function () {
        Route::get('contexto', [ContextController::class, 'show'])->name('context');
        Route::get('datos/{resource}', [RecordController::class, 'show'])->name('records');
        Route::get('reportes/{report}', [ReportController::class, 'show'])->name('reports');
        Route::post('acciones', [ActionController::class, 'store'])->name('actions.store');
        Route::get('acciones/{uuid}', [ActionController::class, 'show'])->name('actions.show');
    });
