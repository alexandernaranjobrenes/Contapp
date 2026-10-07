<?php

namespace App\Domains\Conti\Actions;

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Conti\Resources\ContiFilters;
use App\Domains\Core\Models\Company;
use App\Domains\Tax\Models\TaxRate;
use App\Models\User;

/** Una cuenta contable, como en el Catálogo de cuentas (ChartOfAccountController::store). */
final class CreateAccountAction extends BaseContiAction
{
    public function key(): string
    {
        return 'crear_cuenta';
    }

    public function label(): string
    {
        return 'Crear una cuenta contable';
    }

    public function description(): string
    {
        return 'Agrega una cuenta al catálogo. La naturaleza (débito o crédito) sale de la clase.';
    }

    public function screen(): string
    {
        return 'accounting.chart_of_accounts';
    }

    public function fields(): array
    {
        return [
            'codigo' => 'código de la cuenta (único)',
            'nombre' => 'nombre en español',
            'nombre_ingles' => 'opcional',
            'clase' => ContiFilters::options(ChartOfAccount::ACCOUNT_TYPES),
            'moneda' => 'local | extranjera | ambas (por defecto local)',
            'clasificacion_iva' => ContiFilters::options(ChartOfAccount::TAX_CLASSIFICATIONS).' (por defecto none)',
            'indicador_impuesto' => 'código del indicador de impuesto vinculado (opcional)',
            'acepta_movimientos' => 'sí | no (por defecto sí): una cuenta hoja',
            'exige_socio' => 'sí | no (por defecto no): para CxC y CxP',
            'monetaria' => 'sí | no (por defecto no): elegible para bancos',
            'exige_norma_reparto' => 'sí | no (por defecto no)',
            'activa' => 'sí | no (por defecto sí)',
        ];
    }

    public function form(Company $company): array
    {
        return [
            $this->field('codigo', 'Código', 'texto', ['requerido' => true]),
            $this->field('nombre', 'Nombre', 'texto', ['requerido' => true]),
            $this->field('clase', 'Clase', 'opciones', ['requerido' => true, 'opciones' => ChartOfAccount::ACCOUNT_TYPES, 'recalcula' => true]),
            $this->field('moneda', 'Moneda', 'opciones', ['opciones' => ChartOfAccount::CURRENCY_MODES, 'defecto' => 'local']),
            $this->field('acepta_movimientos', 'Cuenta hoja (acepta movimientos)', 'si_no', ['defecto' => true]),
            $this->field('exige_socio', 'Exige socio de negocio (CxC/CxP)', 'si_no', ['defecto' => false]),
            $this->field('monetaria', 'Cuenta monetaria (elegible para bancos)', 'si_no', ['defecto' => false]),
            $this->field('exige_norma_reparto', 'Exige norma de reparto', 'si_no', ['defecto' => false]),
            $this->field('activa', 'Activa', 'si_no', ['defecto' => true]),
            $this->field('clasificacion_iva', 'Clasificación IVA', 'opciones', ['opciones' => ChartOfAccount::TAX_CLASSIFICATIONS, 'defecto' => 'none', 'avanzado' => true]),
            $this->field('indicador_impuesto', 'Indicador de impuesto', 'buscar', ['fuente' => 'indicadores-impuesto', 'avanzado' => true]),
            $this->field('nombre_ingles', 'Nombre en inglés', 'texto', ['avanzado' => true]),
        ];
    }

    /**
     * El código que sigue al de la última cuenta de esa clase, en el mismo
     * grupo donde se viene trabajando, y la clasificación de IVA que tienen
     * las demás.
     */
    public function suggest(array $values, Company $company): array
    {
        if (! $this->given($values, 'clase')) {
            return [];
        }

        $type = ContiFilters::keyFor((string) $values['clase'], ChartOfAccount::ACCOUNT_TYPES);

        if (! array_key_exists($type, ChartOfAccount::ACCOUNT_TYPES)) {
            return [];
        }

        $label = ChartOfAccount::ACCOUNT_TYPES[$type];
        $leaves = ChartOfAccount::where('account_type', $type)->where('accepts_posting', true);
        $latest = (clone $leaves)->latest('created_at')->latest('id')->first();
        $out = [];

        if ($latest !== null && ! $this->given($values, 'codigo')) {
            $last = (clone $leaves)->where('parent_id', $latest->parent_id)->orderByDesc('code')->value('code');
            $parent = $latest->parent_id ? ChartOfAccount::find($latest->parent_id) : null;
            $out['codigo'] = $this->suggestion(
                $this->nextCode($last, fn (string $code) => ChartOfAccount::where('code', $code)->exists()),
                "Sigue a {$last}, la última cuenta de {$label}".($parent ? " dentro de {$parent->code} {$parent->description_es}" : '').'.',
            );
        }

        $common = $this->given($values, 'clasificacion_iva') ? null : $this->mostCommon($leaves, 'tax_classification');

        if ($common !== null && $common[0] !== 'none') {
            $out['clasificacion_iva'] = $this->suggestion($common[0], "La tienen {$common[1]} de las {$common[2]} cuentas de {$label}.");
        }

        return $out;
    }

