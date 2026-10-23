<script setup>
import { ref, computed, nextTick, onMounted, onBeforeUnmount, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { startCompanySwitch } from '../Utils/companySwitch';
import { closeConti, conti, toggleConti } from '../Utils/contiChat';
import { openScanner } from '../Utils/contiScan';
import { useNewsSeen } from '../Utils/newsSeen';
import FeedbackPanel from '../Components/Feedback/FeedbackPanel.vue';
import {
    BookOpenIcon, BotIcon, Building2Icon, CheckIcon, ChevronDownIcon, ChevronRightIcon, CircleUserRoundIcon, HandshakeIcon, LandmarkIcon, LayoutDashboardIcon, LibraryIcon, LogOutIcon, MenuIcon,
    MessageCircleIcon, MessagesSquareIcon, MoonIcon, PackageIcon, PanelLeftCloseIcon, PanelLeftIcon, PanelLeftOpenIcon, PanelTopIcon, PercentIcon, ReceiptIcon,
    ScanLineIcon, SettingsIcon, SunIcon, TagsIcon, UsersIcon, XIcon,
} from '@lucide/vue';

/**
 * La barra superior es la MISMA en todas las pantallas (CLAUDE.md secc. 24):
 * título, compañía, tema, rol, «Comentarios y noticias» y «Salir». Lo único
 * que cambia es el título.
 * El nombre del usuario no va: ocupaba lugar y no decide nada. Por
 * eso este layout no tiene un slot para acciones: los botones, filtros y
 * buscadores de cada pantalla van en su .view-toolbar, arriba de la tabla.
 *
 * Con «menú arriba» (desde 1025px, si la persona lo eligió): el menú es una
 * barra horizontal arriba, con la marca y el título, y lo de la barra superior
 * pasa a una columna a la izquierda. En un teléfono o una tablet, siempre
 * como está: el menú en su panel.
 */
defineProps({
    title: { type: String, default: '' },
});

const page = usePage();

// Agrupado por área en vez de una lista plana de ~18 ítems (era la queja:
// el menú quedaba muy cargado) — mismas rutas y funciones de siempre, solo
// organizadas bajo secciones colapsables. Dentro de cada sección, cada hijo
// lleva su categoría ("group") y el submenú la muestra como subtítulo
// (CLAUDE.md secc. 29): primero el trabajo diario, después los reportes y al
// final los catálogos y la configuración.
//
// Cada hijo lleva su pantalla ("screen"): los permisos son por pantalla del
// menú (App\Domains\Core\Support\ScreenCatalog, que tiene las mismas; un test
// lo verifica). Se muestran solo las que la persona puede abrir, y una
// sección sin ninguna desaparece. El backend sigue siendo quien realmente
// bloquea (EnsureModuleAccess): esto es solo para no ofrecer en el menú algo
// a lo que de todas formas no puede entrar.
function passesScreen(screen) {
    return !screen || (page.props.screenAccess?.[screen] ?? 'none') !== 'none';
}

// La foto de perfil de la cuenta (Mi cuenta → Mis datos), para mostrarla en
// lugar del ícono de «Mi cuenta». Si no carga —el bucket no responde, el
// archivo ya no está— vuelve el ícono, en vez de dejar una imagen rota. Una
// dirección nueva (se subió otra foto) merece otro intento.
const photoFailed = ref(false);
const accountPhoto = computed(() => (photoFailed.value ? null : page.props.auth?.user?.photo_url ?? null));
watch(() => page.props.auth?.user?.photo_url, () => { photoFailed.value = false; });

const nav = computed(() => {
    const items = [
        { label: 'Panel', href: route('dashboard'), match: ['dashboard'], icon: LayoutDashboardIcon },
        {
            label: 'Contabilidad', icon: BookOpenIcon,
            match: ['document-types.*', 'chart-of-accounts.*', 'opening-balance.*', 'journal-entries.*', 'journal-entry-schedules.*', 'period-close.*',
                'reports.trial-balance.*', 'reports.income-statement.*', 'reports.balance-sheet.*', 'reports.period-comparison.*',
                'reports.multi-company-comparison.*', 'reports.document-type-register.*', 'reports.catalog-export.*', 'saved-reports.*', 'accounting-reports.*'],
            children: [
                { screen: 'accounting.journal_entries', group: 'Operación', label: 'Registros', href: route('journal-entries.index'), match: ['journal-entries.*'] },
                { screen: 'accounting.journal_entry_schedules', group: 'Operación', label: 'Registros programados', href: route('journal-entry-schedules.index'), match: ['journal-entry-schedules.*'] },
                { screen: 'accounting.opening_balance', group: 'Operación', label: 'Saldos iniciales', href: route('opening-balance.create'), match: ['opening-balance.*'] },
                { screen: 'accounting.period_close', group: 'Operación', label: 'Cierre de períodos', href: route('period-close.index'), match: ['period-close.*'] },

                // Cada reporte es su propia pantalla, en el módulo de
                // reportería: su permiso es distinto del de contabilidad.
                // UNA entrada para los dos libros, no una por libro.
                //
                // Los dos salen del mismo controlador y comparten pantalla de
                // permiso (ver ScreenCatalog), y el menú exige una entrada por
                // pantalla — lo comprueba ScreenCatalogTest. Así que se entra
                // al índice y ahí se escoge mayor o diario, que es el mismo
                // patrón de «Reportes de inventario» y «Reportes» de planillas.
                //
                // Va primero entre los reportes: los libros se consultan a
                // diario para rastrear un saldo, mientras que los estados
                // financieros se miran al cerrar.
                { screen: 'reports.accounting_books', group: 'Reportes', label: 'Libro mayor y diario', href: route('accounting-reports.index'), match: ['accounting-reports.*'] },
                { screen: 'reports.trial_balance', group: 'Reportes', label: 'Balance de comprobación', href: route('reports.trial-balance.index'), match: ['reports.trial-balance.*'] },
                { screen: 'reports.income_statement', group: 'Reportes', label: 'Estado de resultados', href: route('reports.income-statement.index'), match: ['reports.income-statement.*'] },
                { screen: 'reports.balance_sheet', group: 'Reportes', label: 'Balance general', href: route('reports.balance-sheet.index'), match: ['reports.balance-sheet.*'] },
                { screen: 'reports.period_comparison', group: 'Reportes', label: 'Comparativo entre periodos', href: route('reports.period-comparison.index'), match: ['reports.period-comparison.*'] },
                { screen: 'reports.multi_company_comparison', group: 'Reportes', label: 'Comparativo de empresas', href: route('reports.multi-company-comparison.index'), match: ['reports.multi-company-comparison.*'] },
                { screen: 'reports.document_type_register', group: 'Reportes', label: 'Registro por tipo de documento', href: route('reports.document-type-register.index'), match: ['reports.document-type-register.*'] },
                // Guarda combinaciones de parámetros de VARIOS reportes, no
                // solo de los contables; queda acá porque la mayoría lo son.
                { screen: 'reports.saved_reports', group: 'Reportes', label: 'Reportes guardados', href: route('saved-reports.index'), match: ['saved-reports.*'] },
                // Exporta cuentas, centros de costo, normas de reparto,
                // indicadores de IVA y socios: casi todo catálogo contable, y
                // no tiene un módulo propio donde vivir.
                { screen: 'reports.catalog_export', group: 'Reportes', label: 'Exportar catálogos', href: route('reports.catalog-export.index'), match: ['reports.catalog-export.*'] },

                { screen: 'accounting.chart_of_accounts', group: 'Catálogos', label: 'Catálogo de cuentas', href: route('chart-of-accounts.index'), match: ['chart-of-accounts.*'] },
                { screen: 'accounting.document_types', group: 'Catálogos', label: 'Tipos de documento', href: route('document-types.index'), match: ['document-types.*'] },
            ],
        },
        {
            label: 'Centros de costo y cambiario', icon: TagsIcon,
            match: ['cost-centers.*', 'cost-allocation-rules.*', 'exchange-rates.*', 'fx-revaluation.*',
                'reports.cost-center.*', 'reports.cost-allocation-rule.*'],
            children: [
                { screen: 'accounting.exchange_rates', group: 'Operación', label: 'Tipos de cambio', href: route('exchange-rates.index'), match: ['exchange-rates.*'] },
                { screen: 'accounting.fx_revaluation_run', group: 'Operación', label: 'Diferencial: ejecutar', href: route('fx-revaluation.create'), match: ['fx-revaluation.create', 'fx-revaluation.preview', 'fx-revaluation.store'] },
                { screen: 'accounting.fx_revaluation_history', group: 'Operación', label: 'Diferencial: historial', href: route('fx-revaluation.index'), match: ['fx-revaluation.index', 'fx-revaluation.show'] },
                { screen: 'reports.cost_center', group: 'Reportes', label: 'Auxiliar por centro de costo', href: route('reports.cost-center.index'), match: ['reports.cost-center.*'] },
                { screen: 'reports.cost_allocation_rule', group: 'Reportes', label: 'Reporte de normas de reparto', href: route('reports.cost-allocation-rule.index'), match: ['reports.cost-allocation-rule.*'] },
                { screen: 'accounting.cost_centers', group: 'Catálogos', label: 'Centros de costo', href: route('cost-centers.index'), match: ['cost-centers.*'] },
                { screen: 'accounting.cost_allocation_rules', group: 'Catálogos', label: 'Normas de reparto', href: route('cost-allocation-rules.index'), match: ['cost-allocation-rules.*'] },
            ],
        },
        {
            label: 'Inventario', icon: PackageIcon,
            match: ['items.*', 'item-groups.*', 'warehouses.*', 'units-of-measure.*', 'inventory-movements.*', 'gl-determinations.*', 'supplier-invoices.*', 'landed-costs.*', 'production-orders.*', 'warehouse-bins.*', 'stock-transfers.*', 'inventory-reports.*', 'price-lists.*', 'bills-of-materials.*', 'item-serials.*',
                'reports.inventory-valuation.*', 'reports.inventory-aging.*'],
            children: [
                // Veintiún pantallas: la operación se parte por área para que
                // cada grupo se lea de un vistazo.
                { screen: 'inventory.movements', group: 'Existencias', label: 'Movimientos', href: route('inventory-movements.index'), match: ['inventory-movements.*'] },
                { screen: 'inventory.transfers', group: 'Existencias', label: 'Traslados', href: route('stock-transfers.index'), match: ['stock-transfers.*'] },
                { screen: 'inventory.stock_counts', group: 'Existencias', label: 'Tomas físicas', href: route('stock-counts.index'), match: ['stock-counts.*'] },
                { screen: 'inventory.write_downs', group: 'Existencias', label: 'Deterioro (NIC 2)', href: route('inventory-write-downs.index'), match: ['inventory-write-downs.*'] },
                { screen: 'inventory.purchase_orders', group: 'Compras', label: 'Órdenes de compra', href: route('purchase-orders.index'), match: ['purchase-orders.*'] },
                { screen: 'inventory.reorder', group: 'Compras', label: 'Sugerencia de compra', href: route('reorder.index'), match: ['reorder.*'] },
                { screen: 'inventory.supplier_invoices', group: 'Compras', label: 'Facturas de proveedor', href: route('supplier-invoices.index'), match: ['supplier-invoices.*'] },
                { screen: 'inventory.landed_costs', group: 'Compras', label: 'Costos de importación', href: route('landed-costs.index'), match: ['landed-costs.*'] },
                { screen: 'inventory.import_costs', group: 'Compras', label: 'Rubros de nacionalización', href: route('import-costs.index'), match: ['import-costs.*'] },
                { screen: 'inventory.production_orders', group: 'Producción', label: 'Órdenes de fabricación', href: route('production-orders.index'), match: ['production-orders.*'] },
                { screen: 'inventory.bills_of_materials', group: 'Producción', label: 'Listas de materiales', href: route('bills-of-materials.index'), match: ['bills-of-materials.*'] },
                { screen: 'inventory.reports', group: 'Reportes', label: 'Reportes de inventario', href: route('inventory-reports.index'), match: ['inventory-reports.*'] },
                { screen: 'reports.inventory_valuation', group: 'Reportes', label: 'Existencias valorizadas', href: route('reports.inventory-valuation.index'), match: ['reports.inventory-valuation.*'] },
                { screen: 'reports.inventory_aging', group: 'Reportes', label: 'Antigüedad de inventario', href: route('reports.inventory-aging.index'), match: ['reports.inventory-aging.*'] },
                { screen: 'inventory.lot_expiry', group: 'Reportes', label: 'Lotes por vencer', href: route('lot-expiry.index'), match: ['lot-expiry.*'] },
                { screen: 'inventory.items', group: 'Catálogos', label: 'Artículos', href: route('items.index'), match: ['items.index', 'items.kardex', 'item-serials.*'] },
                { screen: 'inventory.item_groups', group: 'Catálogos', label: 'Grupos de artículos', href: route('item-groups.index'), match: ['item-groups.*'] },
                { screen: 'inventory.price_lists', group: 'Catálogos', label: 'Listas de precios', href: route('price-lists.index'), match: ['price-lists.*'] },
                { screen: 'inventory.warehouses', group: 'Catálogos', label: 'Almacenes', href: route('warehouses.index'), match: ['warehouses.*', 'warehouse-bins.*'] },
                { screen: 'inventory.units', group: 'Catálogos', label: 'Unidades de medida', href: route('units-of-measure.index'), match: ['units-of-measure.*'] },
                { screen: 'inventory.gl_determinations', group: 'Configuración', label: 'Determinación de cuentas', href: route('gl-determinations.index'), match: ['gl-determinations.*'] },
            ],
        },
        {
            label: 'Facturación', icon: ReceiptIcon,
            match: ['sales-documents.*', 'sales-orders.*', 'billing-settings.*', 'price-overrides.*'],
            children: [
                { screen: 'billing.sales_orders', group: 'Operación', label: 'Órdenes de pedido', href: route('sales-orders.index'), match: ['sales-orders.*'] },
                { screen: 'billing.sales_documents', group: 'Operación', label: 'Comprobantes', href: route('sales-documents.index'), match: ['sales-documents.index', 'sales-documents.show'] },
                { screen: 'billing.new_invoice', group: 'Operación', label: 'Nueva factura', href: route('sales-documents.create'), match: ['sales-documents.create'] },
                { screen: 'billing.price_overrides', group: 'Reportes', label: 'Cambios de precio autorizados', href: route('price-overrides.index'), match: ['price-overrides.*'] },
                { screen: 'billing.settings', group: 'Configuración', label: 'Parámetros de facturación', href: route('billing-settings.index'), match: ['billing-settings.*'] },
            ],
        },
        {
            label: 'Planillas', icon: UsersIcon,
            match: ['employees.*', 'payroll-periods.*', 'payslips.*', 'employee-deductions.*', 'recurring-inputs.*', 'personnel-actions.*', 'vacations.*', 'labor-settlements.*', 'payroll-reports.*', 'job-structure.*', 'payroll-settings.*'],
            children: [
                { screen: 'payroll.employees', group: 'Personal', label: 'Empleados', href: route('employees.index'), match: ['employees.*'] },
                { screen: 'payroll.personnel_actions', group: 'Personal', label: 'Acciones de personal', href: route('personnel-actions.index'), match: ['personnel-actions.*'] },
                { screen: 'payroll.vacations', group: 'Personal', label: 'Vacaciones', href: route('vacations.index'), match: ['vacations.*'] },
                { screen: 'payroll.labor_settlements', group: 'Personal', label: 'Liquidaciones laborales', href: route('labor-settlements.index'), match: ['labor-settlements.*'] },
                { screen: 'payroll.periods', group: 'Planilla', label: 'Períodos de planilla', href: route('payroll-periods.index'), match: ['payroll-periods.*', 'payslips.*'] },
                { screen: 'payroll.recurring_inputs', group: 'Planilla', label: 'Rubros fijos', href: route('recurring-inputs.index'), match: ['recurring-inputs.*'] },
                { screen: 'payroll.deductions', group: 'Planilla', label: 'Deducciones y préstamos', href: route('employee-deductions.index'), match: ['employee-deductions.*'] },
                { screen: 'payroll.reports', group: 'Reportes', label: 'Reportes de planilla', href: route('payroll-reports.index'), match: ['payroll-reports.*'] },
                { screen: 'payroll.job_structure', group: 'Configuración', label: 'Departamentos y puestos', href: route('job-structure.index'), match: ['job-structure.*', 'departments.*', 'job-positions.*'] },
                { screen: 'payroll.settings', group: 'Configuración', label: 'Parámetros de planilla', href: route('payroll-settings.index'), match: ['payroll-settings.*'] },
            ],
        },
        {
            label: 'Socios de negocio', icon: HandshakeIcon,
            match: ['business-partners.*', 'open-items.*', 'bp-categories.*', 'reports.aging.*'],
            children: [
                { screen: 'business_partners.partners', group: 'Operación', label: 'Socios de negocio', href: route('business-partners.index'), match: ['business-partners.index', 'business-partners.create', 'business-partners.edit'] },
                // Aplicar cobros y pagos es otro permiso que editar al socio (CLAUDE.md secc. 36).
                { screen: 'business_partners.open_items', group: 'Operación', label: 'Cobros y pagos', href: route('open-items.index'), match: ['open-items.*', 'business-partners.open-items*'] },
                // La antigüedad de saldos es de clientes y proveedores: su
                // módulo es este, no contabilidad.
                { screen: 'reports.aging', group: 'Reportes', label: 'Antigüedad de saldos', href: route('reports.aging.index'), match: ['reports.aging.*'] },
                { screen: 'business_partners.categories', group: 'Catálogos', label: 'Categorías de socios', href: route('bp-categories.index'), match: ['bp-categories.*'] },
            ],
        },
        {
            label: 'Bancos', icon: LandmarkIcon,
            match: ['bank-accounts.*', 'bank-reconciliations.*', 'bank-reconciliation-report.*', 'reports.cash-flow-projection.*'],
            children: [
                { screen: 'banking.reconciliations', group: 'Operación', label: 'Conciliaciones bancarias', href: route('bank-reconciliations.hub'), match: ['bank-reconciliations.*'] },
                { screen: 'banking.reconciliation_report', group: 'Reportes', label: 'Reporte de conciliaciones', href: route('bank-reconciliation-report.index'), match: ['bank-reconciliation-report.*'] },
                // Qué se espera cobrar y pagar: es tesorería, no contabilidad.
                { screen: 'reports.cash_flow_projection', group: 'Reportes', label: 'Proyección de cobros y pagos', href: route('reports.cash-flow-projection.index'), match: ['reports.cash-flow-projection.*'] },
                { screen: 'banking.accounts', group: 'Catálogos', label: 'Cuentas bancarias', href: route('bank-accounts.index'), match: ['bank-accounts.*'] },
            ],
        },
        {
            label: 'Impuestos', icon: PercentIcon,
            match: ['tax-rates.*', 'tax-report.*'],
            children: [
                { screen: 'tax.report', group: 'Reportes', label: 'Reporte de IVA', href: route('tax-report.index'), match: ['tax-report.*'] },
                { screen: 'tax.tax_rates', group: 'Catálogos', label: 'Indicadores de impuesto', href: route('tax-rates.index'), match: ['tax-rates.*'] },
            ],
        },
        // El Repositorio (CLAUDE.md secc. 39): los documentos de consulta que
        // publica el equipo de Pivox. Para cualquier rol, como el Panel: no es
        // una pantalla de la compañía, así que no lleva «screen».
        { label: 'Repositorio', href: route('repository.index'), match: ['repository.*'], icon: LibraryIcon },
        // Ya no hay un grupo «Reportes» de nivel superior: cada reporte vive
        // dentro del módulo que reporta.
        //
        // ── Por qué, y qué se pierde ────────────────────────────────────
        //
        // Un menú general de reportes obliga a saber de antemano que lo que se
        // busca es «un reporte» y no «algo de contabilidad». Quien quiere el
        // balance de comprobación lo busca en Contabilidad, y quien quiere la
        // antigüedad de saldos la busca en Socios de negocio. Inventario y
        // Planillas ya tenían los suyos adentro; esto termina de aplicar el
        // mismo criterio a los que quedaban sueltos.
        //
        // Con los permisos por pantalla, cada reporte se muestra si la persona
        // tiene ese reporte, sin importar el resto de la sección.
    ];

    const adminChildren = [];
    if (page.props.auth?.user?.can_manage_users) {
        adminChildren.push({ label: 'Usuarios', href: route('users.index'), match: ['users.*'] });
    }
    if (page.props.auth?.user?.can_manage_company) {
        adminChildren.push({ label: 'Apariencia', href: route('appearance.edit'), match: ['appearance.*'] });
        // La Bitácora (CLAUDE.md secc. 35): Superusuario y Administradores.
        adminChildren.push({ label: 'Bitácora', href: route('activity-log.index'), match: ['activity-log.*'] });
    }
    if (page.props.auth?.user?.is_super_admin) {
        adminChildren.push({ label: 'Agregar compañía', href: route('companies.create'), match: ['companies.*'] });
        // Los respaldos (CLAUDE.md secc. 37): toda la información de la
        // compañía, solo el Superusuario.
        adminChildren.push({ label: 'Respaldos', href: route('backups.index'), match: ['backups.*'] });
        // La facturación electrónica (CLAUDE.md secc. 38): las credenciales
        // del ATV y la llave de la compañía, solo el Superusuario.
        adminChildren.push({ label: 'Facturación electrónica', href: route('hacienda-settings.index'), match: ['hacienda-settings.*'] });
    }
    // `rail`: con el menú arriba, va en la columna de la izquierda, abajo,
    // junto a «Salir» (pedido del usuario): es de la compañía y de la persona,
    // no del trabajo diario.
    if (adminChildren.length) {
        items.push({
            label: 'Administración', icon: SettingsIcon, rail: true,
            match: adminChildren.flatMap((c) => c.match),
            children: adminChildren,
        });
    }

    // «Mi cuenta»: lo que es de la persona y no de la compañía, para
    // cualquier rol. Administración es de la compañía activa; esto no.
    const accountChildren = [
        { label: 'Mis datos', href: route('profile.edit'), match: ['profile.*'] },
    ];
    // Mientras la cuenta no sea dueña de una licencia: quien es Administrador
    // o Usuario en la de otra persona puede activar la suya. Con una ya
    // activada desaparece (una cuenta, una licencia).
    if (page.props.auth?.user?.owns_license === false) {
        accountChildren.push({ label: 'Activar una licencia', href: route('license-redemption.create'), match: ['license-redemption.*'] });
    }
    items.push({
        label: 'Mi cuenta', icon: CircleUserRoundIcon, rail: true,
        // Con foto de perfil subida, la foto va en lugar del ícono.
        photo: accountPhoto.value,
        match: accountChildren.flatMap((c) => c.match),
        children: accountChildren,
    });

    return items
        .map((item) => item.children
            ? { ...item, children: item.children.filter((child) => passesScreen(child.screen)) }
            : item)
        .filter((item) => (item.children ? item.children.length > 0 : true));
});

const ROLE_LABELS = {
    super_admin: 'Superusuario',
    admin: 'Administrador',
    user: 'Usuario',
};

const roleLabel = computed(() => ROLE_LABELS[page.props.auth?.user?.role_type] ?? null);

const PIVOX_VERSION = 'v1.0.0';
const currentYear = computed(() => new Date().getFullYear());

const collapsed = ref(false);
const theme = ref('light');

// Hasta 1024px (una tablet, incluso en horizontal) la barra lateral le quita
// al contenido el espacio que las tablas necesitan: pasa a ser un panel que
// se abre con el botón de menú, siempre completo —el modo colapsado es solo
// del escritorio—. Se cierra al navegar, al tocar fuera de él o con Escape.
const narrowQuery = typeof window !== 'undefined' ? window.matchMedia('(max-width: 1024px)') : null;
const narrow = ref(narrowQuery?.matches ?? false);
const navOpen = ref(false);

// El menú arriba o al lado (CLAUDE.md secc. 29): una preferencia de la
// persona en este navegador, como el tema, no un dato de la base. Se lee
// antes de pintar, así la página ya sale como la dejó.
const LAYOUT_KEY = 'pivox-layout';
const layout = ref(typeof window !== 'undefined' && localStorage.getItem(LAYOUT_KEY) === 'top' ? 'top' : 'side');

// Arriba, solo con lugar: en una pantalla angosta el menú es su panel.
const topLayout = computed(() => layout.value === 'top' && !narrow.value);

// Colapsado de verdad (solo íconos): en escritorio, con el menú al lado, y si
// el usuario lo pidió.
const compact = computed(() => collapsed.value && !narrow.value && !topLayout.value);

// Las secciones abren su ventana flotante: con el menú colapsado (al lado) y
// con el menú arriba (debajo de la sección).
const flyoutMode = computed(() => compact.value || topLayout.value);

function toggleLayout() {
    layout.value = layout.value === 'top' ? 'side' : 'top';
    localStorage.setItem(LAYOUT_KEY, layout.value);
    closeFlyout();
}

function onNarrowChange(event) {
    narrow.value = event.matches;
    if (!event.matches) navOpen.value = false;
}

function closeNavOnEscape(event) {
    if (event.key === 'Escape' && navOpen.value) navOpen.value = false;
}

watch(() => page.url, () => {
    navOpen.value = false;
    closeFlyout();
    closeCompanyMenu();
});

// ── Menú flotante del modo colapsado (CLAUDE.md secc. 29) ─────────────────
//
// Colapsada, la barra muestra solo íconos. Al pasar el mouse por uno —o al
// llegar a él con el teclado— se abre a su lado una ventana con el nombre de
// la sección y sus pantallas, agrupadas por las mismas categorías del menú
// abierto, para navegar sin tener que expandir la barra.
//
// Va teletransportado a <body> y con position: fixed: dentro de la barra, que
// se desplaza (overflow-y), quedaría recortado.
//
// Con el menú arriba es la misma ventana, debajo de la sección. Una sección
// sin pantallas (el Panel) ya muestra su nombre: no abre nada.
const flyout = ref(null); // { item, anchor, anchorTop, top, left, below }
const flyoutEl = ref(null);
let flyoutTimer = null;
let switchTimer = null;
// Al volver con Escape, el foco regresa al ícono: ese foco no reabre la ventana.
let skipFocusOpen = false;

// Debajo, si la sección está en la barra de arriba; al lado de su columna, si
// está en la barra lateral colapsada o en la columna de la izquierda del menú
// arriba (Administración, Mi cuenta).
function showFlyout(item, anchor) {
    const rect = anchor.getBoundingClientRect();
    const column = anchor.closest('.sidebar, .topbar');

    if (topLayout.value && column?.classList.contains('sidebar')) {
        flyout.value = { item, anchor, anchorTop: rect.bottom + 6, top: rect.bottom + 6, left: rect.left, below: true };
    } else {
        const columnRight = column?.getBoundingClientRect().right ?? rect.right;
        flyout.value = { item, anchor, anchorTop: rect.top, top: rect.top, left: columnRight + 6, below: false };
    }

    nextTick(positionFlyout);
}

// Lo que se abre al llegar a una sección: con el menú arriba, nada para una
// sin pantallas (y se cierra la que estaba abierta).
function opensFlyout(item) {
    if (! flyoutMode.value) return false;

    if (topLayout.value && ! item.children && ! item.sections) {
        scheduleCloseFlyout();
        return false;
    }

    return true;
}

// Con el teclado se abre en el acto.
function openFlyout(item, event) {
    if (! opensFlyout(item)) return;

    if (skipFocusOpen) {
        skipFocusOpen = false;
        return;
    }

    clearTimeout(flyoutTimer);
    clearTimeout(switchTimer);
    showFlyout(item, event.currentTarget);
}

// Con el mouse, cambiar de sección espera un momento: camino a un enlace de
// más abajo en la ventana, el cursor cruza en diagonal los íconos vecinos, y
// sin esa espera cada uno reemplazaría la ventana que se estaba buscando.
// Quedarse sobre el ícono sí cambia la sección; seguir de largo, no.
function hoverSection(item, event) {
    if (! opensFlyout(item)) return;

    clearTimeout(flyoutTimer);
    clearTimeout(switchTimer);

    const anchor = event.currentTarget;

    if (! flyout.value || flyout.value.item.label === item.label) {
        showFlyout(item, anchor);
        return;
    }

    switchTimer = setTimeout(() => showFlyout(item, anchor), 200);
}

function leaveSection() {
    clearTimeout(switchTimer);
    scheduleCloseFlyout();
}

// En una pantalla táctil no hay «pasar por encima»: el primer toque en una
// sección abre su ventana, y el segundo entra a la sección. Lo que cuenta es
// si ya estaba abierta ANTES de tocar: el toque también le da el foco, y el
// foco la abre.
let pointerType = 'mouse';
let openBeforeTouch = null;

function onSectionPointerdown(event) {
    pointerType = event.pointerType;
    openBeforeTouch = flyout.value?.item.label ?? null;
}

function onSectionClickCapture(item, event) {
    if (! flyoutMode.value || ! (item.children || item.sections) || pointerType === 'mouse' || openBeforeTouch === item.label) return;

    event.preventDefault();
    showFlyout(item, event.currentTarget);
}

// Tocar fuera la cierra: abierta con un toque, puede no tener el foco que al
// irse la cerraría.
function onOutsideFlyout(event) {
    if (flyout.value && ! flyoutEl.value?.contains(event.target) && ! flyout.value.anchor?.contains(event.target)) {
        closeFlyout();
    }
}

// Al lado: alineado con el ícono, pero sin salirse por abajo (Inventario tiene
// más de veinte pantallas y en una pantalla baja no cabría desde el ícono
// hacia abajo). Arriba: debajo de la sección, sin salirse por la derecha; si
// no cabe en la altura, se desplaza.
function positionFlyout() {
    if (! flyout.value || ! flyoutEl.value) return;

    if (flyout.value.below) {
        const width = flyoutEl.value.offsetWidth;
        flyout.value.left = Math.max(8, Math.min(flyout.value.left, window.innerWidth - 8 - width));

        return;
    }

    const height = flyoutEl.value.offsetHeight;
    const bottomLimit = window.innerHeight - 8;

    flyout.value.top = Math.max(8, Math.min(flyout.value.anchorTop, bottomLimit - height));
}

const flyoutStyle = computed(() => {
    if (! flyout.value) return null;

    const style = { top: `${flyout.value.top}px`, left: `${flyout.value.left}px` };

    if (flyout.value.below) {
        style.maxHeight = `calc(100dvh - ${flyout.value.top + 8}px)`;
    }

    return style;
});

// Lo que muestra la ventana: la sección, o con «Más» las que no cupieron.
const flyoutSections = computed(() => flyout.value?.item.sections ?? (flyout.value ? [flyout.value.item] : []));

// Se cierra con una pausa corta: es el tiempo de llevar el mouse del ícono a
// la ventana sin que desaparezca en el camino.
function scheduleCloseFlyout() {
    clearTimeout(flyoutTimer);
    flyoutTimer = setTimeout(closeFlyout, 250);
}

// Llegar a la ventana la sostiene, y cancela el cambio a la sección de un
// ícono que se cruzó en el camino.
function keepFlyout() {
    clearTimeout(flyoutTimer);
    clearTimeout(switchTimer);
}

function closeFlyout() {
    clearTimeout(flyoutTimer);
    clearTimeout(switchTimer);
    flyout.value = null;
}

function flyoutLinks() {
    return [...(flyoutEl.value?.querySelectorAll('a') ?? [])];
}

// Teclado desde el ícono: flecha derecha (o abajo) entra a la ventana; Escape
// la cierra. Con el menú arriba, la flecha abajo entra, y la izquierda y la
// derecha pasan a la sección de al lado.
function onSectionKeydown(event) {
    if (topLayout.value && (event.key === 'ArrowRight' || event.key === 'ArrowLeft')) {
        event.preventDefault();
        const sections = [...(topNavEl.value?.querySelectorAll('[data-top-section]:not(.is-overflow), [data-top-more]:not(.is-overflow)') ?? [])];
        const index = sections.indexOf(event.currentTarget);
        const step = event.key === 'ArrowRight' ? 1 : -1;
        sections[(index + step + sections.length) % sections.length]?.focus();

        return;
    }

    if (! flyout.value) return;

    if (event.key === 'ArrowDown' || (! topLayout.value && event.key === 'ArrowRight')) {
        event.preventDefault();
        flyoutLinks()[0]?.focus();
    } else if (event.key === 'Escape') {
        closeFlyout();
    }
}

// Dentro de la ventana: flechas para recorrerla; Escape o flecha izquierda
// vuelven al ícono.
function onFlyoutKeydown(event) {
    const links = flyoutLinks();
    const index = links.indexOf(document.activeElement);

    if (event.key === 'ArrowDown') {
        event.preventDefault();
        links[(index + 1) % links.length]?.focus();
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        links[(index - 1 + links.length) % links.length]?.focus();
    } else if (event.key === 'Escape' || event.key === 'ArrowLeft') {
        event.preventDefault();
        const anchor = flyout.value?.anchor;
        closeFlyout();
        skipFocusOpen = true;
        anchor?.focus();
    }
}

// Si el foco sale de la ventana a otro lado que no sea su ícono, se cierra.
function onFlyoutFocusOut(event) {
    const next = event.relatedTarget;
    if (next && (flyoutEl.value?.contains(next) || next === flyout.value?.anchor)) return;
    scheduleCloseFlyout();
}

// Al expandir la barra (o bajar el menú a su panel) ya no hace falta.
watch(flyoutMode, (value) => {
    if (! value) closeFlyout();
});

// ── El menú arriba: las secciones que caben, y «Más» ──────────────────────
//
// La barra no se desplaza de lado (CLAUDE.md secc. 28): las secciones que no
// caben después de la marca y el título van a «Más», que abre la misma
// ventana con todas ellas. Las que sobran siguen en la barra, invisibles y
// fuera del flujo: así se pueden medir otra vez cuando la ventana cambia.
const topNavEl = ref(null);
const visibleCount = ref(Infinity);
let topNavObserver = null;

// Con el menú arriba, la barra lleva el trabajo diario; Administración y Mi
// cuenta, la columna de la izquierda (`rail`). Al lado, todo junto.
const barNav = computed(() => (topLayout.value ? nav.value.filter((item) => ! item.rail) : nav.value));
const railNav = computed(() => (topLayout.value ? nav.value.filter((item) => item.rail) : []));

const overflowNav = computed(() => (topLayout.value ? barNav.value.slice(visibleCount.value) : []));
const moreItem = computed(() => ({ label: 'Más', sections: overflowNav.value }));

function fitTopNav() {
    const el = topNavEl.value;

    if (! topLayout.value || ! el) return;

    const sections = [...el.querySelectorAll('[data-top-section]')];
    const more = el.querySelector('[data-top-more]');
    const gap = parseFloat(getComputedStyle(el).columnGap) || 0;
    const widths = sections.map((section) => section.offsetWidth);
    const all = widths.reduce((sum, width) => sum + width, 0) + gap * Math.max(0, widths.length - 1);

    if (all <= el.clientWidth) {
        visibleCount.value = sections.length;
        return;
    }

    const room = el.clientWidth - (more?.offsetWidth ?? 0) - gap;
    let used = 0;
    let count = 0;

    for (const width of widths) {
        const next = used + (count ? gap : 0) + width;
        if (next > room) break;
        used = next;
        count++;
    }

    visibleCount.value = count;
}

function watchTopNav() {
    topNavObserver?.disconnect();
    topNavObserver = null;

    if (! topLayout.value || ! topNavEl.value) return;

    topNavObserver = new ResizeObserver(fitTopNav);
    topNavObserver.observe(topNavEl.value);
    fitTopNav();
}

watch(topLayout, () => nextTick(watchTopNav));
watch(() => barNav.value.length, () => nextTick(fitTopNav));

// Teclado en la columna: flecha derecha (o abajo) entra a la ventana, que se
// abre al lado; Escape la cierra. La flecha izquierda, dentro de la ventana,
// vuelve al ícono (onFlyoutKeydown).
function onRailKeydown(event) {
    if (! flyout.value) return;

    if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
        event.preventDefault();
        flyoutLinks()[0]?.focus();
    } else if (event.key === 'Escape') {
        closeFlyout();
    }
}

