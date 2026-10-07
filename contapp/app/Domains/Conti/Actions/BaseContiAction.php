<?php

namespace App\Domains\Conti\Actions;

use App\Domains\Accounting\Models\Currency;
use App\Domains\Conti\Resources\ContiFilters;
use App\Domains\Core\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
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

    /** Sin nada más que lo que mandó el agente. */
    public function formValues(array $values, Company $company): array
    {
        return $values;
    }

    /** Sin sugerencias. */
    public function suggest(array $values, Company $company): array
    {
        return [];
    }

    /** @return array{valor: string, motivo: string}|null */
    protected function suggestion(mixed $value, string $reason): ?array
    {
        return $value === null || $value === '' ? null : ['valor' => (string) $value, 'motivo' => $reason];
    }

    /** ¿Ya viene ese campo? */
    protected function given(array $values, string $field): bool
    {
        return isset($values[$field]) && $values[$field] !== '';
    }

    /**
     * El código que sigue: «CLI-006» → «CLI-007», «6-02-01-01-005» →
     * «6-02-01-01-006». Con los mismos dígitos, y el primero que esté libre.
     *
     * @param  callable(string): bool  $taken
     */
    protected function nextCode(?string $code, callable $taken): ?string
    {
        if ($code === null || ! preg_match('/^(.*?)(\d+)$/', $code, $match)) {
            return null;
        }

        $width = strlen($match[2]);
        $number = (int) $match[2];

        for ($try = 0; $try < 50; $try++) {
            $candidate = $match[1].str_pad((string) ++$number, $width, '0', STR_PAD_LEFT);

            if (! $taken($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * El valor que más se repite en esa columna, y en cuántos de cuántos.
     *
     * @return array{0: mixed, 1: int, 2: int}|null
     */
    protected function mostCommon(Builder $query, string $column): ?array
    {
        $rows = (clone $query)->whereNotNull($column)
            ->toBase()
            ->select($column, DB::raw('count(*) as total'))
            ->groupBy($column)
            ->orderByDesc('total')
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        return [$rows->first()->{$column}, (int) $rows->first()->total, (int) $rows->sum('total')];
    }

    /**
     * Un campo del formulario del chat (ContiForm.vue).
     *
     * - tipo: texto, texto_largo, numero, monto, fecha, opciones, si_no,
     *   buscar (un código, con sugerencias de una consulta) o lineas (una
     *   lista de renglones, con sus «columnas»).
     * - extra: requerido, ayuda, defecto («hoy» en una fecha), opciones
     *   (valor => etiqueta), fuente y filtros (para buscar), columnas y
     *   minimo (para lineas), avanzado (se muestra plegado).
     *
     * @return array<string, mixed>
     */
    protected function field(string $name, string $label, string $type = 'texto', array $extra = []): array
    {
        if (isset($extra['opciones'])) {
            $extra['opciones'] = array_map(
                fn ($value, $text) => ['valor' => (string) $value, 'etiqueta' => (string) $text],
                array_keys($extra['opciones']),
                $extra['opciones'],
            );
        }

        return ['campo' => $name, 'etiqueta' => $label, 'tipo' => $type, ...$extra];
    }

    /** La moneda local y la extranjera de la compañía, por su código. */
    protected function currencyOptions(Company $company): array
    {
        $options = [];

        foreach ([$company->local_currency_id, $company->foreign_currency_id] as $id) {
            if ($id && ($currency = Currency::find($id))) {
                $options[$currency->code] = "{$currency->code} {$currency->name}";
            }
        }

        return $options;
    }
}
