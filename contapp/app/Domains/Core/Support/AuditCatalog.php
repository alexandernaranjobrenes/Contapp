<?php

namespace App\Domains\Core\Support;

use App\Domains\Accounting\Models as A;
use App\Domains\Banking\Models as Bank;
use App\Domains\Billing\Models as Bill;
use App\Domains\BusinessPartners\Models as Bp;
use App\Domains\Conti\Models as Conti;
use App\Domains\Core\Models as Core;
use App\Domains\Feedback\Models as Feedback;
use App\Domains\Inventory\Models as Inv;
use App\Domains\Licensing\Models as Lic;
use App\Domains\Payroll\Models as Pay;
use App\Domains\Reporting\Models as Rep;
use App\Domains\Tax\Models as Tax;
use App\Models\User;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Qué guarda la Bitácora de cada registro (CLAUDE.md secc. 35).
 *
 * - MODELS: todo lo que se registra, con su nombre en español, si es una
 *   línea o un derivado de otro documento (no encabeza la fila) y su
 *   pantalla de ScreenCatalog (para que un Administrador vea solo lo suyo).
 * - IGNORED: lo que no, con el motivo. Una prueba exige que cada modelo
 *   nuevo esté en una de las dos listas: así la Bitácora sigue siendo «de
 *   todo» sin que nadie tenga que acordarse.
 * - Los campos se guardan con lo de antes y lo de después, sin contraseñas,
 *   claves ni tokens, y con las cuentas bancarias enmascaradas.
 */