onMounted(() => {
    const storedSidebar = localStorage.getItem('pivox-sidebar');
    collapsed.value = storedSidebar === 'collapsed';

    const storedTheme = localStorage.getItem('pivox-theme');
    theme.value = storedTheme === 'dark' ? 'dark' : (storedTheme === 'light' ? 'light' : theme.value);

    narrowQuery?.addEventListener('change', onNarrowChange);
    window.addEventListener('keydown', closeNavOnEscape);
    document.addEventListener('pointerdown', onOutsideDial);
    document.addEventListener('pointerdown', onOutsideFlyout);
    document.addEventListener('pointerdown', onOutsideCompanyMenu);

    watchTopNav();
    // Con la tipografía del tema ya cargada, los nombres miden otra cosa.
    document.fonts?.ready.then(fitTopNav);
});

onBeforeUnmount(() => {
    narrowQuery?.removeEventListener('change', onNarrowChange);
    window.removeEventListener('keydown', closeNavOnEscape);
    document.removeEventListener('pointerdown', onOutsideDial);
    document.removeEventListener('pointerdown', onOutsideFlyout);
    document.removeEventListener('pointerdown', onOutsideCompanyMenu);
    topNavObserver?.disconnect();
    clearTimeout(companyTimer);
    clearTimeout(dialTimer);
});

