<?php

namespace Database\Seeders;

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Tax\Models\TaxRate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Catálogo de cuentas de PRUEBA para una compañía concreta (no es un
 * catálogo "oficial" ni una plantilla de producción): existe para tener un
 * plan contable completo y realista contra el cual ejercitar asientos,
 * balances, IVA, diferencial cambiario, centros de costo y cierres sin
 * cargarlo a mano ni depender de datos de un cliente real.
 *
 * Convenciones tomadas del catálogo real de la compañía 600, que es el
 * formato que la app espera (ver ChartOfAccountBulkImporter, que importa
 * TODO con level = 1):
 *
 *   - Estructura PLANA: level = 1 y parent_id = NULL en todas las filas. La
 *     jerarquía vive en los segmentos del código (1 / 1-01 / 1-01-01 /
 *     1-01-01-01 / 1-01-01-01-001) y AccountRollupBuilder la reconstruye
 *     desde ahí; no se arma con parent_id.
 *   - Solo el 5.º nivel (N-NN-NN-NN-NNN) es cuenta de detalle
 *     (accepts_posting = true); los 4 niveles de arriba son agrupadoras y
 *     nunca reciben movimientos.
 *   - normal_balance NUNCA se escribe a mano: sale de
 *     ChartOfAccount::normalBalanceFor(), igual que en el alta manual
 *     (ChartOfAccountController::store) y en la carga masiva.
 *   - section / list_order se dejan como los deja la app (NULL / 0): el
 *     listado ordena por código.
 *
 * Idempotente (updateOrCreate por company_id + code): se puede correr las
 * veces que haga falta sin duplicar cuentas ni romper las que ya tengan
 * movimientos contabilizados.
 *
 * Uso:
 *   SEED_COMPANY_ID=604 php artisan db:seed --class=TestChartOfAccountsSeeder
 */
class TestChartOfAccountsSeeder extends Seeder
{
    /** Compañía destino. Si queda en null se lee de SEED_COMPANY_ID. */
    public ?int $companyId = null;

    /** Primer dígito del código => clase de cuenta (las 8 del gabinete). */
    private const CLASS_TYPES = [
        '1' => 'asset',
        '2' => 'liability',
        '3' => 'equity',
        '4' => 'income',
        '5' => 'cost_of_sales',
        '6' => 'expense',
        '7' => 'other_income',
        '8' => 'other_expense',
    ];

