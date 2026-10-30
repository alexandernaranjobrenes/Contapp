<?php

namespace App\Domains\Core\Dashboard\Widgets;

use App\Domains\Conti\Services\ContiUsageService;
use App\Domains\Core\Dashboard\DashboardContext;
use App\Domains\Core\Dashboard\DashboardWidget;

/**
 * Lo que gastó de Conti esta semana quien mira el Panel, contra el límite
 * más cercano de agotarse (el suyo o el de la licencia): lo mismo que dice
 * «Modelo y consumo» en el chat. Solo si la licencia tiene Conti y la persona
 * lo puede usar.
 */
class ContiUsageWidget extends DashboardWidget
{
    public function key(): string
    {
        return 'conti_usage';
    }

    public function title(): string
    {
        return 'Tu consumo de Conti';
    }

    public function description(): string
    {
        return 'Los créditos de Conti que gastaste esta semana y cuánto te queda. Cada persona ve el suyo.';
    }

    public function section(): string
    {
        return 'conti';
    }

    public function kind(): string
    {
        return 'meter';
    }

    public function size(): string
    {
        return 'small';
    }

    public function screens(): array
    {
        return [];
    }

    public function requires(): array
    {
        return ['conti'];
    }

    public function data(DashboardContext $context): array
    {
        $usage = app(ContiUsageService::class)->personal($context->user, $context->company);

        // El límite que está más cerca de agotarse es el que importa.
        $limit = collect($usage['limites'])
            ->sortByDesc(fn (array $l) => (float) $l['limite'] > 0 ? (float) $l['usado'] / (float) $l['limite'] : 0)
            ->first();

        return [
            'used' => round((float) $usage['semana']['creditos'], 2),
            'unit' => 'créditos',
            'caption' => $usage['semana']['mensajes'] === 1 ? '1 mensaje esta semana' : "{$usage['semana']['mensajes']} mensajes esta semana",
            'limit' => $limit ? [
                'label' => $limit['nombre'],
                'value' => round((float) $limit['limite'], 2),
                'used' => round((float) $limit['usado'], 2),
                'renews' => "Se renueva {$limit['renueva']}.",
            ] : null,
            'empty' => 'Sin límite de consumo.',
        ];
    }
}