function toggleSidebar() {
    collapsed.value = !collapsed.value;
    localStorage.setItem('pivox-sidebar', collapsed.value ? 'collapsed' : 'expanded');
}

function toggleTheme() {
    theme.value = theme.value === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', theme.value);
    localStorage.setItem('pivox-theme', theme.value);
}

const companies = computed(() => page.props.companies ?? []);
const currentCompanyId = computed(() => page.props.currentCompanyId);
const user = computed(() => page.props.auth?.user);

// El panel «Comentarios y noticias» (Components/Feedback/FeedbackPanel.vue).
// El punto del botón: hay una noticia del backoffice que todavía no se vio.
const feedbackOpen = ref(false);
const { hasUnseen: hasUnseenNews } = useNewsSeen();

// Conti, el asistente (CLAUDE.md secc. 32): su panel va al lado de la página
// (app.js) y ocupa el mismo lugar que «Comentarios y noticias», así que
// abrir uno cierra el otro.
const contiEnabled = computed(() => Boolean(page.props.conti?.enabled));
watch(feedbackOpen, (open) => { if (open) closeConti(); });

// El botón de Conti se abre en dos (CLAUDE.md secc. 32): el chat y escanear
// un documento. Con el mouse, al pasar por encima; en una pantalla táctil, al
// tocarlo. Se cierra al salir (con una pausa, para llegar a las opciones), al
// tocar fuera o con Escape.
const dial = ref(null);
const dialOpen = ref(false);
let dialTimer = null;