    /**
     * [código, descripción, banderas]. Banderas posibles:
     *   cash     => is_cash_account (cuenta monetaria: caja/bancos)
     *   bp       => requires_business_partner (exige socio de negocio)
     *   cc       => requires_cost_center (exige centro de costo)
     *   currency => currency_mode: 'foreign' | 'both' (por defecto 'local')
     *   tax      => tax_classification
     *   rate     => código del TaxRate a vincular (tax_rate_id)
     *   inactive => is_active = false
     */
    private const ACCOUNTS = [
        // =========================== 1. ACTIVOS ===========================
        ['1', 'ACTIVOS'],
        ['1-01', 'ACTIVO CIRCULANTE'],
        ['1-01-01', 'EFECTIVO Y EQUIVALENTES'],
        ['1-01-01-01', 'CAJA'],
        ['1-01-01-01-001', 'Caja general', ['cash' => true]],
        ['1-01-01-01-002', 'Caja chica', ['cash' => true]],
        ['1-01-01-02', 'BANCOS MONEDA LOCAL'],
        ['1-01-01-02-001', 'Banco Nacional de Costa Rica CRC', ['cash' => true]],
        ['1-01-01-02-002', 'Banco de Costa Rica CRC', ['cash' => true]],
        ['1-01-01-02-003', 'BAC Credomatic CRC', ['cash' => true]],
        ['1-01-01-03', 'BANCOS MONEDA EXTRANJERA'],
        ['1-01-01-03-001', 'Banco Nacional de Costa Rica USD', ['cash' => true, 'currency' => 'foreign']],
        ['1-01-01-03-002', 'BAC Credomatic USD', ['cash' => true, 'currency' => 'foreign']],
        ['1-01-01-04', 'INVERSIONES TRANSITORIAS'],
        ['1-01-01-04-001', 'Certificados de depósito a plazo'],
        ['1-01-01-04-002', 'Intereses por cobrar sobre inversiones'],

        ['1-01-02', 'CUENTAS POR COBRAR'],
        ['1-01-02-01', 'CLIENTES'],
        ['1-01-02-01-001', 'Clientes locales', ['bp' => true]],
        ['1-01-02-01-002', 'Clientes del exterior', ['bp' => true, 'currency' => 'foreign']],
        ['1-01-02-01-003', 'Documentos por cobrar clientes', ['bp' => true, 'currency' => 'both']],
        ['1-01-02-02', 'OTRAS CUENTAS POR COBRAR'],
        ['1-01-02-02-001', 'Adelantos a empleados', ['bp' => true]],
        ['1-01-02-02-002', 'Adelantos a proveedores', ['bp' => true]],
        ['1-01-02-02-003', 'Funcionarios y empleados', ['bp' => true]],
        ['1-01-02-03', 'ESTIMACIONES'],
        ['1-01-02-03-001', 'Estimación para cuentas incobrables'],

        ['1-01-03', 'INVENTARIOS'],
        ['1-01-03-01', 'INVENTARIO DE MERCADERÍA'],
        ['1-01-03-01-001', 'Inventario de mercadería'],
        ['1-01-03-01-002', 'Mercadería en tránsito', ['currency' => 'both']],
        // Contrapartida de la categoría 'wip' de la matriz de determinación
        // de cuentas del módulo de inventario (GlDetermination::CATEGORIES).
        ['1-01-03-01-003', 'Producto en proceso (WIP)'],
        ['1-01-03-02', 'OTROS INVENTARIOS'],
        ['1-01-03-02-001', 'Materiales y suministros'],
        ['1-01-03-02-002', 'Estimación para obsolescencia de inventario'],

        ['1-01-04', 'IMPUESTOS POR COBRAR'],
        ['1-01-04-01', 'IVA SOPORTADO (CRÉDITO FISCAL)'],
        ['1-01-04-01-001', 'IVA soportado 13%', ['tax' => 'iva_soportado', 'rate' => 'IVA-13']],
        ['1-01-04-01-002', 'IVA soportado 4%', ['tax' => 'iva_soportado', 'rate' => 'IVA-4']],
        ['1-01-04-01-003', 'IVA soportado 2%', ['tax' => 'iva_soportado', 'rate' => 'IVA-2']],
        ['1-01-04-01-004', 'IVA soportado 1%', ['tax' => 'iva_soportado', 'rate' => 'IVA-1']],
        ['1-01-04-02', 'OTROS IMPUESTOS POR COBRAR'],
        ['1-01-04-02-001', 'Retenciones de renta soportadas'],
        ['1-01-04-02-002', 'Impuesto sobre la renta pagado por anticipado'],

        ['1-01-05', 'GASTOS PAGADOS POR ANTICIPADO'],
        ['1-01-05-01', 'SEGUROS Y ALQUILERES'],
        ['1-01-05-01-001', 'Seguros pagados por anticipado'],
        ['1-01-05-01-002', 'Alquileres pagados por anticipado'],
        ['1-01-05-02', 'OTROS PAGOS ANTICIPADOS'],
        ['1-01-05-02-001', 'Suscripciones y licencias de software'],
        ['1-01-05-02-002', 'Depósitos en garantía'],

        ['1-02', 'ACTIVO FIJO'],
        ['1-02-01', 'PROPIEDAD, PLANTA Y EQUIPO'],
        ['1-02-01-01', 'TERRENOS Y EDIFICIOS'],
        ['1-02-01-01-001', 'Terrenos'],
        ['1-02-01-01-002', 'Edificios e instalaciones'],
        ['1-02-01-02', 'MOBILIARIO Y EQUIPO'],
        ['1-02-01-02-001', 'Mobiliario y equipo de oficina'],
        ['1-02-01-02-002', 'Equipo de cómputo'],
        ['1-02-01-02-003', 'Vehículos'],
        ['1-02-01-02-004', 'Maquinaria y equipo'],
        ['1-02-02', 'DEPRECIACIÓN ACUMULADA'],
        ['1-02-02-01', 'DEPRECIACIÓN ACUMULADA'],
        ['1-02-02-01-001', 'Depreciación acumulada de edificios'],
        ['1-02-02-01-002', 'Depreciación acumulada de mobiliario y equipo'],
        ['1-02-02-01-003', 'Depreciación acumulada de equipo de cómputo'],
        ['1-02-02-01-004', 'Depreciación acumulada de vehículos'],

        ['1-03', 'OTROS ACTIVOS'],
        ['1-03-01', 'ACTIVOS INTANGIBLES'],
        ['1-03-01-01', 'INTANGIBLES'],
        ['1-03-01-01-001', 'Software y licencias'],
        ['1-03-01-01-002', 'Amortización acumulada de intangibles'],
        ['1-03-02', 'ACTIVOS POR IMPUESTO DIFERIDO'],
        ['1-03-02-01', 'IMPUESTO DIFERIDO'],
        ['1-03-02-01-001', 'Activo por impuesto sobre la renta diferido'],

        // =========================== 2. PASIVOS ===========================
        ['2', 'PASIVOS'],
        ['2-01', 'PASIVO CIRCULANTE'],
        ['2-01-01', 'CUENTAS POR PAGAR'],
        ['2-01-01-01', 'PROVEEDORES'],
        ['2-01-01-01-001', 'Proveedores locales', ['bp' => true]],
        ['2-01-01-01-002', 'Proveedores del exterior', ['bp' => true, 'currency' => 'foreign']],
        ['2-01-01-02', 'OTRAS CUENTAS POR PAGAR'],
        ['2-01-01-02-001', 'Acreedores varios', ['bp' => true]],
        ['2-01-01-02-002', 'Gastos acumulados por pagar'],
        ['2-01-01-02-003', 'Anticipos recibidos de clientes', ['bp' => true]],
        // Categoría 'gr_ir_clearing': recoge la mercancía recibida cuya
        // factura del proveedor todavía no llega (y viceversa).
        ['2-01-01-02-004', 'Transitoria de compras (GR/IR)'],

        ['2-01-02', 'IMPUESTOS POR PAGAR'],
        ['2-01-02-01', 'IVA DEVENGADO (DÉBITO FISCAL)'],
        ['2-01-02-01-001', 'IVA devengado 13%', ['tax' => 'iva_devengado', 'rate' => 'IVA-13']],
        ['2-01-02-01-002', 'IVA devengado 4%', ['tax' => 'iva_devengado', 'rate' => 'IVA-4']],
        ['2-01-02-01-003', 'IVA devengado 2%', ['tax' => 'iva_devengado', 'rate' => 'IVA-2']],
        ['2-01-02-01-004', 'IVA devengado 1%', ['tax' => 'iva_devengado', 'rate' => 'IVA-1']],
        ['2-01-02-02', 'OTROS IMPUESTOS POR PAGAR'],
        ['2-01-02-02-001', 'Impuesto sobre la renta por pagar'],
        ['2-01-02-02-002', 'Retenciones de renta practicadas'],
        ['2-01-02-02-003', 'Retenciones de IVA practicadas'],

        ['2-01-03', 'CARGAS SOCIALES Y PLANILLA'],
        ['2-01-03-01', 'PLANILLA POR PAGAR'],
        ['2-01-03-01-001', 'Salarios por pagar', ['bp' => true]],
        ['2-01-03-01-002', 'Aguinaldo por pagar'],
        ['2-01-03-01-003', 'Vacaciones por pagar'],
        ['2-01-03-02', 'CARGAS SOCIALES'],
        ['2-01-03-02-001', 'CCSS por pagar'],
        ['2-01-03-02-002', 'INS riesgos del trabajo por pagar'],
        ['2-01-03-02-003', 'Embargos y deducciones por pagar'],

        ['2-01-04', 'DEUDA FINANCIERA CORTO PLAZO'],
        ['2-01-04-01', 'PRÉSTAMOS CORTO PLAZO'],
        ['2-01-04-01-001', 'Préstamos bancarios corto plazo CRC'],
        ['2-01-04-01-002', 'Préstamos bancarios corto plazo USD', ['currency' => 'foreign']],
        ['2-01-04-01-003', 'Intereses por pagar'],

        ['2-02', 'PASIVO A LARGO PLAZO'],
        ['2-02-01', 'DEUDA FINANCIERA LARGO PLAZO'],
        ['2-02-01-01', 'PRÉSTAMOS LARGO PLAZO'],
        ['2-02-01-01-001', 'Préstamos bancarios largo plazo CRC'],
        ['2-02-01-01-002', 'Préstamos bancarios largo plazo USD', ['currency' => 'foreign']],
        ['2-02-02', 'PROVISIONES Y OTROS PASIVOS'],
        ['2-02-02-01', 'PROVISIONES'],
        ['2-02-02-01-001', 'Provisión para prestaciones legales'],
        ['2-02-02-01-002', 'Pasivo por impuesto sobre la renta diferido'],

        // ========================= 3. PATRIMONIO ==========================
        ['3', 'PATRIMONIO'],
        ['3-01', 'CAPITAL'],
        ['3-01-01', 'CAPITAL SOCIAL'],
        ['3-01-01-01', 'CAPITAL SOCIAL'],
        ['3-01-01-01-001', 'Capital social suscrito y pagado'],
        ['3-01-01-01-002', 'Aportes extraordinarios de socios'],
        ['3-02', 'RESERVAS Y RESULTADOS'],
        ['3-02-01', 'RESERVAS'],
        ['3-02-01-01', 'RESERVAS'],
        ['3-02-01-01-001', 'Reserva legal'],
        ['3-02-02', 'RESULTADOS ACUMULADOS'],
        ['3-02-02-01', 'RESULTADOS ACUMULADOS'],
        ['3-02-02-01-001', 'Utilidades acumuladas de periodos anteriores'],
        ['3-02-02-01-002', 'Pérdidas acumuladas de periodos anteriores'],
        ['3-02-02-01-003', 'Utilidad o pérdida del periodo'],
        ['3-02-03', 'OTROS RESULTADOS INTEGRALES'],
        ['3-02-03-01', 'OTROS RESULTADOS INTEGRALES'],
        ['3-02-03-01-001', 'Superávit por revaluación de activos'],
        // Contrapartida de los saldos iniciales. Existe porque cargar
        // existencias de apertura como entrada de mercancía manda su
        // contrapartida a "Ajuste de inventario — aumento", que es una
        // cuenta de RESULTADOS: el balance cuadra igual, pero el estado de
        // resultados muestra la apertura como si fuera menos costo, o sea
        // más utilidad. Un saldo inicial no es un resultado del período;
        // su contrapartida es patrimonial.
        ['3-03', 'SALDOS INICIALES'],
        ['3-03-01', 'SALDOS INICIALES'],
        ['3-03-01-01', 'SALDOS INICIALES'],
        ['3-03-01-01-001', 'Saldos iniciales de existencias'],

        // ========================== 4. INGRESOS ===========================
        ['4', 'INGRESOS'],
        ['4-01', 'INGRESOS OPERATIVOS'],
        ['4-01-01', 'VENTAS DE MERCADERÍA'],
        ['4-01-01-01', 'VENTAS GRAVADAS'],
        ['4-01-01-01-001', 'Ventas de mercadería gravadas 13%', ['tax' => 'sales', 'rate' => 'IVA-13', 'cc' => true]],
        ['4-01-01-01-002', 'Ventas de mercadería gravadas 4%', ['tax' => 'sales', 'rate' => 'IVA-4', 'cc' => true]],
        ['4-01-01-02', 'VENTAS EXENTAS Y DE EXPORTACIÓN'],
        ['4-01-01-02-001', 'Ventas exentas', ['tax' => 'sales', 'rate' => 'IVA-0', 'cc' => true]],
        ['4-01-01-02-002', 'Ventas de exportación', ['tax' => 'sales', 'rate' => 'IVA-0', 'currency' => 'foreign', 'cc' => true]],
        ['4-01-02', 'INGRESOS POR SERVICIOS'],
        ['4-01-02-01', 'SERVICIOS GRAVADOS'],
        ['4-01-02-01-001', 'Servicios profesionales gravados 13%', ['tax' => 'sales', 'rate' => 'IVA-13', 'cc' => true]],
        ['4-01-02-01-002', 'Servicios de mantenimiento gravados 13%', ['tax' => 'sales', 'rate' => 'IVA-13', 'cc' => true]],
        ['4-01-03', 'DEVOLUCIONES Y DESCUENTOS'],
        ['4-01-03-01', 'DEVOLUCIONES Y DESCUENTOS SOBRE VENTAS'],
        ['4-01-03-01-001', 'Devoluciones sobre ventas', ['tax' => 'sales']],
        ['4-01-03-01-002', 'Descuentos y rebajas sobre ventas', ['tax' => 'sales']],

        // ======================= 5. COSTO DE VENTAS =======================
        ['5', 'COSTO DE VENTAS'],
        ['5-01', 'COSTO DE VENTAS'],
        ['5-01-01', 'COSTO DE MERCADERÍA Y SERVICIOS'],
        ['5-01-01-01', 'COSTO DIRECTO'],
        ['5-01-01-01-001', 'Costo de mercadería vendida', ['cc' => true]],
        ['5-01-01-01-002', 'Costo de servicios prestados', ['cc' => true]],
        ['5-01-02', 'COSTOS INDIRECTOS'],
        ['5-01-02-01', 'COSTOS INDIRECTOS'],
        ['5-01-02-01-001', 'Fletes y transporte sobre compras', ['tax' => 'purchases', 'cc' => true]],
        ['5-01-02-01-002', 'Aranceles y gastos de desalmacenaje', ['tax' => 'purchases', 'cc' => true]],
        ['5-01-02-01-003', 'Ajustes y mermas de inventario', ['cc' => true]],
        // Las cuatro categorías restantes de la matriz de inventario. Sin
        // 'cc' a propósito: una regla de determinación puede llevar norma de
        // reparto propia, pero si la CUENTA la exigiera, toda regla que la
        // use quedaría obligada a traerla — y estas las dispara el sistema
        // (ajustes, desviaciones), no alguien digitando.
        ['5-01-03', 'AJUSTES Y DESVIACIONES DE INVENTARIO'],
        ['5-01-03-01', 'AJUSTES Y DESVIACIONES'],
        ['5-01-03-01-001', 'Ajuste de inventario — aumento'],
        ['5-01-03-01-002', 'Ajuste de inventario — disminución'],
        ['5-01-03-01-003', 'Diferencia de precio de compra'],
        ['5-01-03-01-004', 'Desviación de fabricación'],

        // =========================== 6. GASTOS ============================
        ['6', 'GASTOS'],
        ['6-01', 'GASTOS DE OPERACIÓN'],
        ['6-01-01', 'GASTOS DE PERSONAL'],
        ['6-01-01-01', 'REMUNERACIONES Y CARGAS'],
        ['6-01-01-01-001', 'Salarios', ['cc' => true]],
        ['6-01-01-01-002', 'Aguinaldo', ['cc' => true]],
        ['6-01-01-01-003', 'Vacaciones', ['cc' => true]],
        ['6-01-01-01-004', 'Cargas sociales CCSS', ['cc' => true]],
        ['6-01-01-01-005', 'Póliza de riesgos del trabajo', ['cc' => true]],
        ['6-01-01-01-006', 'Capacitación y bienestar del personal', ['cc' => true]],
        ['6-01-02', 'GASTOS GENERALES'],
        ['6-01-02-01', 'SERVICIOS Y SUMINISTROS'],
        ['6-01-02-01-001', 'Alquileres', ['tax' => 'purchases', 'cc' => true]],
        ['6-01-02-01-002', 'Servicios públicos (agua, luz, teléfono)', ['tax' => 'purchases', 'cc' => true]],
        ['6-01-02-01-003', 'Internet y comunicaciones', ['tax' => 'purchases', 'cc' => true]],
        ['6-01-02-01-004', 'Papelería y útiles de oficina', ['tax' => 'purchases', 'cc' => true]],
        ['6-01-02-01-005', 'Limpieza y seguridad', ['tax' => 'purchases', 'cc' => true]],
        ['6-01-02-02', 'SERVICIOS PROFESIONALES'],
        // Sin 'bp': el socio se identifica en la línea de la cuenta por pagar,
        // no en la del gasto. Si el gasto exigiera socio, todo tipo de
        // documento con bp_line_requirement 'due_date' (FCP) obligaría a
        // ABRIR UNA PARTIDA sobre la línea de gasto, que no es lo que
        // representa: las partidas viven en CxC/CxP, no en resultados.
        ['6-01-02-02-001', 'Honorarios profesionales', ['tax' => 'purchases', 'cc' => true]],
        ['6-01-02-02-002', 'Auditoría externa', ['tax' => 'purchases', 'cc' => true]],
        ['6-01-02-02-003', 'Asesoría legal', ['tax' => 'purchases', 'cc' => true]],
        ['6-01-02-03', 'MANTENIMIENTO Y TRANSPORTE'],
        ['6-01-02-03-001', 'Mantenimiento y reparaciones', ['tax' => 'purchases', 'cc' => true]],
        ['6-01-02-03-002', 'Combustibles y lubricantes', ['tax' => 'purchases', 'cc' => true]],
        ['6-01-02-03-003', 'Viáticos y gastos de viaje', ['cc' => true]],
        ['6-01-02-04', 'SEGUROS E IMPUESTOS'],
        ['6-01-02-04-001', 'Seguros', ['cc' => true]],
        ['6-01-02-04-002', 'Impuestos municipales y patentes', ['cc' => true]],
        ['6-01-03', 'DEPRECIACIONES Y AMORTIZACIONES'],
        ['6-01-03-01', 'DEPRECIACIONES Y AMORTIZACIONES'],
        ['6-01-03-01-001', 'Depreciación de edificios', ['cc' => true]],
        ['6-01-03-01-002', 'Depreciación de mobiliario y equipo', ['cc' => true]],
        ['6-01-03-01-003', 'Depreciación de equipo de cómputo', ['cc' => true]],
        ['6-01-03-01-004', 'Depreciación de vehículos', ['cc' => true]],
        ['6-01-03-01-005', 'Amortización de intangibles', ['cc' => true]],
        ['6-02', 'GASTOS DE VENTA'],
        ['6-02-01', 'GASTOS DE VENTA'],
        ['6-02-01-01', 'MERCADEO Y DISTRIBUCIÓN'],
        ['6-02-01-01-001', 'Publicidad y mercadeo', ['tax' => 'purchases', 'cc' => true]],
        ['6-02-01-01-002', 'Comisiones sobre ventas', ['cc' => true]],
        ['6-02-01-01-003', 'Fletes sobre ventas', ['tax' => 'purchases', 'cc' => true]],
        ['6-02-01-01-004', 'Gasto por estimación de incobrables', ['cc' => true]],
        // Inactiva a propósito: sirve para probar el filtro de cuentas activas
        // y que una cuenta inactiva no se ofrezca al digitar un asiento.
        ['6-02-01-01-005', 'Publicidad impresa (descontinuada)', ['cc' => true, 'inactive' => true]],

        // ======================= 7. OTROS INGRESOS ========================
        ['7', 'OTROS INGRESOS'],
        ['7-01', 'INGRESOS FINANCIEROS'],
        ['7-01-01', 'PRODUCTOS FINANCIEROS'],
        ['7-01-01-01', 'INTERESES Y RENDIMIENTOS'],
        ['7-01-01-01-001', 'Intereses ganados sobre inversiones'],
        ['7-01-01-01-002', 'Descuentos ganados sobre compras'],
        ['7-01-02', 'DIFERENCIAL CAMBIARIO'],
        ['7-01-02-01', 'DIFERENCIAL CAMBIARIO'],
        // Cuenta de GANANCIA que pide la pantalla de revaluación cambiaria
        // (FxRevaluationService::execute recibe gainAccount y lossAccount).
        ['7-01-02-01-001', 'Ganancia por diferencial cambiario'],
        ['7-02', 'OTROS INGRESOS NO OPERATIVOS'],
        ['7-02-01', 'OTROS INGRESOS'],
        ['7-02-01-01', 'OTROS INGRESOS'],
        ['7-02-01-01-001', 'Ganancia en venta de activo fijo'],
        ['7-02-01-01-002', 'Ingresos varios'],

        // ======================== 8. OTROS GASTOS =========================
        ['8', 'OTROS GASTOS'],
        ['8-01', 'GASTOS FINANCIEROS'],
        ['8-01-01', 'GASTOS FINANCIEROS'],
        ['8-01-01-01', 'INTERESES Y COMISIONES'],
        ['8-01-01-01-001', 'Intereses sobre préstamos'],
        ['8-01-01-01-002', 'Comisiones y gastos bancarios'],
        ['8-01-02', 'DIFERENCIAL CAMBIARIO'],
        ['8-01-02-01', 'DIFERENCIAL CAMBIARIO'],
        // Contraparte de PÉRDIDA de la revaluación cambiaria.
        ['8-01-02-01-001', 'Pérdida por diferencial cambiario'],
        ['8-02', 'OTROS GASTOS NO OPERATIVOS'],
        ['8-02-01', 'OTROS GASTOS'],
        ['8-02-01-01', 'OTROS GASTOS'],
        ['8-02-01-01-001', 'Pérdida en venta de activo fijo'],
        ['8-02-01-01-002', 'Gastos no deducibles'],
        ['8-02-01-01-003', 'Multas y sanciones'],
        ['8-03', 'IMPUESTO SOBRE LA RENTA'],
        ['8-03-01', 'IMPUESTO SOBRE LA RENTA'],
        ['8-03-01-01', 'IMPUESTO SOBRE LA RENTA'],
        ['8-03-01-01-001', 'Impuesto sobre la renta del periodo'],
    ];