final class AuditCatalog
{
    /**
     * clase => [singular, plural, género (m|f), es línea o derivado, pantalla]
     *
     * @var array<class-string, array{0: string, 1: string, 2: string, 3: bool, 4: ?string}>
     */
    public const MODELS = [
        // Contabilidad
        A\AccountReconciliation::class => ['conciliación de cuenta', 'conciliaciones de cuenta', 'f', false, 'accounting.chart_of_accounts'],
        A\AccountReconciliationLine::class => ['línea de conciliación de cuenta', 'líneas de conciliación de cuenta', 'f', true, 'accounting.chart_of_accounts'],
        A\ChartOfAccount::class => ['cuenta contable', 'cuentas contables', 'f', false, 'accounting.chart_of_accounts'],
        A\CostAllocationRule::class => ['norma de reparto', 'normas de reparto', 'f', false, 'accounting.cost_allocation_rules'],
        A\CostAllocationRuleLine::class => ['línea de norma de reparto', 'líneas de norma de reparto', 'f', true, 'accounting.cost_allocation_rules'],
        A\CostCenter::class => ['centro de costo', 'centros de costo', 'm', false, 'accounting.cost_centers'],
        A\Currency::class => ['moneda', 'monedas', 'f', false, 'accounting.exchange_rates'],
        A\ExchangeRate::class => ['tipo de cambio', 'tipos de cambio', 'm', false, 'accounting.exchange_rates'],
        A\FiscalPeriod::class => ['período contable', 'períodos contables', 'm', false, 'accounting.period_close'],
        A\FiscalYear::class => ['año fiscal', 'años fiscales', 'm', false, 'accounting.period_close'],
        A\FxRevaluationDetail::class => ['línea del diferencial cambiario', 'líneas del diferencial cambiario', 'f', true, 'accounting.fx_revaluation_run'],
        A\FxRevaluationRun::class => ['diferencial cambiario', 'diferenciales cambiarios', 'm', false, 'accounting.fx_revaluation_run'],
        A\JournalDetail::class => ['línea de asiento', 'líneas de asiento', 'f', true, 'accounting.journal_entries'],
        A\JournalEntry::class => ['asiento', 'asientos', 'm', false, 'accounting.journal_entries'],
        A\JournalEntrySchedule::class => ['registro programado', 'registros programados', 'm', false, 'accounting.journal_entry_schedules'],
        A\OpeningBalance::class => ['saldo inicial', 'saldos iniciales', 'm', true, 'accounting.opening_balance'],
        A\PeriodClosingProcess::class => ['cierre de período', 'cierres de período', 'm', false, 'accounting.period_close'],

        // Bancos
        Bank\BankAccount::class => ['cuenta bancaria', 'cuentas bancarias', 'f', false, 'banking.accounts'],
        Bank\BankReconciliation::class => ['conciliación bancaria', 'conciliaciones bancarias', 'f', false, 'banking.reconciliations'],
        Bank\BankReconciliationLine::class => ['línea de conciliación bancaria', 'líneas de conciliación bancaria', 'f', true, 'banking.reconciliations'],
        Bank\BankStatementLine::class => ['movimiento del estado de cuenta', 'movimientos del estado de cuenta', 'm', true, 'banking.reconciliations'],

        // Facturación
        Bill\BillingPaymentAccount::class => ['cuenta de cobro', 'cuentas de cobro', 'f', false, 'billing.settings'],
        Bill\BillingTaxAccount::class => ['cuenta de impuesto de facturación', 'cuentas de impuesto de facturación', 'f', false, 'billing.settings'],
        Bill\CompanyEconomicActivity::class => ['actividad económica', 'actividades económicas', 'f', false, 'billing.settings'],
        Bill\PriceOverrideAuthorization::class => ['cambio de precio autorizado', 'cambios de precio autorizados', 'm', false, 'billing.new_invoice'],
        Bill\SalesDocument::class => ['comprobante', 'comprobantes', 'm', false, 'billing.sales_documents'],
        Bill\SalesDocumentLine::class => ['línea de comprobante', 'líneas de comprobante', 'f', true, 'billing.sales_documents'],
        Bill\SalesLineTax::class => ['impuesto de línea', 'impuestos de línea', 'm', true, 'billing.sales_documents'],
        Bill\SalesOrder::class => ['orden de pedido', 'órdenes de pedido', 'f', false, 'billing.sales_orders'],
        Bill\SalesOrderLine::class => ['línea de orden de pedido', 'líneas de orden de pedido', 'f', true, 'billing.sales_orders'],
        Bill\SalesPayment::class => ['medio de pago', 'medios de pago', 'm', true, 'billing.sales_documents'],
        Bill\SalesReference::class => ['referencia del comprobante', 'referencias del comprobante', 'f', true, 'billing.sales_documents'],

        // Socios de negocio
        Bp\BpCategory::class => ['categoría de socios', 'categorías de socios', 'f', false, 'business_partners.categories'],
        Bp\BpFamily::class => ['familia de socios', 'familias de socios', 'f', false, 'business_partners.categories'],
        Bp\BpOpenItem::class => ['partida abierta', 'partidas abiertas', 'f', true, 'business_partners.partners'],
        Bp\BpOpenItemReconciliation::class => ['conciliación de partidas', 'conciliaciones de partidas', 'f', false, 'business_partners.partners'],
        Bp\BpOpenItemReconciliationLine::class => ['línea de conciliación de partidas', 'líneas de conciliación de partidas', 'f', true, 'business_partners.partners'],
        Bp\BpPaymentApplication::class => ['aplicación de pago', 'aplicaciones de pago', 'f', true, 'business_partners.partners'],
        Bp\BusinessPartner::class => ['socio de negocio', 'socios de negocio', 'm', false, 'business_partners.partners'],

        // Configuración de la compañía
        Core\AccountMaskConfig::class => ['máscara de cuentas', 'máscaras de cuentas', 'f', false, 'accounting.chart_of_accounts'],
        Core\DocumentType::class => ['tipo de documento', 'tipos de documento', 'm', false, 'accounting.document_types'],
        Core\DocumentTypeNumberSeries::class => ['serie de numeración', 'series de numeración', 'f', false, 'accounting.document_types'],
        Core\DocumentTypeOfficer::class => ['responsable del tipo de documento', 'responsables del tipo de documento', 'm', true, 'accounting.document_types'],
        Core\DocumentTypePermission::class => ['permiso del tipo de documento', 'permisos del tipo de documento', 'm', true, 'accounting.document_types'],
        Core\DocumentTypePrinting::class => ['impresión del tipo de documento', 'impresiones del tipo de documento', 'f', true, 'accounting.document_types'],
        Core\DocumentTypeVisibleColumn::class => ['columna del tipo de documento', 'columnas del tipo de documento', 'f', true, 'accounting.document_types'],

        // Inventario
        Inv\BillOfMaterial::class => ['lista de materiales', 'listas de materiales', 'f', false, 'inventory.bills_of_materials'],
        Inv\BillOfMaterialLine::class => ['componente de la lista de materiales', 'componentes de la lista de materiales', 'm', true, 'inventory.bills_of_materials'],
        Inv\GlDetermination::class => ['determinación de cuenta', 'determinaciones de cuenta', 'f', true, 'inventory.gl_determinations'],
        Inv\ImportCostAllocation::class => ['asignación del rubro de nacionalización', 'asignaciones del rubro de nacionalización', 'f', true, 'inventory.import_costs'],
        Inv\ImportCostDocument::class => ['rubro de nacionalización', 'rubros de nacionalización', 'm', false, 'inventory.import_costs'],
        Inv\InventoryDocument::class => ['movimiento de inventario', 'movimientos de inventario', 'm', false, 'inventory.movements'],
        Inv\InventoryDocumentLine::class => ['línea de movimiento', 'líneas de movimiento', 'f', true, 'inventory.movements'],
        Inv\InventoryWriteDown::class => ['deterioro de inventario', 'deterioros de inventario', 'm', false, 'inventory.write_downs'],
        Inv\InventoryWriteDownLine::class => ['línea de deterioro', 'líneas de deterioro', 'f', true, 'inventory.write_downs'],
        Inv\Item::class => ['artículo', 'artículos', 'm', false, 'inventory.items'],
        Inv\ItemBin::class => ['existencia por ubicación', 'existencias por ubicación', 'f', true, 'inventory.movements'],
        Inv\ItemGroup::class => ['grupo de artículos', 'grupos de artículos', 'm', false, 'inventory.item_groups'],
        Inv\ItemLot::class => ['lote', 'lotes', 'm', true, 'inventory.items'],
        Inv\ItemLotStock::class => ['existencia por lote', 'existencias por lote', 'f', true, 'inventory.movements'],
        Inv\ItemSerial::class => ['número de serie', 'números de serie', 'm', true, 'inventory.items'],
        Inv\ItemWarehouse::class => ['existencia por almacén', 'existencias por almacén', 'f', true, 'inventory.movements'],
        Inv\LandedCostAllocation::class => ['asignación del costo de importación', 'asignaciones del costo de importación', 'f', true, 'inventory.landed_costs'],
        Inv\LandedCostDocument::class => ['costo de importación', 'costos de importación', 'm', false, 'inventory.landed_costs'],
        Inv\PriceList::class => ['lista de precios', 'listas de precios', 'f', false, 'inventory.price_lists'],
        Inv\PriceListItem::class => ['precio de lista', 'precios de lista', 'm', true, 'inventory.price_lists'],
        Inv\ProductionOrder::class => ['orden de fabricación', 'órdenes de fabricación', 'f', false, 'inventory.production_orders'],
        Inv\PurchaseOrder::class => ['orden de compra', 'órdenes de compra', 'f', false, 'inventory.purchase_orders'],
        Inv\PurchaseOrderLine::class => ['línea de orden de compra', 'líneas de orden de compra', 'f', true, 'inventory.purchase_orders'],
        Inv\StockCount::class => ['toma física', 'tomas físicas', 'f', false, 'inventory.stock_counts'],
        Inv\StockCountLine::class => ['línea de toma física', 'líneas de toma física', 'f', true, 'inventory.stock_counts'],
        Inv\StockJournal::class => ['registro del kardex', 'registros del kardex', 'm', true, 'inventory.movements'],
        Inv\UnitOfMeasure::class => ['unidad de medida', 'unidades de medida', 'f', false, 'inventory.units'],
        Inv\Warehouse::class => ['almacén', 'almacenes', 'm', false, 'inventory.warehouses'],
        Inv\WarehouseBin::class => ['ubicación', 'ubicaciones', 'f', false, 'inventory.warehouses'],

        // Planillas
        Pay\Department::class => ['departamento', 'departamentos', 'm', false, 'payroll.job_structure'],
        Pay\Employee::class => ['empleado', 'empleados', 'm', false, 'payroll.employees'],
        Pay\EmployeeDeduction::class => ['deducción o préstamo', 'deducciones y préstamos', 'f', false, 'payroll.deductions'],
        Pay\EmployeeDeductionApplication::class => ['rebajo de deducción', 'rebajos de deducción', 'm', true, 'payroll.deductions'],
        Pay\EmployeeNote::class => ['anotación del empleado', 'anotaciones del empleado', 'f', false, 'payroll.employees'],
        Pay\EmployeeRecurringInput::class => ['rubro fijo', 'rubros fijos', 'm', false, 'payroll.recurring_inputs'],
        Pay\JobPosition::class => ['puesto', 'puestos', 'm', false, 'payroll.job_structure'],
        Pay\LaborSettlement::class => ['liquidación laboral', 'liquidaciones laborales', 'f', false, 'payroll.labor_settlements'],
        Pay\LaborSettlementLine::class => ['línea de liquidación', 'líneas de liquidación', 'f', true, 'payroll.labor_settlements'],
        Pay\PayrollConcept::class => ['concepto de planilla', 'conceptos de planilla', 'm', false, 'payroll.settings'],
        Pay\PayrollContribution::class => ['carga social', 'cargas sociales', 'f', false, 'payroll.settings'],
        Pay\PayrollEntry::class => ['boleta de planilla', 'boletas de planilla', 'f', true, 'payroll.periods'],
        Pay\PayrollEntryLine::class => ['línea de boleta', 'líneas de boleta', 'f', true, 'payroll.periods'],
        Pay\PayrollInput::class => ['movimiento de planilla', 'movimientos de planilla', 'm', false, 'payroll.periods'],
        Pay\PayrollPeriod::class => ['período de planilla', 'períodos de planilla', 'm', false, 'payroll.periods'],
        Pay\PayrollPeriodEvent::class => ['evento del período de planilla', 'eventos del período de planilla', 'm', true, 'payroll.periods'],
        Pay\PayrollProvision::class => ['provisión de planilla', 'provisiones de planilla', 'f', false, 'payroll.settings'],
        Pay\PayrollSetting::class => ['parámetro de planilla', 'parámetros de planilla', 'm', false, 'payroll.settings'],
        Pay\PayrollTaxBracket::class => ['tramo del impuesto al salario', 'tramos del impuesto al salario', 'm', false, 'payroll.settings'],
        Pay\PayrollTaxCredit::class => ['crédito fiscal', 'créditos fiscales', 'm', false, 'payroll.settings'],
        Pay\PersonnelAction::class => ['acción de personal', 'acciones de personal', 'f', false, 'payroll.personnel_actions'],
        Pay\VacationMovement::class => ['movimiento de vacaciones', 'movimientos de vacaciones', 'm', false, 'payroll.vacations'],

        // Reportes e impuestos
        Rep\SavedReport::class => ['reporte guardado', 'reportes guardados', 'm', false, 'reports.saved_reports'],
        Tax\JournalDetailTax::class => ['impuesto de línea de asiento', 'impuestos de línea de asiento', 'm', true, 'accounting.journal_entries'],
        Tax\TaxRate::class => ['indicador de impuesto', 'indicadores de impuesto', 'm', false, 'tax.tax_rates'],
        Tax\TaxType::class => ['tipo de impuesto', 'tipos de impuesto', 'm', false, 'tax.tax_rates'],
    ];