// Solo con un mouse de verdad: al tocar, el teléfono también avisa que «pasó
// por encima» justo antes del clic, y lo abriría y cerraría de una vez.
function openDial(event) {
    if (event.pointerType !== 'mouse') return;
    clearTimeout(dialTimer);
    dialOpen.value = true;
}

function closeDialSoon(event) {
    if (event.pointerType !== 'mouse') return;
    clearTimeout(dialTimer);
    dialTimer = setTimeout(() => { dialOpen.value = false; }, 350);
}

function onDialClick(event) {
    // Con el mouse ya se abrió al pasar: el clic no lo vuelve a cerrar.
    if (event.pointerType === 'mouse' && dialOpen.value) return;
    dialOpen.value = !dialOpen.value;
}

function openContiChat() {
    dialOpen.value = false;
    if (!conti.open) toggleConti();
}

function openContiScanner() {
    dialOpen.value = false;
    openScanner();
}

function onOutsideDial(event) {
    if (dialOpen.value && !dial.value?.contains(event.target)) dialOpen.value = false;
}
watch(() => conti.open, (open) => { if (open) feedbackOpen.value = false; });

const LICENSE_STATUS_LABELS = {
    active: { label: 'Vigente', cls: 'badge-success' },
    expiring_soon: { label: 'Por vencer', cls: 'badge-warning' },
    expired: { label: 'Vencida', cls: 'badge-warning' },
    suspended: { label: 'Suspendida', cls: 'badge-danger' },
    revoked: { label: 'Revocada', cls: 'badge-danger' },
};

const licenseStatus = computed(() => {
    const status = page.props.license?.display_status;
    return LICENSE_STATUS_LABELS[status] ?? { label: status, cls: 'badge' };
});

// El estado de la licencia de cada compañía, en el selector.
const LICENSE_STATE_SUFFIX = {
    expired: ' — licencia vencida',
    suspended: ' — licencia suspendida',
    revoked: ' — licencia revocada',
};

function isBlockedCompany(company) {
    return company.license_state === 'suspended' || company.license_state === 'revoked';
}

// El cambio va con su ventana de transición (CLAUDE.md secc. 30): tapa la
// pantalla con el nombre de la compañía nueva hasta que el cambio queda hecho.
function companyName(company) {
    return company ? (company.trade_name || company.legal_name) : null;
}

const currentCompany = computed(() => companies.value.find((c) => String(c.id) === String(currentCompanyId.value)) ?? null);

function switchTo(to, revert = () => {}) {
    if (! to || String(to.id) === String(currentCompanyId.value)) return;

    startCompanySwitch({
        // La ventana ya se pinta con el tema de la compañía a la que se va.
        to: { id: to.id, name: companyName(to), theme: to.theme || 'marino', licenseState: to.license_state ?? 'ok' },
        from: currentCompany.value ? { id: currentCompany.value.id, name: companyName(currentCompany.value) } : null,
        revert,
    });
}

function switchCompany(event) {
    const select = event.target;

    // Si se queda en la anterior, el selector vuelve a mostrarla: con :value
    // de solo ida, Vue no lo corregiría solo.
    switchTo(companies.value.find((c) => String(c.id) === String(select.value)), () => { select.value = String(currentCompanyId.value); });
}

// ── La compañía, con el menú arriba ────────────────────────────────────────
//
// La columna de la izquierda es solo de íconos (pedido del usuario): la
// compañía es uno que abre a su lado la lista para cambiar, al pasar el mouse
// o al tocarlo, y con el teclado (Enter, Espacio, flecha abajo o derecha). La
// lista dice en cuál se está y con qué rol: la columna ya no tiene lugar para
// el nombre ni para la etiqueta del rol.
//
// Sin `title` en el ícono: el tooltip nativo taparía la lista (como en el menú
// colapsado). El nombre va en su aria-label.
const companyBtn = ref(null);
const companyMenuEl = ref(null);
const companyMenu = ref(null); // { top, left }
let companyTimer = null;
let companyPointer = 'mouse';

function companyItems() {
    return [...(companyMenuEl.value?.querySelectorAll('button:not(:disabled)') ?? [])];
}

function openCompanyMenu(focusItem = false) {
    clearTimeout(companyTimer);

    const rect = companyBtn.value.getBoundingClientRect();
    const railRight = companyBtn.value.closest('.topbar')?.getBoundingClientRect().right ?? rect.right;
    companyMenu.value = { top: rect.top, left: railRight + 6 };

    nextTick(() => {
        positionCompanyMenu();

        if (focusItem) {
            const items = companyItems();
            (items.find((item) => item.getAttribute('aria-checked') === 'true') ?? items[0])?.focus();
        }
    });
}

// Alineada con el ícono, sin salirse por abajo.
function positionCompanyMenu() {
    if (! companyMenu.value || ! companyMenuEl.value) return;

    companyMenu.value.top = Math.max(8, Math.min(companyMenu.value.top, window.innerHeight - 8 - companyMenuEl.value.offsetHeight));
}

function closeCompanyMenu() {
    clearTimeout(companyTimer);
    companyMenu.value = null;
}

// Con una pausa corta: el tiempo de llevar el mouse del ícono a la lista.
function scheduleCloseCompanyMenu() {
    clearTimeout(companyTimer);
    companyTimer = setTimeout(closeCompanyMenu, 250);
}

function keepCompanyMenu() {
    clearTimeout(companyTimer);
}

function enterCompany(event) {
    if (event.pointerType === 'mouse') openCompanyMenu();
}

function leaveCompany(event) {
    if (event.pointerType === 'mouse') scheduleCloseCompanyMenu();
}

function onCompanyPointerdown(event) {
    companyPointer = event.pointerType;
}

function onCompanyClick() {
    // Con el mouse ya se abrió al pasar: el clic no la vuelve a cerrar.
    if (companyPointer === 'mouse' && companyMenu.value) return;

    companyMenu.value ? closeCompanyMenu() : openCompanyMenu();
}

function onCompanyKeydown(event) {
    if (['Enter', ' ', 'ArrowDown', 'ArrowRight'].includes(event.key)) {
        event.preventDefault();
        openCompanyMenu(true);
    } else if (event.key === 'Escape') {
        closeCompanyMenu();
    }
}

// Dentro de la lista: flechas para recorrerla; Escape o flecha izquierda
// vuelven al ícono.
function onCompanyMenuKeydown(event) {
    const items = companyItems();
    const index = items.indexOf(document.activeElement);

    if (event.key === 'ArrowDown') {
        event.preventDefault();
        items[(index + 1) % items.length]?.focus();
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        items[(index - 1 + items.length) % items.length]?.focus();
    } else if (event.key === 'Escape' || event.key === 'ArrowLeft') {
        event.preventDefault();
        closeCompanyMenu();
        companyBtn.value?.focus();
    }
}

// Si el foco sale de la lista a otro lado que no sea su ícono, se cierra.
function onCompanyMenuFocusOut(event) {
    const next = event.relatedTarget;
    if (next && (companyMenuEl.value?.contains(next) || next === companyBtn.value)) return;
    scheduleCloseCompanyMenu();
}

function onOutsideCompanyMenu(event) {
    if (companyMenu.value && ! companyMenuEl.value?.contains(event.target) && ! companyBtn.value?.contains(event.target)) {
        closeCompanyMenu();
    }
}

function chooseCompany(company) {
    closeCompanyMenu();
    switchTo(company);
}

watch(topLayout, (value) => {
    if (! value) closeCompanyMenu();
});

// Los hijos de una sección, juntos por categoría y en el orden en que se
// definieron. Con una sola categoría (Administración) no hay subtítulos.
function childGroups(item) {
    const groups = [];

    for (const child of item.children) {
        const label = child.group ?? null;
        let group = groups.find((g) => g.label === label);

        if (! group) {
            group = { label, children: [] };
            groups.push(group);
        }

        group.children.push(child);
    }

    return groups;
}

// `match` es opcional en una entrada de menú: sin él, la entrada simplemente
// nunca se resalta. Pero esto se llama al pintar el menú, y el menú está en
// TODAS las pantallas — así que un `match` olvidado en una sola entrada
// reventaba la aplicación completa con un «Cannot read properties of
// undefined», sin pista de cuál entrada era.
//
// Pasó de verdad al agregar los libros legales. Una propiedad opcional que
// falta no puede costar la aplicación entera.
function isCurrent(patterns) {
    return (patterns ?? []).some((pattern) => route().current(pattern));
}

// Grupos del menú (ítems con "children", ej. Bancos): se abren solos cuando
// la ruta activa cae dentro del grupo, pero una vez que el usuario los
// abre/cierra a mano, esa elección manda por el resto de la sesión.
const manualGroupState = ref({});