    public function run(): void
    {
        $companyId = $this->companyId ?? (int) env('SEED_COMPANY_ID');

        if ($companyId <= 0) {
            throw new RuntimeException(
                'Indicá la compañía destino: SEED_COMPANY_ID=<id> php artisan db:seed --class=TestChartOfAccountsSeeder'
            );
        }

        $company = Company::findOrFail($companyId);

        // Sin esto, CompanyScope falla cerrado (whereRaw 1=0) y updateOrCreate
        // jamás encontraría las filas existentes: cada corrida intentaría un
        // INSERT y chocaría contra el unique (company_id, code).
        app(CurrentCompany::class)->set($company->id);

        // Tarifas nacionales (company_id NULL) o propias de la compañía. Se
        // resuelven por código y no por id, que cambia entre ambientes.
        $rates = TaxRate::withoutGlobalScopes()
            ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $company->id))
            ->pluck('id', 'code');

        DB::transaction(function () use ($company, $rates): void {
            foreach (self::ACCOUNTS as $row) {
                [$code, $description] = $row;
                $flags = $row[2] ?? [];

                $accountType = self::CLASS_TYPES[$code[0]];
                $isLeaf = substr_count($code, '-') === 4;

                if (isset($flags['rate']) && ! isset($rates[$flags['rate']])) {
                    throw new RuntimeException(
                        "La cuenta {$code} referencia la tarifa {$flags['rate']}, que no existe en esta base. ".
                        'Cargá primero las tarifas de IVA.'
                    );
                }

                ChartOfAccount::updateOrCreate(
                    ['company_id' => $company->id, 'code' => $code],
                    [
                        'parent_id' => null,
                        'level' => 1,
                        'description_es' => $description,
                        'account_type' => $accountType,
                        'normal_balance' => ChartOfAccount::normalBalanceFor($accountType),
                        'currency_mode' => $flags['currency'] ?? 'local',
                        // Solo el 5.º nivel recibe movimientos; las agrupadoras nunca.
                        'accepts_posting' => $isLeaf,
                        'requires_business_partner' => $flags['bp'] ?? false,
                        'is_cash_account' => $flags['cash'] ?? false,
                        'requires_cost_center' => $flags['cc'] ?? false,
                        'is_financial_report' => true,
                        'tax_classification' => $flags['tax'] ?? 'none',
                        'tax_rate_id' => isset($flags['rate']) ? $rates[$flags['rate']] : null,
                        'is_active' => ! ($flags['inactive'] ?? false),
                    ]
                );
            }
        });

        $total = count(self::ACCOUNTS);
        $leaves = count(array_filter(self::ACCOUNTS, fn ($r) => substr_count($r[0], '-') === 4));

        $this->command?->info(
            "Catálogo de prueba cargado en «{$company->legal_name}» (id {$company->id}): ".
            "{$total} cuentas, {$leaves} de detalle."
        );
    }
}