    /**
     * Lo que no va en la Bitácora de la compañía, y por qué.
     *
     * @var array<class-string, string>
     */
    public const IGNORED = [
        Core\AuditLog::class => 'Es la Bitácora misma.',
        User::class => 'Lo de las cuentas ya lo anota cada acción (perfil, contraseña, permisos, estado).',
        Core\Company::class => 'Datos, tema y logo ya los anota cada acción.',
        Core\CompanyInvitation::class => 'Las invitaciones ya las anota CompanyInvitationService.',
        Core\ScreenPermission::class => 'Los permisos ya los anota PermissionGrantService.',
        Core\ModulePermission::class => 'Los permisos ya los anota PermissionGrantService.',
        Core\UserRole::class => 'El rol ya lo anota PermissionGrantService.',
        Core\Role::class => 'Catálogo del sistema.',
        Core\Module::class => 'Catálogo del sistema.',
        Core\CompanyModule::class => 'Los módulos de la compañía los decide la licencia, en el backoffice.',
        Conti\ContiAction::class => 'Es lo que Conti prepara: lo que se confirma queda con sus registros.',
        Conti\ContiUsage::class => 'Es el consumo de cada mensaje, no un cambio de datos.',
        Conti\ContiUserSetting::class => 'El acceso a Conti ya lo anota ContiAccessService.',
        Feedback\FeedbackComment::class => 'Comentarios y noticias son de CONTAPP, no de la compañía.',
        Feedback\FeedbackVote::class => 'Comentarios y noticias son de CONTAPP, no de la compañía.',
        Feedback\NewsPost::class => 'Comentarios y noticias son de CONTAPP, no de la compañía.',
        Lic\CommercialFollowUp::class => 'Backoffice: tiene su propia bitácora.',
        Lic\CommercialInteraction::class => 'Backoffice: tiene su propia bitácora.',
        Lic\CommercialProfile::class => 'Backoffice: tiene su propia bitácora.',
        Lic\License::class => 'Backoffice: tiene su propia bitácora.',
        Lic\LicenseCategory::class => 'Backoffice: tiene su propia bitácora.',
        Lic\LicenseInvitation::class => 'Backoffice: tiene su propia bitácora.',
        Lic\Propietario::class => 'Backoffice: tiene su propia bitácora.',
    ];