function isGroupExpanded(item) {
    const manual = manualGroupState.value[item.label];
    return manual !== undefined ? manual : isCurrent(item.match);
}

function toggleGroup(item) {
    manualGroupState.value[item.label] = ! isGroupExpanded(item);
}
</script>

<template>
    <div class="app-shell" :class="{ 'is-collapsed': compact, 'nav-open': navOpen, 'layout-top': topLayout }">
        <aside id="app-nav" class="sidebar" @scroll.passive="closeFlyout">
            <div class="sidebar-brand">
                <span class="brand-mark"><img src="/images/logos/logo_pivox_corto.png" :alt="compact ? 'Pivox' : ''"></span>
                <img v-if="!compact" class="brand-name" src="/images/logos/pivox_nombre_blanco.png" alt="Pivox">
                <!-- Con el menú arriba, el título de la pantalla va acá, junto a
                     la marca: la columna de la izquierda no tiene dónde. -->
                <h1 v-if="topLayout" class="brand-title" :title="title">{{ title }}</h1>
                <button type="button" class="nav-close" aria-label="Cerrar menú" @click="navOpen = false"><XIcon :size="18" /></button>
            </div>

            <nav ref="topNavEl" class="sidebar-nav" aria-label="Menú principal">
                <template v-for="(item, index) in barNav" :key="item.label">
                    <!-- Colapsado se ve solo el ícono: el nombre queda como
                         tooltip y como etiqueta para el lector de pantalla.
                         Arriba, ícono y nombre; las que no caben, en «Más». -->
                    <Link
                        v-if="!item.children || flyoutMode"
                        :href="item.children ? item.children[0].href : item.href"
                        class="sidebar-link"
                        :class="{ active: isCurrent(item.match), 'flyout-open': flyout?.item.label === item.label, 'is-overflow': topLayout && index >= visibleCount }"
                        :data-top-section="topLayout ? '' : null"
                        :aria-label="compact ? item.label : null"
                        :aria-haspopup="flyoutMode && item.children ? 'true' : null"
                        :aria-expanded="flyoutMode && item.children ? flyout?.item.label === item.label : null"
                        @mouseenter="hoverSection(item, $event)"
                        @mouseleave="leaveSection"
                        @focus="openFlyout(item, $event)"
                        @blur="scheduleCloseFlyout"
                        @keydown="onSectionKeydown"
                        @pointerdown="onSectionPointerdown"
                        @click.capture="onSectionClickCapture(item, $event)"
                    >
                        <span class="sidebar-icon">
                            <img v-if="item.photo" :src="item.photo" class="sidebar-photo" alt="" @error="photoFailed = true">
                            <component :is="item.icon" v-else :size="18" />
                        </span>
                        <span v-if="!compact" class="sidebar-label">{{ item.label }}</span>
                        <ChevronDownIcon v-if="topLayout && item.children" class="top-chevron" :size="14" />
                    </Link>

                    <div v-else class="sidebar-group">
                        <button
                            type="button"
                            class="sidebar-link sidebar-group-toggle"
                            :class="{ active: isCurrent(item.match), 'is-open': isGroupExpanded(item) }"
                            :aria-expanded="isGroupExpanded(item)"
                            @click="toggleGroup(item)"
                        >
                            <span class="sidebar-icon-chip">
                                <img v-if="item.photo" :src="item.photo" class="sidebar-photo" alt="" @error="photoFailed = true">
                                <component :is="item.icon" v-else :size="16" />
                            </span>
                            <span class="sidebar-label">{{ item.label }}</span>
                            <ChevronRightIcon class="sidebar-chevron" :class="{ open: isGroupExpanded(item) }" />
                        </button>

                        <div class="sidebar-subnav-wrap" :class="{ open: isGroupExpanded(item) }">
                            <div class="sidebar-subnav">
                                <template v-for="group in childGroups(item)" :key="group.label ?? 'todo'">
                                    <span v-if="group.label && childGroups(item).length > 1" class="sidebar-subgroup">{{ group.label }}</span>
                                    <Link
                                        v-for="child in group.children"
                                        :key="child.label"
                                        :href="child.href"
                                        class="sidebar-sublink"
                                        :class="{ active: isCurrent(child.match) }"
                                    >
                                        <span class="sublink-node"></span>
                                        <span class="sidebar-label">{{ child.label }}</span>
                                    </Link>
                                </template>
                            </div>
                        </div>
                    </div>
                </template>

                <!-- Siempre está con el menú arriba, para poder medirla; sin
                     secciones que no caben, invisible. -->
                <button
                    v-if="topLayout"
                    type="button"
                    data-top-more
                    class="sidebar-link top-more"
                    :class="{
                        active: overflowNav.some((item) => isCurrent(item.match)),
                        'flyout-open': flyout?.item.label === 'Más',
                        'is-overflow': ! overflowNav.length,
                    }"
                    aria-haspopup="true"
                    :aria-expanded="flyout?.item.label === 'Más'"
                    @mouseenter="hoverSection(moreItem, $event)"
                    @mouseleave="leaveSection"
                    @focus="openFlyout(moreItem, $event)"
                    @blur="scheduleCloseFlyout"
                    @keydown="onSectionKeydown"
                    @pointerdown="onSectionPointerdown"
                    @click="showFlyout(moreItem, $event.currentTarget)"
                >
                    <span class="sidebar-label">Más</span>
                    <ChevronDownIcon class="top-chevron" :size="14" />
                </button>
            </nav>

            <button
                v-if="!topLayout"
                type="button"
                class="sidebar-collapse-btn"
                :aria-label="collapsed ? 'Expandir menú' : 'Colapsar menú'"
                :title="collapsed ? 'Expandir menú' : null"
                @click="toggleSidebar"
            >
                <PanelLeftOpenIcon v-if="collapsed" :size="18" />
                <template v-else><PanelLeftCloseIcon :size="18" /> Colapsar</template>
            </button>
        </aside>

        <div class="nav-backdrop" @click="navOpen = false" />

        <div class="app-main" :class="{ 'conti-docked': contiEnabled && conti.open, 'has-conti-fab': contiEnabled }">
            <!-- Con el menú arriba es la columna de la izquierda: lo mismo,
                 apilado y solo con los íconos. -->
            <header class="topbar">
                <div v-if="!topLayout" class="topbar-heading">
                    <button
                        type="button"
                        class="nav-toggle"
                        aria-controls="app-nav"
                        :aria-expanded="navOpen"
                        aria-label="Abrir menú"
                        @click="navOpen = true"
                    >
                        <MenuIcon :size="20" />
                    </button>

                    <h1 class="topbar-title">{{ title }}</h1>
                </div>

                <div class="topbar-actions">
                    <!-- Con el menú arriba, la compañía es un ícono que abre la
                         lista al lado (la columna es solo de íconos). -->
                    <button
                        v-if="companies.length && topLayout"
                        ref="companyBtn"
                        type="button"
                        class="btn btn-ghost topbar-icon-btn company-btn"
                        :class="{ 'is-open': companyMenu }"
                        aria-haspopup="menu"
                        :aria-expanded="Boolean(companyMenu)"
                        :aria-label="`Compañía: ${companyName(currentCompany) ?? '—'}. Cambiar de compañía`"
                        @pointerenter="enterCompany"
                        @pointerleave="leaveCompany"
                        @pointerdown="onCompanyPointerdown"
                        @click="onCompanyClick"
                        @keydown="onCompanyKeydown"
                    >
                        <Building2Icon />
                    </button>

                    <select
                        v-if="companies.length && !topLayout"
                        class="company-select"
                        aria-label="Compañía"
                        :value="currentCompanyId"
                        @change="switchCompany"
                    >
                        <!-- Con la licencia suspendida o revocada no se puede
                             entrar: se ve marcada y no se elige. Vencida sí
                             (modo de gracia), avisando. -->
                        <option
                            v-for="c in companies"
                            :key="c.id"
                            :value="c.id"
                            :disabled="isBlockedCompany(c) && c.id !== currentCompanyId"
                        >
                            {{ c.trade_name || c.legal_name }}{{ LICENSE_STATE_SUFFIX[c.license_state] ?? '' }}
                        </option>
                    </select>

                    <button type="button" class="btn btn-ghost topbar-icon-btn" title="Cambiar tema" aria-label="Cambiar tema" @click="toggleTheme">
                        <SunIcon v-if="theme === 'dark'" />
                        <MoonIcon v-else />
                    </button>

                    <!-- El menú arriba o al lado: solo con lugar para elegir
                         (en una pantalla angosta, el menú siempre es su panel). -->
                    <button
                        type="button"
                        class="btn btn-ghost topbar-icon-btn layout-btn"
                        :title="topLayout ? 'Menú al lado' : 'Menú arriba'"
                        :aria-label="topLayout ? 'Poner el menú al lado' : 'Poner el menú arriba'"
                        @click="toggleLayout"
                    >
                        <PanelLeftIcon v-if="topLayout" />
                        <PanelTopIcon v-else />
                    </button>

                    <!-- Con el menú arriba, el rol va en la lista de compañías. -->
                    <span v-if="roleLabel && !topLayout" class="badge badge-role">{{ roleLabel }}</span>

                    <button
                        v-if="user"
                        type="button"
                        class="btn btn-ghost topbar-icon-btn feedback-trigger"
                        :class="{ 'is-open': feedbackOpen }"
                        aria-controls="feedback-panel"
                        :aria-expanded="feedbackOpen"
                        :aria-label="hasUnseenNews ? 'Comentarios y noticias (hay noticias nuevas)' : 'Comentarios y noticias'"
                        title="Comentarios y noticias"
                        @click="feedbackOpen = !feedbackOpen"
                    >
                        <MessagesSquareIcon />
                        <span v-if="hasUnseenNews" class="topbar-dot" aria-hidden="true" />
                    </button>

                    <!-- Con el menú arriba, abajo en la columna: Administración y
                         Mi cuenta, junto a «Salir». Abren su ventana al lado,
                         como la barra colapsada; sin `title`, que la taparía. -->
                    <template v-if="topLayout">
                        <span class="rail-spacer" aria-hidden="true" />
                        <Link
                            v-for="item in railNav"
                            :key="item.label"
                            :href="item.children[0].href"
                            class="btn btn-ghost topbar-icon-btn rail-section"
                            :class="{ active: isCurrent(item.match), 'flyout-open': flyout?.item.label === item.label }"
                            :aria-label="item.label"
                            aria-haspopup="true"
                            :aria-expanded="flyout?.item.label === item.label"
                            @mouseenter="hoverSection(item, $event)"
                            @mouseleave="leaveSection"
                            @focus="openFlyout(item, $event)"
                            @blur="scheduleCloseFlyout"
                            @keydown="onRailKeydown"
                            @pointerdown="onSectionPointerdown"
                            @click.capture="onSectionClickCapture(item, $event)"
                        >
                            <img v-if="item.photo" :src="item.photo" class="rail-photo" alt="" @error="photoFailed = true">
                            <component :is="item.icon" v-else />
                        </Link>
                    </template>

                    <Link
                        v-if="user"
                        :href="route('logout')"
                        method="delete"
                        as="button"
                        class="btn btn-ghost logout-btn"
                        title="Salir"
                    >
                        <LogOutIcon />
                        <span class="logout-label">Salir</span>
                    </Link>
                </div>
            </header>

            <!-- Los avisos y el contenido, juntos: con el menú arriba son la
                 columna de la derecha de la columna de la sesión. -->
            <div class="app-body">
                <div v-if="page.props.licenseGrace" class="flash flash-warning">
                    La licencia de tu compañía está vencida. Podés consultar y exportar información, pero no crear ni modificar registros hasta renovarla.
                </div>

                <div v-if="page.props.flash?.success" class="flash flash-success">
                    {{ page.props.flash.success }}
                </div>
                <div v-if="page.props.flash?.error" class="flash flash-error">
                    {{ page.props.flash.error }}
                </div>

                <main class="content">
                    <slot />
                </main>
            </div>

            <!-- El menú flotante de la barra colapsada: fuera de la barra para
                 que su desplazamiento no lo recorte. -->
            <Teleport to="body">
                <nav
                    v-if="flyout"
                    ref="flyoutEl"
                    class="sidebar-flyout"
                    :class="{ 'is-below': flyout.below }"
                    :style="flyoutStyle"
                    :aria-label="flyout.item.label"
                    @mouseenter="keepFlyout"
                    @mouseleave="scheduleCloseFlyout"
                    @focusin="keepFlyout"
                    @focusout="onFlyoutFocusOut"
                    @keydown="onFlyoutKeydown"
                >
                    <!-- Una sección; con «Más», cada una de las que no cupieron. -->
                    <template v-for="section in flyoutSections" :key="section.label">
                        <Link
                            v-if="!section.children"
                            :href="section.href"
                            class="flyout-title flyout-title-link"
                            :class="{ active: isCurrent(section.match) }"
                        >{{ section.label }}</Link>

                        <template v-else>
                            <span class="flyout-title">{{ section.label }}</span>
                            <template v-for="group in childGroups(section)" :key="group.label ?? 'todo'">
                                <span v-if="group.label && childGroups(section).length > 1" class="flyout-group">{{ group.label }}</span>
                                <Link
                                    v-for="child in group.children"
                                    :key="child.label"
                                    :href="child.href"
                                    class="flyout-link"
                                    :class="{ active: isCurrent(child.match) }"
                                    :aria-current="isCurrent(child.match) ? 'page' : null"
                                >{{ child.label }}</Link>
                            </template>
                        </template>
                    </template>
                </nav>

                <!-- La lista de compañías del menú arriba, al lado de su ícono. -->
                <div
                    v-if="companyMenu"
                    ref="companyMenuEl"
                    class="company-menu"
                    role="menu"
                    aria-label="Compañías"
                    :style="{ top: `${companyMenu.top}px`, left: `${companyMenu.left}px` }"
                    @pointerenter="keepCompanyMenu"
                    @pointerleave="leaveCompany"
                    @keydown="onCompanyMenuKeydown"
                    @focusout="onCompanyMenuFocusOut"
                >
                    <div class="company-menu-head">
                        <span>Compañía</span>
                        <span v-if="roleLabel" class="badge badge-role">{{ roleLabel }}</span>
                    </div>
                    <!-- Con la licencia suspendida o revocada no se puede
                         entrar: se ve marcada y no se elige. Vencida sí
                         (modo de gracia), avisando. -->
                    <button
                        v-for="c in companies"
                        :key="c.id"
                        type="button"
                        role="menuitemradio"
                        class="company-menu-item"
                        :class="{ current: String(c.id) === String(currentCompanyId) }"
                        :aria-checked="String(c.id) === String(currentCompanyId)"
                        :disabled="isBlockedCompany(c) && c.id !== currentCompanyId"
                        @click="chooseCompany(c)"
                    >
                        <CheckIcon v-if="String(c.id) === String(currentCompanyId)" class="company-menu-check" :size="15" />
                        <span v-else class="company-menu-check" aria-hidden="true" />
                        <span class="company-menu-name">
                            {{ companyName(c) }}
                            <small v-if="LICENSE_STATE_SUFFIX[c.license_state]">{{ LICENSE_STATE_SUFFIX[c.license_state].replace(' — ', '') }}</small>
                        </span>
                    </button>
                </div>
            </Teleport>

            <FeedbackPanel v-if="user" :open="feedbackOpen" @close="feedbackOpen = false" />

            <!-- El pie, y encima Conti, el asistente (Components/Conti/
                 ContiPanel.vue): un botón flotante en la esquina inferior
                 derecha. Va anclado al pie y no a la ventana: así queda
                 justo arriba de él, mida lo que mida. Con un panel de la
                 derecha abierto (Conti o «Comentarios y noticias») no se
                 muestra: el panel ocupa ese lugar. -->
            <div class="app-bottom">
                <div
                    v-if="contiEnabled"
                    v-show="!conti.open && !feedbackOpen"
                    ref="dial"
                    class="conti-dial"
                    :class="{ 'is-open': dialOpen }"
                    @pointerenter="openDial"
                    @pointerleave="closeDialSoon"
                    @keydown.escape="dialOpen = false"
                >
                    <button
                        type="button"
                        class="conti-dial-option is-chat"
                        :tabindex="dialOpen ? 0 : -1"
                        :aria-hidden="!dialOpen"
                        aria-controls="conti-panel"
                        aria-label="Abrir el chat de Conti"
                        @click="openContiChat"
                    >
                        <MessageCircleIcon :size="20" />
                        <span class="conti-dial-label">Chat</span>
                    </button>
                    <button
                        type="button"
                        class="conti-dial-option is-scan"
                        :tabindex="dialOpen ? 0 : -1"
                        :aria-hidden="!dialOpen"
                        aria-label="Escanear un documento"
                        @click="openContiScanner"
                    >
                        <ScanLineIcon :size="20" />
                        <span class="conti-dial-label">Escanear</span>
                    </button>
                    <button
                        type="button"
                        class="conti-fab"
                        :aria-expanded="dialOpen"
                        title="Conti, el asistente"
                        aria-label="Conti, el asistente"
                        @click="onDialClick"
                    >
                        <BotIcon :size="26" />
                    </button>
                </div>

                <footer v-if="page.props.license" class="app-footer">
                    <span>Licencia {{ page.props.license.category ?? '—' }} · {{ page.props.license.masked_code }} · Vence {{ page.props.license.expires_at }}</span>
                    <span class="badge" :class="licenseStatus.cls">{{ licenseStatus.label }}</span>
                    <span class="app-footer-meta">Pivox {{ PIVOX_VERSION }} · © {{ currentYear }}</span>
                </footer>
            </div>
        </div>
    </div>
