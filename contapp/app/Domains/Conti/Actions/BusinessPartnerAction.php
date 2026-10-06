<?php

namespace App\Domains\Conti\Actions;

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\CostCenter;
use App\Domains\Accounting\Models\Currency;
use App\Domains\BusinessPartners\Models\BpCategory;
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
