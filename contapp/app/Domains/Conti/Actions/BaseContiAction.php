<?php

namespace App\Domains\Conti\Actions;

use App\Domains\Accounting\Models\Currency;
use App\Domains\Conti\Resources\ContiFilters;
use App\Domains\Core\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Lo que comparten las acciones de Conti: validar y resolver códigos. */
abstract class BaseContiAction implements ContiAction
{
    /**
     * Valida con los mensajes en español de la aplicación; los errores
     * llevan el nombre del campo tal como lo mandó el agente.
     */
    protected function validate(array $input, array $rules): array
    {
        return Validator::make($input, $rules, [], array_combine(
            array_keys($rules),
            array_map(fn (string $field) => '«'.str_replace(['.*.', '_'], [' ', ' '], $field).'»', array_keys($rules)),
        ))->validate();
    }

    protected function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    /**
     * Un registro de la compañía por su código (pasa por el CompanyScope).
     *
     * @template T of Model
     *
     * @param  class-string<T>  $model
     * @return T
     */
    protected function byCode(string $model, ?string $code, string $field, string $what, string $column = 'code'): Model
    {
        $found = $code === null || $code === '' ? null : $model::where($column, $code)->first();

        return $found ?? $this->fail($field, "No existe {$what} con código «{$code}» en esta compañía.");
    }

    /** @param  class-string<Model>  $model */
    protected function optionalByCode(string $model, ?string $code, string $field, string $what): ?Model
    {
        return $code === null || $code === '' ? null : $this->byCode($model, $code, $field, $what);
    }

    /** La moneda local o la extranjera de la compañía, por su código (CRC, USD). */
    protected function currency(Company $company, ?string $code, string $field): Currency
    {
        $local = Currency::find($company->local_currency_id);

        if ($code === null || $code === '') {
            return $local;
        }

        $foreign = $company->foreign_currency_id ? Currency::find($company->foreign_currency_id) : null;

        foreach ([$local, $foreign] as $currency) {
            if ($currency && strcasecmp($currency->code, $code) === 0) {
                return $currency;
            }
        }

        $this->fail($field, "La moneda «{$code}» no es ni la local ni la extranjera de la compañía.");
    }

    protected function bool(mixed $value, bool $default = false): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return is_bool($value) ? $value : ContiFilters::truthy((string) $value);
    }

    protected function item(string $campo, ?string $valor): array
    {
        return ['campo' => $campo, 'valor' => $valor];
    }

    /** @param  array<string, string|null>  $pairs */
    protected function items(array $pairs): array
    {
        $items = [];

        foreach ($pairs as $label => $value) {
            if ($value !== null && $value !== '') {
                $items[] = $this->item($label, (string) $value);
            }
        }

        return $items;
    }

    protected function money(mixed $value): string
    {
        return number_format((float) $value, 2, ',', '.');
    }
}
