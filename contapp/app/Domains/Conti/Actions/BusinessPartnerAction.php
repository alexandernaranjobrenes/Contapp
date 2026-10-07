<?php

namespace App\Domains\Conti\Actions;

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\CostCenter;
use App\Domains\Accounting\Models\Currency;
use App\Domains\BusinessPartners\Models\BpCategory;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Conti\Resources\ContiFilters;
use App\Domains\Core\Models\Company;
use App\Domains\Inventory\Models\PriceList;
use Illuminate\Validation\Rule;

/**
 * Lo común a crear y actualizar un socio: los mismos campos y reglas que
 * BusinessPartnerController. Correo y teléfono no: Conti no maneja datos de
 * contacto, se cargan en la ficha.
 */
abstract class BusinessPartnerAction extends BaseContiAction
{
    protected const TYPES = ['client' => 'Cliente', 'supplier' => 'Proveedor', 'both' => 'Ambos'];

    public function screen(): string
    {
        return 'business_partners.partners';
    }

    protected function partnerFields(): array
    {
        return [
            'codigo' => 'código del socio, formato x-xxx (ej. C-001)',
            'nombre' => 'nombre o razón social',
            'tipo' => 'cliente | proveedor | ambos',
            'cedula' => 'cédula física o jurídica (opcional)',
            'encargado' => 'nombre de la persona encargada (opcional)',
            'codigo_actividad' => 'código de actividad económica de Hacienda (opcional)',
            'socio_desde' => 'AAAA-MM-DD; por defecto hoy',
            'categoria' => 'código de la categoría de socios (opcional)',
            'centro_costo' => 'código del centro de costo (opcional)',
            'cuenta_control' => 'código de la cuenta contable de control (CxC o CxP; cuenta hoja)',
            'moneda' => 'CRC o USD; por defecto la local',
            'lista_precios' => 'código de la lista de precios; vacío = la predeterminada',
            'limite_credito' => 'monto (opcional)',
            'plazo_dias' => 'plazo de pago en días (opcional)',
        ];
    }

    /**
     * El formulario de un socio. Al editar, nada es obligatorio: lo que queda
     * vacío no cambia.
     */
    protected function partnerForm(Company $company, bool $editing = false): array
    {
        $required = ! $editing;

        return [
            $this->field('codigo', 'Código', 'texto', ['requerido' => $required, 'ayuda' => 'Formato x-xxx, ej. C-001.']),
            $this->field('nombre', 'Nombre o razón social', 'texto', ['requerido' => $required]),
            $this->field('tipo', 'Tipo', 'opciones', ['requerido' => $required, 'opciones' => self::TYPES, 'recalcula' => ! $editing]),
            $this->field('cuenta_control', 'Cuenta de control (CxC o CxP)', 'buscar', ['requerido' => $required, 'fuente' => 'cuentas', 'filtros' => ['acepta_movimientos' => 'sí']]),
            $this->field('moneda', 'Moneda', 'opciones', ['opciones' => $this->currencyOptions($company)]),
            $this->field('plazo_dias', 'Plazo de pago (días)', 'numero'),
            $this->field('limite_credito', 'Límite de crédito', 'monto'),
            $this->field('cedula', 'Cédula física o jurídica', 'texto', $editing ? ['ayuda' => 'Vacía: queda como está.'] : []),
            $this->field('categoria', 'Categoría', 'buscar', ['fuente' => 'categorias-socios', 'avanzado' => true]),
            $this->field('lista_precios', 'Lista de precios', 'buscar', ['fuente' => 'listas-precios', 'avanzado' => true, 'ayuda' => 'Vacía: la predeterminada.']),
            $this->field('centro_costo', 'Centro de costo', 'buscar', ['fuente' => 'centros-costo', 'avanzado' => true]),
            $this->field('encargado', 'Persona encargada', 'texto', ['avanzado' => true]),
            $this->field('codigo_actividad', 'Código de actividad económica', 'texto', ['avanzado' => true]),
            $this->field('socio_desde', 'Socio desde', 'fecha', ['avanzado' => true, 'defecto' => $editing ? null : 'hoy']),
        ];
    }

    /**
     * Lo que tienen los demás socios de ese tipo: el código que sigue al
     * último, la cuenta de control, la moneda y el plazo que más se usan.
     */
    protected function partnerSuggestions(array $values): array
    {
        if (! $this->given($values, 'tipo')) {
            return [];
        }

        $type = ContiFilters::keyFor((string) $values['tipo'], self::TYPES);

        if (! array_key_exists($type, self::TYPES)) {
            return [];
        }

        $label = ['client' => 'clientes', 'supplier' => 'proveedores', 'both' => 'socios de tipo ambos'][$type];
        $same = BusinessPartner::where('type', $type);
        $out = [];

        if (! $this->given($values, 'codigo') && ($last = (clone $same)->latest('created_at')->latest('id')->value('code'))) {
            $out['codigo'] = $this->suggestion(
                $this->nextCode($last, fn (string $code) => BusinessPartner::where('code', $code)->exists()),
                "Sigue a {$last}, el último de los {$label}.",
            );
        }

        if (! $this->given($values, 'cuenta_control') && ($common = $this->mostCommon($same, 'gl_account_id'))) {
            $account = ChartOfAccount::find($common[0]);
            $out['cuenta_control'] = $this->suggestion($account?->code, "La usan {$common[1]} de los {$common[2]} {$label}: {$account?->code} {$account?->description_es}.");
        }

        if (! $this->given($values, 'moneda') && ($common = $this->mostCommon($same, 'currency_id'))) {
            $out['moneda'] = $this->suggestion(Currency::find($common[0])?->code, "La tienen {$common[1]} de los {$common[2]} {$label}.");
        }

        if (! $this->given($values, 'plazo_dias') && ($common = $this->mostCommon($same, 'payment_terms_days'))) {
            $out['plazo_dias'] = $this->suggestion($common[0], "El plazo más común entre los {$label} ({$common[1]} de {$common[2]}).");
        }

        return $out;
    }

