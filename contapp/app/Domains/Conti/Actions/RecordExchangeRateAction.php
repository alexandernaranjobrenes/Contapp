<?php

namespace App\Domains\Conti\Actions;

use App\Domains\Accounting\Models\Currency;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Conti\Resources\ContiFilters;
use App\Domains\Core\Models\Company;
use App\Models\User;

/** Un tipo de cambio cargado a mano, como en Tipos de cambio (ExchangeRateController::store). */
final class RecordExchangeRateAction extends BaseContiAction
{
    private const TYPES = ['reference' => 'Referencia', 'buy' => 'Compra', 'sell' => 'Venta'];

    public function key(): string
    {
        return 'registrar_tipo_cambio';
    }

    public function label(): string
    {
        return 'Registrar un tipo de cambio';
    }

    public function description(): string
    {
        return 'Carga o corrige el tipo de cambio de una fecha (colones por una unidad de la moneda extranjera). Si esa fecha y ese tipo ya existen, se reemplaza.';
    }

    public function screen(): string
    {
        return 'accounting.exchange_rates';
    }

    public function fields(): array
    {
        return [
            'fecha' => 'AAAA-MM-DD',
            'tipo' => 'referencia | compra | venta',
            'tasa' => 'colones por 1 unidad de moneda extranjera, ej. 512.35',
            'bloquear' => 'sí | no (por defecto no): bloqueada, la sincronización con el BCCR no la sobrescribe',
        ];
    }

    public function prepare(array $input, Company $company, User $user): PreparedAction
    {
        $this->validate($input, [
            'fecha' => ['required', 'date'],
            'tipo' => ['required', 'string'],
            'tasa' => ['required', 'numeric', 'gt:0'],
        ]);

        if (! $company->foreign_currency_id) {
            $this->fail('fecha', 'La compañía no tiene configurada una moneda extranjera.');
        }

        $type = ContiFilters::keyFor((string) $input['tipo'], self::TYPES);

        if (! array_key_exists($type, self::TYPES)) {
            $this->fail('tipo', 'El tipo es referencia, compra o venta.');
        }

        $date = date('Y-m-d', strtotime($input['fecha']));
        $rate = bcadd((string) $input['tasa'], '0', 6);
        $locked = $this->bool($input['bloquear'] ?? null);

        $existing = ExchangeRate::where('currency_id', $company->foreign_currency_id)
            ->where('rate_type', $type)
            ->whereDate('rate_date', $date)
            ->first();

        $currency = Currency::find($company->foreign_currency_id);

        return new PreparedAction(
            title: "Tipo de cambio {$currency?->code} del {$date}",
            items: $this->items([
                'Fecha' => $date,
                'Tipo' => self::TYPES[$type],
                'Tasa' => "₡{$rate} por 1 {$currency?->code}",
                'Bloqueado' => $locked ? 'Sí: el BCCR no lo va a sobrescribir' : 'No',
                'Reemplaza' => $existing ? 'Sí: el valor anterior era '.rtrim(rtrim((string) $existing->rate, '0'), '.') : null,
            ]),
            payload: ['date' => $date, 'type' => $type, 'rate' => $rate, 'locked' => $locked],
            input: $input,
        );
    }

    public function execute(PreparedAction $prepared, Company $company, User $user): ActionResult
    {
        $p = $prepared->payload;

        $existing = ExchangeRate::where('currency_id', $company->foreign_currency_id)
            ->where('rate_type', $p['type'])
            ->whereDate('rate_date', $p['date'])
            ->first();

        $attributes = ['rate' => $p['rate'], 'source' => 'manual', 'is_locked' => $p['locked'], 'created_by' => $user->id];

        if ($existing) {
            $existing->update($attributes);
        } else {
            ExchangeRate::create([
                ...$attributes,
                'company_id' => $company->id,
                'currency_id' => $company->foreign_currency_id,
                'rate_date' => $p['date'],
                'rate_type' => $p['type'],
            ]);
        }

        return new ActionResult("Tipo de cambio del {$p['date']} guardado.", route('exchange-rates.index'), 'Ver los tipos de cambio');
    }
}