</template>

<style scoped>
/*
    height (no min-height) + overflow: hidden en los dos hijos scrolleables
    abajo: el shell entero ocupa exactamente el viewport y NUNCA scrollea
    como documento — si lo hiciera, el sidebar (una columna del grid) se
    scrollearía junto con el contenido y desaparecería de la vista al bajar
    por una tabla larga. En su lugar, cada columna scrollea la suya: el
    sidebar internamente si el menú no entra, y .content internamente para
    el contenido de la página.

    100dvh después de 100vh: en el navegador de un teléfono, 100vh incluye el
    área que tapa la barra de direcciones y el final de la página quedaría
    debajo de ella. El navegador que no entiende dvh se queda con vh.
*/
.app-shell {
    display: grid;
    grid-template-columns: var(--sidebar-width) minmax(0, 1fr);
    height: 100vh;
    height: 100dvh;
    transition: grid-template-columns .15s ease;
}

.app-shell.is-collapsed {
    grid-template-columns: var(--sidebar-width-collapsed) minmax(0, 1fr);
}

.sidebar {
    background: var(--color-sidebar);
    color: var(--color-on-sidebar);
    display: flex;
    flex-direction: column;
    padding: 0.75rem 0.6rem;
    height: 100vh;
    height: 100dvh;
    overflow-y: auto;
}

.sidebar-brand {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.4rem 0.4rem 1rem;
}

/* El ícono va sobre blanco: el azul del logo se perdería en las barras
   azules de algunos temas. El nombre es la versión en blanco del logo. */
.brand-mark {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    width: 28px;
    height: 28px;
    padding: 4px;
    border-radius: 8px;
    background: #fff;
}

.brand-mark img {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: contain;
}

.brand-name {
    display: block;
    height: 16px;
    width: auto;
}

.sidebar-nav {
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
    flex: 1;
}

.sidebar-link {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    padding: 0.46rem 0.6rem;
    border-radius: var(--radius-sm);
    text-decoration: none;
    color: rgba(244, 246, 250, 0.82);
    font-size: 0.83rem;
    font-weight: 600;
}

.sidebar-link:hover {
    background: rgba(255, 255, 255, 0.08);
    color: var(--color-on-sidebar);
}

.sidebar-link.active {
    background: rgba(255, 255, 255, 0.16);
    color: var(--color-on-sidebar);
}

.sidebar-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.25rem;
    flex-shrink: 0;
}

/*
    La foto de perfil en lugar del ícono de «Mi cuenta». Un círculo de 26px —el
    tamaño de la ficha de un ícono de sección— para que se reconozca la cara.
    Es más alta que un ícono (18px), así que lleva márgenes negativos: ocupa
    en la fila el mismo alto que un ícono y la fila de «Mi cuenta» no queda
    más alta que las demás.
*/
.sidebar-photo {
    display: block;
    flex-shrink: 0;
    width: 26px;
    height: 26px;
    border-radius: 50%;
    object-fit: cover;
    box-shadow: 0 0 0 1px rgba(255, 255, 255, 0.28);
}

.sidebar-icon .sidebar-photo {
    margin: -4px -3px;
}

/*
    Colapsado, cada fila del menú es solo un ícono: va centrado en el ancho
    de la barra, no pegado a la izquierda como cuando lo sigue el nombre. Lo
    mismo la marca de arriba y el botón de expandir de abajo.
*/
.is-collapsed .sidebar-brand,
.is-collapsed .sidebar-link,
.is-collapsed .sidebar-collapse-btn {
    justify-content: center;
    padding-left: 0;
    padding-right: 0;
}

/*
    Distingue de un vistazo un TÍTULO MAYOR (una sección, ej. "Contabilidad")
    de sus DESPLEGABLES (los destinos concretos adentro, ej. "Catálogo de
    cuentas"): el título mayor lleva su ícono en una "ficha" propia y texto
    con más peso/tracking; al abrirse, sus hijos cuelgan de una línea
    conectora fina con un punto por ítem — el mismo lenguaje visual de un
    árbol de archivos, no una lista plana repetida más chica.
*/
.sidebar-group-toggle {
    width: 100%;
    background: transparent;
    border: none;
    cursor: pointer;
    font: inherit;
    position: relative;
    text-align: left;
}

/*
    Criterio de diseño (revisado): el acento naranjo-marrón es la EXCEPCIÓN,
    no la norma — solo marca la sección que tenés abierta ahora mismo. Si
    los seis títulos estuvieran siempre naranjas (como en el intento
    anterior), el color deja de significar nada: todo compite por la misma
    atención y el sidebar se ve saturado en vez de organizado. Por defecto,
    los títulos usan el mismo blanco/gris neutro que ya usa el resto de la
    app — legible, calmo, profesional — y el naranjo aparece únicamente
    cuando hay algo real que señalar: "estás acá".
*/
.sidebar-group-toggle .sidebar-label {
    font-weight: 800;
    letter-spacing: .01em;
}

.sidebar-icon-chip {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    flex-shrink: 0;
    border-radius: 8px;
    background: rgba(255, 255, 255, 0.10);
    transition: background-color .15s ease, color .15s ease;
}

.sidebar-group-toggle.is-open {
    background: color-mix(in srgb, var(--color-accent) 12%, transparent);
    box-shadow: inset 3px 0 0 0 var(--color-accent);
}

.sidebar-group-toggle.is-open .sidebar-icon-chip {
    background: color-mix(in srgb, var(--color-accent) 28%, transparent);
    color: var(--color-accent);
}

.sidebar-group-toggle.is-open .sidebar-label {
    color: var(--color-accent);
}

.sidebar-chevron {
    flex-shrink: 0;
    margin-left: auto;
    color: rgba(244, 246, 250, 0.5);
    transition: transform .15s ease, color .15s ease;
}

.sidebar-chevron.open {
    transform: rotate(90deg);
    color: var(--color-accent);
}

/* Truco de acordeón sin JS: animar grid-template-rows de 0fr a 1fr colapsa
   y expande con altura real (a diferencia de max-height, que siempre deja
   una duración de transición "de mentira" si el contenido es más chico). */
.sidebar-subnav-wrap {
    display: grid;
    grid-template-rows: 0fr;
    transition: grid-template-rows .22s ease;
}

.sidebar-subnav-wrap.open {
    grid-template-rows: 1fr;
}

