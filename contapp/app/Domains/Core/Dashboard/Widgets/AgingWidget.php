<?php

namespace App\Domains\Core\Dashboard\Widgets;

use App\Domains\BusinessPartners\DataTransferObjects\AgingCurrencyGroup;
use App\Domains\BusinessPartners\DataTransferObjects\AgingResult;
use App\Domains\BusinessPartners\Services\AgingService;
use App\Domains\Core\Dashboard\DashboardContext;
use App\Domains\Core\Dashboard\DashboardWidget;

/**
 * La antigüedad de saldos de hoy (AgingService, el mismo reporte), por tramo
 * de vencimiento: los tramos están en orden, así que van de un solo color.
 *
 * El reporte agrupa por moneda y no las suma entre sí; acá se dibuja la
 * moneda local y las otras se nombran debajo con su total.
 */
abstract class AgingWidget extends DashboardWidget
{
    /** client | supplier */
    abstract protected function partnerType(): string;

    public function section(): string
    {
        return 'business_partners';
    }

    public function kind(): string
    {
        return 'bars';
    }

    public function screens(): array
    {
        return ['reports.aging'];
    }

    public function href(DashboardContext $context): ?string
    {
        return route('reports.aging.index');
    }

    public function data(DashboardContext $context): array
    {
        $result = self::aging($context, $this->partnerType());
        $group = self::localGroup($context, $result->groups);

        return [
            'format' => 'money',
            'currency' => $context->currency,
            'rows' => $group
                ? collect($result->bucketLabels)->map(fn (string $label, string $key) => [
                    'label' => $label,
                    'value' => DashboardContext::money($group->bucketTotals[$key] ?? 0),
                ])->values()->all()
                : [],
            'total' => $group ? ['label' => 'Total', 'value' => DashboardContext::money($group->total)] : null,
            'others' => collect($result->groups)
                ->reject(fn (AgingCurrencyGroup $g) => $g === $group)
                ->map(fn (AgingCurrencyGroup $g) => ['currency' => $g->currencyCode, 'value' => DashboardContext::money($g->total)])
                ->values()->all(),
            'empty' => 'No hay saldos pendientes.',
        ];
    }

    /** La antigüedad de hoy, una sola vez por visita aunque la usen varios elementos. */
    public static function aging(DashboardContext $context, string $partnerType): AgingResult
    {
        return $context->remember("aging:{$partnerType}", fn () => app(AgingService::class)
            ->build($context->company, $context->today->toDateString(), $partnerType));
    }

    /**
     * El grupo de la moneda local, o el primero si no hay saldos en ella.
     *
     * @param  AgingCurrencyGroup[]  $groups
     */
    public static function localGroup(DashboardContext $context, array $groups): ?AgingCurrencyGroup
    {
        return collect($groups)->first(fn (AgingCurrencyGroup $g) => $g->currencyCode === $context->currency['code'])
            ?? ($groups[0] ?? null);
    }
}