    /** Nunca se guardan: se ven solo en su pantalla, o en ningún lado. */
    private const SECRET = '/password|token|secret|api_key|security_code/i';

    /** Se guardan con los últimos cuatro dígitos. */
    private const MASKED = '/account_number|bank_account|iban/i';

    /** Lo que cambia solo, en cada guardado. */
    private const NOISE = ['id', 'company_id', 'created_at', 'updated_at', 'deleted_at', 'created_by', 'updated_by'];

    /** Tope de campos por registro y de largo por valor: es una bitácora, no un respaldo. */
    private const MAX_FIELDS = 40;

    private const MAX_LENGTH = 200;

    public static function audits(Model $model): bool
    {
        return isset(self::MODELS[$model::class]);
    }

    /** @return array{0: string, 1: string, 2: string, 3: bool, 4: ?string}|null */
    public static function meta(string $class): ?array
    {
        return self::MODELS[$class] ?? null;
    }

    public static function isLine(string $class): bool
    {
        return self::MODELS[$class][3] ?? false;
    }

    public static function screen(string $class): ?string
    {
        return self::MODELS[$class][4] ?? null;
    }

    /** «el asiento», «la cuenta contable» */
    public static function withArticle(string $class): string
    {
        $meta = self::meta($class);

        if ($meta === null) {
            return 'el registro';
        }

        return ($meta[2] === 'f' ? 'la ' : 'el ').$meta[0];
    }

