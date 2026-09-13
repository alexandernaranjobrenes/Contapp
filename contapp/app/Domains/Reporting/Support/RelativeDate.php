<?php

namespace App\Domains\Reporting\Support;

use Illuminate\Support\Facades\Date;
use InvalidArgumentException;

/**
 * Vocabulario cerrado de fechas relativas para un parámetro de reporte
 * guardado (ver SavedReport::parameters) — se resuelve recién al invocar,
 * nunca se congela en un valor absoluto al guardar. Usa Date::now() (no
 * now() global) para ser testeable con Date::setTestNow(), mismo criterio
 * que ya usa ReportHeaderFactory.
 */
final class RelativeDate
{
    public const OPTIONS = [
        'today',
        'start_of_month',
        'end_of_month',
        'start_of_year',
        'end_of_year',
        'start_of_previous_month',
        'end_of_previous_month',
    ];

    public static function resolve(string $keyword): string
    {
        return match ($keyword) {
            'today' => Date::now()->format('Y-m-d'),
            'start_of_month' => Date::now()->startOfMonth()->format('Y-m-d'),
            'end_of_month' => Date::now()->endOfMonth()->format('Y-m-d'),
            'start_of_year' => Date::now()->startOfYear()->format('Y-m-d'),
            'end_of_year' => Date::now()->endOfYear()->format('Y-m-d'),
            'start_of_previous_month' => Date::now()->subMonthNoOverflow()->startOfMonth()->format('Y-m-d'),
            'end_of_previous_month' => Date::now()->subMonthNoOverflow()->endOfMonth()->format('Y-m-d'),
            default => throw new InvalidArgumentException("Palabra clave de fecha relativa desconocida: {$keyword}."),
        };
    }
}