    public function prepare(array $input, Company $company, User $user): PreparedAction
    {
        $this->validate($input, [
            'codigo' => ['required', 'string', 'max:20'],
            'nombre' => ['required', 'string', 'max:255'],
            'nombre_ingles' => ['nullable', 'string', 'max:255'],
            'clase' => ['required', 'string'],
        ]);

        if (ChartOfAccount::where('code', $input['codigo'])->exists()) {
            $this->fail('codigo', "Ya existe una cuenta con el código {$input['codigo']}.");
        }

        $type = ContiFilters::keyFor((string) $input['clase'], ChartOfAccount::ACCOUNT_TYPES);
        if (! array_key_exists($type, ChartOfAccount::ACCOUNT_TYPES)) {
            $this->fail('clase', 'La clase es una de: '.implode(', ', ChartOfAccount::ACCOUNT_TYPES).'.');
        }

        $currencyMode = ContiFilters::keyFor((string) ($input['moneda'] ?? 'local'), ChartOfAccount::CURRENCY_MODES);
        if (! array_key_exists($currencyMode, ChartOfAccount::CURRENCY_MODES)) {
            $this->fail('moneda', 'La moneda es local, extranjera o ambas.');
        }

        $taxClass = ContiFilters::keyFor((string) ($input['clasificacion_iva'] ?? 'none'), ChartOfAccount::TAX_CLASSIFICATIONS);
        if (! array_key_exists($taxClass, ChartOfAccount::TAX_CLASSIFICATIONS)) {
            $this->fail('clasificacion_iva', 'La clasificación de IVA es una de: '.implode(', ', ChartOfAccount::TAX_CLASSIFICATIONS).'.');
        }

        $taxRate = $this->optionalByCode(TaxRate::class, $input['indicador_impuesto'] ?? null, 'indicador_impuesto', 'un indicador de impuesto');

        $payload = [
            'code' => $input['codigo'],
            'description_es' => $input['nombre'],
            'description_en' => ($input['nombre_ingles'] ?? '') !== '' ? $input['nombre_ingles'] : null,
            'account_type' => $type,
            'currency_mode' => $currencyMode,
            'tax_classification' => $taxClass,
            'tax_rate_id' => $taxRate?->id,
            'accepts_posting' => $this->bool($input['acepta_movimientos'] ?? null, true),
            'requires_business_partner' => $this->bool($input['exige_socio'] ?? null),
            'is_cash_account' => $this->bool($input['monetaria'] ?? null),
            'requires_cost_center' => $this->bool($input['exige_norma_reparto'] ?? null),
            'is_active' => $this->bool($input['activa'] ?? null, true),
        ];

        $yes = fn (bool $v) => $v ? 'Sí' : 'No';

        return new PreparedAction(
            title: "Crear la cuenta {$payload['code']} — {$payload['description_es']}",
            items: $this->items([
                'Código' => $payload['code'],
                'Nombre' => $payload['description_es'],
                'Nombre en inglés' => $payload['description_en'],
                'Clase' => ChartOfAccount::ACCOUNT_TYPES[$type],
                'Naturaleza' => ChartOfAccount::normalBalanceFor($type) === 'debit' ? 'Débito' : 'Crédito',
                'Moneda' => ChartOfAccount::CURRENCY_MODES[$currencyMode],
                'Clasificación IVA' => ChartOfAccount::TAX_CLASSIFICATIONS[$taxClass],
                'Indicador de impuesto' => $taxRate ? "{$taxRate->code} ({$taxRate->percentage}%)" : null,
                'Acepta movimientos' => $yes($payload['accepts_posting']),
                'Exige socio' => $yes($payload['requires_business_partner']),
                'Monetaria' => $yes($payload['is_cash_account']),
                'Exige norma de reparto' => $yes($payload['requires_cost_center']),
                'Activa' => $yes($payload['is_active']),
            ]),
            payload: $payload,
            input: $input,
        );
    }

    public function execute(PreparedAction $prepared, Company $company, User $user): ActionResult
    {
        ChartOfAccount::create([
            ...$prepared->payload,
            'company_id' => $company->id,
            'level' => 1,
            'normal_balance' => ChartOfAccount::normalBalanceFor($prepared->payload['account_type']),
        ]);

        return new ActionResult("Cuenta {$prepared->payload['code']} creada.", route('chart-of-accounts.index'), 'Ver el catálogo de cuentas');
    }
}