    /** «Asiento», «Cuenta contable» */
    public static function noun(string $class): string
    {
        return Str::ucfirst(self::meta($class)[0] ?? Str::headline(class_basename($class)));
    }

    public static function plural(string $class): string
    {
        return self::meta($class)[1] ?? 'registros';
    }

    /**
     * Cómo se llama el registro: su número de documento, su código y nombre,
     * o lo que tenga que lo identifique. «#id» si no tiene nada.
     */
    public static function subject(Model $model): string
    {
        $get = fn (string $key) => self::text($model->getAttribute($key));

        $text = match (true) {
            $model instanceof Pay\Employee => trim($get('code').' — '.$model->fullName(), ' —'),
            $model instanceof A\ExchangeRate => trim($get('rate_date').' · '.$get('rate')),
            $model instanceof A\FiscalPeriod => 'período '.$get('period_number'),
            $model instanceof Bank\BankAccount => trim($get('bank_name').' '.self::mask((string) $model->getAttribute('account_number'))),
            $model instanceof Bank\BankReconciliation => 'corte del '.$get('cutoff_date'),
            default => self::firstOf($model),
        };

        return Str::limit($text !== '' ? $text : '#'.$model->getKey(), 120);
    }

    /**
     * Lo que cambió: campo => [antes, después]. Al crear, solo lo que tiene
     * valor; al eliminar, lo que tenía.
     *
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public static function fields(Model $model, string $event): array
    {
        $hidden = array_flip($model->getHidden());
        $out = [];

        $keys = match ($event) {
            'updated' => array_keys($model->getChanges()),
            default => array_keys($model->getAttributes()),
        };

        foreach ($keys as $key) {
            if (in_array($key, self::NOISE, true) || isset($hidden[$key]) || preg_match(self::SECRET, $key)) {
                continue;
            }

            // En «updated», getOriginal todavía tiene lo de antes (Eloquent lo
            // sincroniza después del evento), con los mismos casts que lo nuevo.
            $old = $event === 'created' ? null : self::value($key, $event === 'updated' ? $model->getOriginal($key) : $model->getAttribute($key));
            $new = $event === 'deleted' ? null : self::value($key, $model->getAttribute($key));

            if ($event !== 'updated' && $old === null && $new === null) {
                continue;
            }

            if ($event === 'updated' && self::same($old, $new)) {
                continue;
            }

            $out[$key] = [$old, $new];

            if (count($out) >= self::MAX_FIELDS) {
                break;
            }
        }

        return $out;
    }

    private static function firstOf(Model $model): string
    {
        $get = fn (string $key) => self::text($model->getAttribute($key));

        foreach (['document_number', 'consecutive', 'number'] as $key) {
            if ($get($key) !== '') {
                return $get($key);
            }
        }

        $name = $get('name') ?: $get('description_es') ?: $get('title') ?: $get('description');

        if ($get('code') !== '') {
            return trim($get('code').' — '.Str::limit($name, 60), ' —');
        }

        foreach (['name', 'title', 'reference', 'description', 'year'] as $key) {
            if ($get($key) !== '') {
                return Str::limit($get($key), 60);
            }
        }

        return '';
    }

    private static function value(string $key, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (preg_match(self::MASKED, $key)) {
            return self::mask((string) $value);
        }

        if (is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        return Str::limit(self::text($value), self::MAX_LENGTH);
    }

    private static function text(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            $value instanceof DateTimeInterface => $value->format($value->format('H:i:s') === '00:00:00' ? 'Y-m-d' : 'Y-m-d H:i'),
            $value instanceof BackedEnum => (string) $value->value,
            is_array($value) || is_object($value) => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            is_bool($value) => $value ? 'sí' : 'no',
            default => trim((string) $value),
        };
    }

    /** «500.00» y «500», «1» y true, una fecha con o sin hora: es lo mismo. */
    private static function same(mixed $old, mixed $new): bool
    {
        if (is_numeric($old) && is_numeric($new)) {
            return (float) $old === (float) $new;
        }

        if (is_bool($old) || is_bool($new)) {
            return (bool) $old === (bool) $new;
        }

        return $old === $new || (string) $old === (string) $new;
    }

    private static function mask(string $value): string
    {
        $value = trim($value);

        return strlen($value) > 4 ? '••••'.substr($value, -4) : $value;
    }
}