    /**
     * Valida los datos completos del socio y los lleva a columnas.
     *
     * @return array<string, mixed>
     */
    protected function normalize(array $data, Company $company, ?int $ignoreId): array
    {
        $this->validate($data, [
            'codigo' => ['required', 'string', 'max:20', Rule::unique('business_partners', 'code')->where('company_id', $company->id)->ignore($ignoreId)],
            'nombre' => ['required', 'string', 'max:255'],
            'tipo' => ['required', 'string'],
            'cedula' => ['nullable', 'string', 'max:50'],
            'encargado' => ['nullable', 'string', 'max:255'],
            'codigo_actividad' => ['nullable', 'string', 'max:20'],
            'socio_desde' => ['nullable', 'date'],
            'cuenta_control' => ['required', 'string'],
            'limite_credito' => ['nullable', 'numeric', 'min:0'],
            'plazo_dias' => ['nullable', 'integer', 'min:0'],
            'estado' => ['nullable', 'in:active,inactive,activo,inactivo'],
        ]);

        $type = ContiFilters::keyFor((string) $data['tipo'], self::TYPES);

        if (! array_key_exists($type, self::TYPES)) {
            $this->fail('tipo', 'El tipo es cliente, proveedor o ambos.');
        }

        $account = $this->byCode(ChartOfAccount::class, $data['cuenta_control'], 'cuenta_control', 'una cuenta');

        if (! $account->accepts_posting) {
            $this->fail('cuenta_control', "La cuenta {$account->code} no acepta movimientos: la cuenta de control tiene que ser una cuenta hoja.");
        }

        return [
            'code' => $data['codigo'],
            'name' => $data['nombre'],
            'type' => $type,
            'tax_id' => self::blankToNull($data['cedula'] ?? null),
            'contact_name' => self::blankToNull($data['encargado'] ?? null),
            'economic_activity_code' => self::blankToNull($data['codigo_actividad'] ?? null),
            'partner_since' => self::blankToNull($data['socio_desde'] ?? null) !== null ? date('Y-m-d', strtotime($data['socio_desde'])) : null,
            'category_id' => $this->optionalByCode(BpCategory::class, $data['categoria'] ?? null, 'categoria', 'una categoría')?->id,
            'cost_center_id' => $this->optionalByCode(CostCenter::class, $data['centro_costo'] ?? null, 'centro_costo', 'un centro de costo')?->id,
            'gl_account_id' => $account->id,
            'currency_id' => $this->currency($company, $data['moneda'] ?? null, 'moneda')->id,
            'price_list_id' => $this->optionalByCode(PriceList::class, $data['lista_precios'] ?? null, 'lista_precios', 'una lista de precios')?->id,
            'credit_limit' => self::blankToNull($data['limite_credito'] ?? null) !== null ? bcadd((string) $data['limite_credito'], '0', 2) : null,
            'payment_terms_days' => self::blankToNull($data['plazo_dias'] ?? null) !== null ? (int) $data['plazo_dias'] : null,
            'status' => in_array($data['estado'] ?? 'active', ['inactive', 'inactivo'], true) ? 'inactive' : 'active',
        ];
    }

    /** Lo que la persona lee, a partir de las columnas. */
    protected function describe(array $columns): array
    {
        $account = ChartOfAccount::find($columns['gl_account_id']);

        return $this->items([
            'Código' => $columns['code'],
            'Nombre' => $columns['name'],
            'Tipo' => self::TYPES[$columns['type']],
            'Cédula' => $columns['tax_id'],
            'Encargado' => $columns['contact_name'],
            'Actividad económica' => $columns['economic_activity_code'],
            'Socio desde' => $columns['partner_since'],
            'Categoría' => $columns['category_id'] ? BpCategory::find($columns['category_id'])?->name : null,
            'Centro de costo' => $columns['cost_center_id'] ? CostCenter::find($columns['cost_center_id'])?->name : null,
            'Cuenta de control' => $account ? "{$account->code} {$account->description_es}" : null,
            'Moneda' => Currency::find($columns['currency_id'])?->code,
            'Lista de precios' => $columns['price_list_id'] ? PriceList::find($columns['price_list_id'])?->name : 'Predeterminada de la compañía',
            'Límite de crédito' => $columns['credit_limit'] !== null ? $this->money($columns['credit_limit']) : null,
            'Plazo de pago' => $columns['payment_terms_days'] !== null ? $columns['payment_terms_days'].' días' : null,
            'Estado' => $columns['status'] === 'active' ? 'Activo' : 'Inactivo',
        ]);
    }

    private static function blankToNull(mixed $value): mixed
    {
        return $value === '' ? null : $value;
    }
}