.sidebar-subnav {
    overflow: hidden;
    min-height: 0;
    display: flex;
    flex-direction: column;
    gap: 0.05rem;
    margin: 0.15rem 0 0.2rem;
    padding-left: 1.5rem;
    position: relative;
}

/* La línea conectora: nace justo debajo de la ficha del título mayor y
   corre detrás de todos sus hijos, para que se lea "todo esto cuelga de
   ahí arriba" sin tener que repetir el ícono en cada fila. */
.sidebar-subnav::before {
    content: '';
    position: absolute;
    left: 0.72rem;
    top: 0;
    bottom: 0.6rem;
    width: 1px;
    background: rgba(255, 255, 255, 0.14);
}

/* Neutro por defecto (mismo criterio de arriba) — el naranjo queda
   reservado para .active, la página en la que estás parado ahora mismo. Es
   la única forma de que "estoy acá" se note: si los otros seis ítems
   también fueran naranjas a media intensidad, distinguir cuál es el activo
   de verdad requeriría fijarse en el matiz exacto, no en un vistazo. */
.sidebar-sublink {
    position: relative;
    display: flex;
    align-items: center;
    gap: 0.55rem;
    font-size: 0.78rem;
    font-weight: 500;
    padding: 0.4rem 0.6rem;
    border-radius: var(--radius-sm);
    text-decoration: none;
    color: rgba(244, 246, 250, 0.6);
    transition: color .15s ease;
}

.sidebar-sublink:hover {
    color: var(--color-on-sidebar);
}

/* El subtítulo de una categoría del submenú (Operación, Reportes,
   Catálogos…): chico y en versalitas, para que ordene la lista sin competir
   con los enlaces. Tapa la línea conectora con el color del menú, así se lee
   como un corte entre grupos. */
.sidebar-subgroup {
    position: relative;
    margin: 0.55rem 0 0.1rem -1.5rem;
    padding: 0.15rem 0.6rem 0.15rem 1.5rem;
    font-size: 0.64rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: rgba(244, 246, 250, 0.42);
    background: var(--color-sidebar);
}

.sidebar-subgroup:first-child {
    margin-top: 0.1rem;
}

.sublink-node {
    position: relative;
    flex-shrink: 0;
    width: 5px;
    height: 5px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.3);
    transition: background-color .15s ease, box-shadow .15s ease;
}

.sidebar-sublink.active {
    color: var(--color-accent);
    font-weight: 700;
}

.sidebar-sublink.active .sublink-node {
    background: var(--color-accent);
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--color-accent) 25%, transparent);
}

/* ── Menú flotante de la barra colapsada ────────────────────────────── */

/* El ícono cuya ventana está abierta se ve igual que al pasar el mouse,
   para que se note de dónde sale la ventana. */
.sidebar-link.flyout-open {
    background: rgba(255, 255, 255, 0.08);
    color: var(--color-on-sidebar);
}

.sidebar-flyout {
    position: fixed;
    z-index: 45;
    display: flex;
    flex-direction: column;
    min-width: 14rem;
    max-width: 20rem;
    max-height: calc(100vh - 16px);
    max-height: calc(100dvh - 16px);
    overflow-y: auto;
    padding: 0.45rem;
    border-radius: var(--radius-md, 10px);
    background: var(--color-sidebar);
    color: var(--color-on-sidebar);
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.28);
    border: 1px solid rgba(255, 255, 255, 0.10);
}

/* Si no cabe en la altura de la ventana, se desplaza: sus enlaces no se
   aplastan. */
.sidebar-flyout > * {
    flex-shrink: 0;
}

.flyout-title {
    padding: 0.4rem 0.6rem 0.45rem;
    font-size: 0.84rem;
    font-weight: 700;
    color: var(--color-on-sidebar);
    text-decoration: none;
    border-bottom: 1px solid rgba(255, 255, 255, 0.10);
    margin-bottom: 0.2rem;
}

.flyout-title-link {
    border-bottom: 0;
    margin-bottom: 0;
    border-radius: var(--radius-sm);
}

.flyout-title-link:hover,
.flyout-title-link:focus-visible {
    background: rgba(255, 255, 255, 0.08);
}

.flyout-group {
    padding: 0.55rem 0.6rem 0.15rem;
    font-size: 0.64rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: rgba(244, 246, 250, 0.42);
}

.flyout-link {
    padding: 0.38rem 0.6rem;
    border-radius: var(--radius-sm);
    font-size: 0.8rem;
    font-weight: 500;
    color: rgba(244, 246, 250, 0.75);
    text-decoration: none;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.flyout-link:hover,
.flyout-link:focus-visible {
    background: rgba(255, 255, 255, 0.08);
    color: var(--color-on-sidebar);
    outline: none;
}

.flyout-link.active,
.flyout-title-link.active {
    color: var(--color-accent);
    font-weight: 700;
}

.sidebar-collapse-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    background: transparent;
    border: none;
    color: rgba(244, 246, 250, 0.7);
    text-align: left;
    padding: 0.5rem 0.6rem;
    cursor: pointer;
    font-size: 0.78rem;
}

.app-main {
    display: flex;
    flex-direction: column;
    min-width: 0;
    height: 100vh;
    height: 100dvh;
    overflow: hidden;
}

/* Los avisos y el contenido: con el menú al lado, uno debajo del otro como
   siempre; .content es lo único que se desplaza. */
.app-body {
    display: flex;
    flex-direction: column;
    flex: 1;
    min-height: 0;
}

/* Con Conti abierto en una pantalla ancha, todo lo de abajo de la barra se
   corre a la izquierda: nada queda debajo del panel (ContiPanel.vue, 27rem),
   tampoco los botones de confirmar lo que Conti preparó. En una pantalla
   angosta el panel la ocupa entera y se cierra para ver la de atrás. */
@media (min-width: 1100px) {
    .app-main > :not(.topbar) {
        transition: margin-right .2s ease;
    }

    .app-main.conti-docked > :not(.topbar) {
        margin-right: 27rem;
    }
}

@media (min-width: 1100px) and (prefers-reduced-motion: reduce) {
    .app-main > :not(.topbar) {
        transition: none;
    }
}

.topbar {
    min-height: var(--topbar-height);
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    padding: 0.5rem 1rem;
    background: var(--color-surface);
    border-bottom: 1px solid var(--color-border);
    z-index: 10;
}

.topbar-heading {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex: 1 1 auto;
    min-width: 0;
}

.topbar-title {
    font-size: 1rem;
    font-weight: 700;
    margin: 0;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* Las acciones nunca se encogen: si el contenedor se achicara, sus botones
   se saldrían de la pantalla (así se perdía «Salir» con un título largo).
   Cuando no cabe todo, cede el título, que se corta con puntos suspensivos
   hasta un mínimo legible. */
.topbar-actions {
    display: flex;
    align-items: center;
    flex: 0 0 auto;
    gap: 0.5rem;
}

/* El aspecto es el de todos los campos (app.scss); acá solo el ancho: un
   nombre de compañía largo se corta en vez de empujar la barra. */
.company-select {
    max-width: 14rem;
    text-overflow: ellipsis;
}

.topbar-icon-btn {
    width: 2.25rem;
    padding: 0;
}

/* Abierto el panel, el botón queda marcado: se ve de dónde salió. */
.feedback-trigger {
    position: relative;
}

.feedback-trigger.is-open {
    background: var(--color-primary-soft);
    border-color: color-mix(in srgb, var(--color-primary) 35%, var(--color-border));
}

/* Conti: el botón flotante, en la esquina inferior derecha, justo arriba
   del pie. Por debajo de los paneles de la derecha (25), del menú en el
   teléfono (30 y 40) y de los modales (50). */
.app-bottom {
    position: relative;
    flex-shrink: 0;
}

.conti-dial {
    position: absolute;
    right: 1.5rem;
    bottom: calc(100% + 1rem);
    z-index: 24;
    width: 3.5rem;
    height: 3.5rem;
}

/* Las dos opciones salen del botón: el chat arriba, escanear a la izquierda. */
.conti-dial-option {
    position: absolute;
    top: 50%;
    left: 50%;
    display: inline-grid;
    place-items: center;
    width: 2.75rem;
    height: 2.75rem;
    margin: -1.375rem 0 0 -1.375rem;
    padding: 0;
    border: 1px solid var(--color-border);
    border-radius: 50%;
    background: var(--color-surface);
    color: color-mix(in srgb, var(--color-primary) 75%, var(--color-text));
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.18);
    cursor: pointer;
    opacity: 0;
    pointer-events: none;
    transform: scale(0.4);
    transition: transform .18s ease, opacity .18s ease, background .15s ease;
}

.conti-dial.is-open .conti-dial-option {
    opacity: 1;
    pointer-events: auto;
}

.conti-dial.is-open .conti-dial-option.is-chat {
    transform: translate(0, -4.1rem);
}

.conti-dial.is-open .conti-dial-option.is-scan {
    transform: translate(-4.1rem, 0);
    transition-delay: .04s;
}

.conti-dial-option:hover,
.conti-dial-option:focus-visible {
    background: var(--color-primary-soft);
    outline: none;
}

/* El nombre de cada opción, a su izquierda. */
.conti-dial-label {
    position: absolute;
    right: calc(100% + 0.45rem);
    padding: 0.15rem 0.5rem;
    border-radius: 999px;
    background: var(--color-text);
    color: var(--color-surface);
    font-size: 0.72rem;
    font-weight: 600;
    white-space: nowrap;
    opacity: 0;
    pointer-events: none;
    transition: opacity .15s ease;
}

.conti-dial-option:hover .conti-dial-label,
.conti-dial-option:focus-visible .conti-dial-label {
    opacity: 1;
}

.conti-fab {
    position: relative;
    z-index: 1;
    display: inline-grid;
    place-items: center;
    width: 3.5rem;
    height: 3.5rem;
    padding: 0;
    border: none;
    border-radius: 50%;
    background: var(--color-primary);
    color: var(--color-on-primary);
    box-shadow: 0 6px 18px rgba(0, 0, 0, 0.22);
    cursor: pointer;
    transition: transform .15s ease, box-shadow .15s ease, background .15s ease;
}

.conti-fab:hover {
    background: var(--color-primary-hover);
    transform: translateY(-2px);
    box-shadow: 0 10px 24px rgba(0, 0, 0, 0.26);
}

.conti-fab:focus-visible {
    outline: 3px solid color-mix(in srgb, var(--color-primary) 45%, transparent);
    outline-offset: 3px;
}

@media (prefers-reduced-motion: reduce) {
    .conti-fab,
    .conti-dial-option { transition: none; }
    .conti-fab:hover { transform: none; }
}

/* En una pantalla táctil no hay «pasar por encima»: los nombres, siempre. */
@media (hover: none) {
    .conti-dial.is-open .conti-dial-label {
        opacity: 1;
    }
}

/* Lo último de la página se puede subir por encima del botón: si no, al
   final de un formulario largo taparía «Guardar». */
.app-main.has-conti-fab .content {
    padding-bottom: 5.75rem;
}

/* Hay una noticia que todavía no se vio. */
.topbar-dot {
    position: absolute;
    top: 0.3rem;
    right: 0.3rem;
    width: 0.5rem;
    height: 0.5rem;
    border-radius: 50%;
    background: var(--color-accent);
    box-shadow: 0 0 0 2px var(--color-surface);
}

@media (min-width: 641px) {
    .topbar-heading {
        min-width: 11rem;
    }
}

.content {
    /* Posicionado a propósito: es lo único que se desplaza, y tiene que ser
       el marco de referencia de lo que lleve `position: absolute` adentro
       (los textos .sr-only, solo para lectores de pantalla). Sin esto esos
       elementos se ubican contra la página entera, quedan por debajo de la
       ventana cuando el contenido es largo, y la página gana un segundo
       desplazamiento que baja más allá de la aplicación. */
    position: relative;
    padding: 1.25rem;
    flex: 1;
    overflow-y: auto;
    overflow-x: auto;
}

.flash {
    flex-shrink: 0;
    margin: 0.75rem 1.25rem 0;
    padding: 0.6rem 0.9rem;
    border-radius: var(--radius-sm);
    font-size: 0.85rem;
}

.flash-success {
    background: var(--color-success-soft);
    color: var(--color-success);
}

.flash-error {
    background: var(--color-danger-soft);
    color: var(--color-danger);
}

.flash-warning {
    background: var(--color-warning-soft);
    color: var(--color-warning);
}

.app-footer {
    flex-shrink: 0;
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.25rem 0.5rem;
    padding: 0.5rem 1.25rem;
    background: var(--color-surface);
    border-top: 1px solid var(--color-border);
    font-size: 0.76rem;
    color: var(--color-text-muted);
}

.app-footer-meta {
    margin-left: auto;
}

.badge-role {
    background: var(--color-primary-soft, rgba(0, 0, 0, 0.06));
    /* El primario mezclado con el texto: oscuro en el modo claro y claro en
       el oscuro, legible en todos los temas (el primario solo, en oscuro, es
       un tono medio sobre un fondo casi del mismo tono). */
    color: color-mix(in srgb, var(--color-primary) 55%, var(--color-text));
    font-weight: 700;
    white-space: nowrap;
}

/* ── El menú arriba (CLAUDE.md secc. 29) ─────────────────────────────────── */
/*
    Arriba, la barra del menú con la marca y el título; abajo, la columna de la
    sesión a la izquierda y el contenido. La barra mide lo mismo que la barra
    superior: los paneles de la derecha («Comentarios y noticias», Conti) se
    abren debajo de --topbar-height. Solo existe desde 1025px (topLayout).
*/
.app-shell.layout-top {
    --rail-width: 3.5rem;
    grid-template-columns: minmax(0, 1fr);
    grid-template-rows: var(--topbar-height) minmax(0, 1fr);
}

.layout-top .sidebar {
    flex-direction: row;
    align-items: center;
    gap: 1rem;
    height: var(--topbar-height);
    padding: 0 0.75rem 0 1rem;
    overflow: hidden;
}

/* La marca no se estira ni se encoge según el menú (sí el título, hasta su
   tope): el menú se queda con lo que sobra, siempre lo mismo, y el cálculo
   de cuántas secciones caben no se mueve al ocultar las que sobran. */
.layout-top .sidebar-brand {
    flex: 0 0 auto;
    max-width: 45%;
    min-width: 0;
    padding: 0;
    gap: 0.6rem;
}

.layout-top .brand-name {
    flex-shrink: 0;
    white-space: nowrap;
}

/* El título de la pantalla, junto a la marca: cede antes que el menú, y se
   corta con puntos suspensivos (el completo, al pasar el mouse). Va con el
   acento del tema (pedido del usuario): es el «estás acá» de la barra, y se
   distingue de los nombres de las secciones, que son del mismo blanco. */
.brand-title {
    min-width: 0;
    max-width: 20rem;
    margin: 0 0 0 0.2rem;
    padding-left: 0.8rem;
    border-left: 1px solid rgba(255, 255, 255, 0.18);
    font-size: 0.95rem;
    font-weight: 700;
    color: var(--color-accent);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.layout-top .sidebar-nav {
    position: relative;
    flex: 1 1 0;
    min-width: 0;
    flex-direction: row;
    align-items: center;
    gap: 0.15rem;
}

.layout-top .sidebar-link {
    flex-shrink: 0;
    gap: 0.45rem;
    padding: 0.42rem 0.6rem;
    white-space: nowrap;
}

/* Las que no caben: fuera del flujo e invisibles (no reciben el foco ni las
   lee el lector de pantalla), pero se pueden seguir midiendo. */
.layout-top .sidebar-link.is-overflow {
    position: absolute;
    visibility: hidden;
    pointer-events: none;
}

.top-more {
    background: transparent;
    border: none;
    font: inherit;
    cursor: pointer;
}

.top-chevron {
    flex-shrink: 0;
    color: rgba(244, 246, 250, 0.5);
    transition: transform .15s ease, color .15s ease;
}

.sidebar-link.flyout-open .top-chevron {
    transform: rotate(180deg);
    color: var(--color-accent);
}

.app-shell.layout-top .app-main {
    display: grid;
    grid-template-columns: var(--rail-width) minmax(0, 1fr);
    grid-template-rows: minmax(0, 1fr) auto;
    height: auto;
    min-height: 0;
}

/* La columna de la sesión: lo mismo que la barra superior, apilado y solo
   con los íconos (el nombre de cada uno, en su tooltip y su aria-label). */
.layout-top .topbar {
    grid-column: 1;
    grid-row: 1;
    flex-direction: column;
    align-items: center;
    justify-content: flex-start;
    min-height: 0;
    padding: 0.75rem 0;
    border-bottom: 0;
    border-right: 1px solid var(--color-border);
    overflow-y: auto;
}

/* Toda la altura de la columna: arriba lo de la sesión, abajo (después del
   espaciador) Administración, Mi cuenta y «Salir». */
.layout-top .topbar-actions {
    flex: 1 1 auto;
    flex-direction: column;
    gap: 0.35rem;
}

.rail-spacer {
    flex: 1 1 auto;
    min-height: 0.75rem;
}

/* La sección en la que se está, o la que tiene su ventana abierta: marcada
   como el botón de «Comentarios y noticias» abierto. */
.rail-section.active,
.rail-section.flyout-open {
    background: var(--color-primary-soft);
    border-color: color-mix(in srgb, var(--color-primary) 35%, var(--color-border));
}

.rail-section.active {
    color: color-mix(in srgb, var(--color-primary) 70%, var(--color-text));
}

/* La foto de perfil en lugar del ícono de «Mi cuenta». */
.rail-photo {
    display: block;
    width: 24px;
    height: 24px;
    border-radius: 50%;
    object-fit: cover;
}

.layout-top .logout-btn {
    width: 2.25rem;
    padding: 0;
}

/* «Salir» queda con su ícono: el texto sigue ahí para el lector de pantalla. */
.layout-top .logout-label {
    position: absolute;
    width: 1px;
    height: 1px;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
}

/* La compañía, primera: abierta su lista, queda marcada como el botón de
   «Comentarios y noticias». */
.company-btn.is-open {
    background: var(--color-primary-soft);
    border-color: color-mix(in srgb, var(--color-primary) 35%, var(--color-border));
}

/* La lista de compañías, al lado de su ícono: por encima del contenido y de
   los paneles de la derecha, como la ventana del menú. */
.company-menu {
    position: fixed;
    z-index: 45;
    display: flex;
    flex-direction: column;
    min-width: 15rem;
    max-width: 22rem;
    max-height: calc(100vh - 16px);
    max-height: calc(100dvh - 16px);
    overflow-y: auto;
    padding: 0.4rem;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md, 10px);
    background: var(--color-surface);
    color: var(--color-text);
    box-shadow: var(--shadow-md);
}

.company-menu-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    padding: 0.3rem 0.55rem 0.45rem;
    margin-bottom: 0.2rem;
    border-bottom: 1px solid var(--color-border);
    font-size: 0.7rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--color-text-muted);
}

.company-menu-item {
    display: flex;
    align-items: center;
    gap: 0.45rem;
    width: 100%;
    min-height: 2.25rem;
    padding: 0.35rem 0.55rem;
    border: none;
    border-radius: var(--radius-sm);
    background: transparent;
    color: inherit;
    font: inherit;
    font-size: 0.84rem;
    text-align: left;
    cursor: pointer;
}

.company-menu-item:hover:not(:disabled),
.company-menu-item:focus-visible {
    background: var(--color-surface-alt);
    outline: none;
}

.company-menu-item.current {
    font-weight: 700;
    color: color-mix(in srgb, var(--color-primary) 70%, var(--color-text));
}

.company-menu-item:disabled {
    cursor: not-allowed;
    color: var(--color-text-muted);
}

.company-menu-check {
    flex-shrink: 0;
    width: 15px;
}

.company-menu-name {
    min-width: 0;
    overflow-wrap: anywhere;
}

.company-menu-name small {
    display: block;
    font-size: 0.72rem;
    font-weight: 500;
    color: var(--color-text-muted);
}

.layout-top .app-body {
    grid-column: 2;
    grid-row: 1;
}

.layout-top .app-bottom {
    grid-column: 1 / -1;
    grid-row: 2;
}

@media (prefers-reduced-motion: reduce) {
    .top-chevron { transition: none; }
}

/* Solo existen en pantallas angostas (ver la media query de abajo). */
.nav-toggle,
.nav-close,
.nav-backdrop {
    display: none;
}

.nav-toggle {
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    width: 36px;
    height: 36px;
    margin-left: -0.35rem;
    padding: 0;
    border: none;
    border-radius: var(--radius-sm);
    background: transparent;
    color: var(--color-text);
    cursor: pointer;
}

.nav-toggle:hover { background: var(--color-surface-alt); }

.nav-close {
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    margin-left: auto;
    padding: 0;
    border: none;
    border-radius: var(--radius-sm);
    background: transparent;
    color: inherit;
    cursor: pointer;
}

.nav-close:hover { background: rgba(255, 255, 255, 0.08); }

@media (max-width: 1024px) {
    .app-shell,
    .app-shell.is-collapsed {
        grid-template-columns: minmax(0, 1fr);
    }

    /*
        Cerrado, el panel queda fuera de la pantalla Y oculto (visibility):
        solo con el transform, sus enlaces seguirían recibiendo el foco con
        Tab aunque no se vean. visibility cambia recién al terminar la
        animación de cierre, para que el panel no desaparezca de golpe.
    */
    .sidebar {
        position: fixed;
        inset: 0 auto 0 0;
        z-index: 40;
        width: min(var(--sidebar-width), 85vw);
        box-shadow: var(--shadow-md);
        transform: translateX(-100%);
        visibility: hidden;
        transition: transform .2s ease, visibility 0s linear .2s;
    }

    .nav-open .sidebar {
        transform: none;
        visibility: visible;
        transition: transform .2s ease;
    }

    .nav-open .nav-backdrop {
        display: block;
        position: fixed;
        inset: 0;
        z-index: 30;
        background: rgba(0, 0, 0, 0.4);
    }

    .nav-toggle,
    .nav-close {
        display: inline-flex;
    }

    /* El panel siempre se abre completo: colapsar es cosa del escritorio. Y
       el menú arriba también: acá no hay lugar, así que ni se ofrece. */
    .sidebar-collapse-btn,
    .layout-btn {
        display: none;
    }

    .badge-role {
        display: none;
    }
}

/*
    En un teléfono la barra conserva los mismos elementos, más compactos: la
    compañía se angosta, y Salir queda con su ícono (el texto sigue ahí para
    el lector de pantalla).
*/
@media (max-width: 640px) {
    .topbar { padding: 0.5rem 0.75rem; gap: 0.5rem; }
    .topbar-actions { gap: 0.35rem; }
    .company-select { max-width: 8rem; }
    .logout-btn { width: 2.25rem; padding: 0; }
    .logout-label {
        position: absolute;
        width: 1px;
        height: 1px;
        margin: -1px;
        overflow: hidden;
        clip: rect(0, 0, 0, 0);
        white-space: nowrap;
    }
    .content { padding: 0.75rem; }
    .app-main.has-conti-fab .content { padding-bottom: 5rem; }
    .conti-dial { right: 1rem; width: 3.25rem; height: 3.25rem; }
    .conti-fab { width: 3.25rem; height: 3.25rem; }
    .flash { margin: 0.75rem 0.75rem 0; }
    .app-footer { padding: 0.5rem 0.75rem; }
    .app-footer-meta { margin-left: 0; }
}

/* En los teléfonos más angostos (320px) la compañía se angosta un poco más
   para que al título le quede algo legible. */
@media (max-width: 400px) {
    .company-select { max-width: 6.5rem; }
}

/* A 320px, con el botón de «Comentarios y noticias», un poco más. */
@media (max-width: 360px) {
    .company-select { max-width: 5.5rem; }
}

@media (prefers-reduced-motion: reduce) {
    .sidebar,
    .nav-open .sidebar {
        transition: none;
    }
}
</style>
