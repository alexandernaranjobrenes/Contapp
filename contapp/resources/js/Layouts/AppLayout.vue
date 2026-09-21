<script setup>
import { ref, computed, onMounted } from 'vue';
import { Link, usePage, router } from '@inertiajs/vue3';

defineProps({
    title: { type: String, default: '' },
});

const page = usePage();

// Agrupado por área en vez de una lista plana de ~18 ítems (era la queja:
// el menú quedaba muy cargado) — mismas rutas y funciones de siempre, solo
// organizadas bajo secciones colapsables. "module" puede ir en la sección
// (todos sus hijos comparten permiso, ej. Contabilidad) o en un hijo
// puntual (ej. Reporte de IVA dentro de Impuestos, que no comparte permiso
// con Indicadores de impuesto, un catálogo global sin gate propio — ver
// routes/web.php). El backend sigue siendo quien realmente bloquea
// (module-access middleware): esto es solo para no ofrecer en el menú algo
// a lo que el usuario de todas formas no puede entrar.
function passesModule(mod) {
    return !mod || page.props.moduleAccess?.[mod] !== 'none';
}

const nav = computed(() => {
    const items = [
        { label: 'Panel', href: route('dashboard'), match: ['dashboard'], icon: '⌂' },
        {
            label: 'Contabilidad', icon: '☰', module: 'accounting',
            match: ['document-types.*', 'chart-of-accounts.*', 'opening-balance.*', 'journal-entries.*', 'journal-entry-schedules.*', 'period-close.*'],
            children: [
                { label: 'Tipos de documento', href: route('document-types.index'), match: ['document-types.*'] },
                { label: 'Catálogo de cuentas', href: route('chart-of-accounts.index'), match: ['chart-of-accounts.*'] },
                { label: 'Saldos iniciales', href: route('opening-balance.create'), match: ['opening-balance.*'] },
                { label: 'Registros', href: route('journal-entries.index'), match: ['journal-entries.*'] },
                { label: 'Registros programados', href: route('journal-entry-schedules.index'), match: ['journal-entry-schedules.*'] },
                { label: 'Cierre de períodos', href: route('period-close.index'), match: ['period-close.*'] },
            ],
        },
        {
            label: 'Centros de costo y cambiario', icon: '🏷', module: 'accounting',
            match: ['cost-centers.*', 'cost-allocation-rules.*', 'exchange-rates.*', 'fx-revaluation.*'],
            children: [
                { label: 'Centros de costo', href: route('cost-centers.index'), match: ['cost-centers.*'] },
                { label: 'Normas de reparto', href: route('cost-allocation-rules.index'), match: ['cost-allocation-rules.*'] },
                { label: 'Tipos de cambio', href: route('exchange-rates.index'), match: ['exchange-rates.*'] },
                { label: 'Diferencial: ejecutar', href: route('fx-revaluation.create'), match: ['fx-revaluation.create', 'fx-revaluation.preview', 'fx-revaluation.store'] },
                { label: 'Diferencial: historial', href: route('fx-revaluation.index'), match: ['fx-revaluation.index', 'fx-revaluation.show'] },
            ],
        },
        {
            label: 'Inventario', icon: '▦', module: 'inventory',
            match: ['items.*', 'item-groups.*', 'warehouses.*', 'units-of-measure.*', 'inventory-movements.*', 'gl-determinations.*', 'supplier-invoices.*', 'landed-costs.*', 'production-orders.*', 'warehouse-bins.*', 'stock-transfers.*'],
            children: [
                { label: 'Artículos', href: route('items.index'), match: ['items.index', 'items.kardex'] },
                { label: 'Movimientos', href: route('inventory-movements.index'), match: ['inventory-movements.*'] },
                { label: 'Traslados', href: route('stock-transfers.index'), match: ['stock-transfers.*'] },
                { label: 'Órdenes de compra', href: route('purchase-orders.index'), match: ['purchase-orders.*'] },
                { label: 'Tomas físicas', href: route('stock-counts.index'), match: ['stock-counts.*'] },
                { label: 'Deterioro (NIC 2)', href: route('inventory-write-downs.index'), match: ['inventory-write-downs.*'] },
                { label: 'Lotes por vencer', href: route('lot-expiry.index'), match: ['lot-expiry.*'] },
                { label: 'Facturas de proveedor', href: route('supplier-invoices.index'), match: ['supplier-invoices.*'] },
                { label: 'Costos de importación', href: route('landed-costs.index'), match: ['landed-costs.*'] },
                { label: 'Rubros de nacionalización', href: route('import-costs.index'), match: ['import-costs.*'] },
                { label: 'Órdenes de fabricación', href: route('production-orders.index'), match: ['production-orders.*'] },
                { label: 'Grupos de artículos', href: route('item-groups.index'), match: ['item-groups.*'] },
                { label: 'Almacenes', href: route('warehouses.index'), match: ['warehouses.*', 'warehouse-bins.*'] },
                { label: 'Unidades de medida', href: route('units-of-measure.index'), match: ['units-of-measure.*'] },
                { label: 'Determinación de cuentas', href: route('gl-determinations.index'), match: ['gl-determinations.*'] },
            ],
        },
        {
            label: 'Facturación', icon: '🧾', module: 'billing',
            match: ['sales-documents.*', 'sales-orders.*', 'billing-settings.*'],
            children: [
                { label: 'Órdenes de pedido', href: route('sales-orders.index'), match: ['sales-orders.*'] },
                { label: 'Comprobantes', href: route('sales-documents.index'), match: ['sales-documents.index', 'sales-documents.show'] },
                { label: 'Nueva factura', href: route('sales-documents.create'), match: ['sales-documents.create'] },
                { label: 'Configuración', href: route('billing-settings.index'), match: ['billing-settings.*'] },
            ],
        },
        {
            label: 'Socios de negocio', icon: '⚭', module: 'business_partners',
            match: ['business-partners.*', 'bp-categories.*'],
            children: [
                { label: 'Socios de negocio', href: route('business-partners.index'), match: ['business-partners.*'] },
                { label: 'Categorías de socios', href: route('bp-categories.index'), match: ['bp-categories.*'] },
            ],
        },
        {
            label: 'Bancos', icon: '🏦', module: 'banking',
            match: ['bank-accounts.*', 'bank-reconciliations.*', 'bank-reconciliation-report.*'],
            children: [
                { label: 'Cuentas bancarias', href: route('bank-accounts.index'), match: ['bank-accounts.*'] },
                { label: 'Conciliaciones bancarias', href: route('bank-reconciliations.hub'), match: ['bank-reconciliations.*'] },
                { label: 'Reporte de conciliaciones', href: route('bank-reconciliation-report.index'), match: ['bank-reconciliation-report.*'] },
            ],
        },
        {
            label: 'Impuestos', icon: '§',
            match: ['tax-rates.*', 'tax-report.*'],
            children: [
                { label: 'Indicadores de impuesto', href: route('tax-rates.index'), match: ['tax-rates.*'] },
                { label: 'Reporte de IVA', href: route('tax-report.index'), match: ['tax-report.*'], module: 'tax' },
            ],
        },
        {
            label: 'Reportes', icon: '▤', module: 'reports',
            match: ['reports.*', 'saved-reports.*'],
            children: [
                { label: 'Balance de comprobación', href: route('reports.trial-balance.index'), match: ['reports.trial-balance.*'] },
                { label: 'Estado de resultados', href: route('reports.income-statement.index'), match: ['reports.income-statement.*'] },
                { label: 'Balance general', href: route('reports.balance-sheet.index'), match: ['reports.balance-sheet.*'] },
                { label: 'Antigüedad de saldos', href: route('reports.aging.index'), match: ['reports.aging.*'] },
                { label: 'Proyección de cobros y pagos', href: route('reports.cash-flow-projection.index'), match: ['reports.cash-flow-projection.*'] },
                { label: 'Comparativo de empresas', href: route('reports.multi-company-comparison.index'), match: ['reports.multi-company-comparison.*'] },
                { label: 'Auxiliar por centro de costo', href: route('reports.cost-center.index'), match: ['reports.cost-center.*'] },
                { label: 'Normas de reparto', href: route('reports.cost-allocation-rule.index'), match: ['reports.cost-allocation-rule.*'] },
                { label: 'Comparativo entre periodos', href: route('reports.period-comparison.index'), match: ['reports.period-comparison.*'] },
                { label: 'Existencias valorizadas', href: route('reports.inventory-valuation.index'), match: ['reports.inventory-valuation.*'] },
                { label: 'Antigüedad de inventario', href: route('reports.inventory-aging.index'), match: ['reports.inventory-aging.*'] },
                { label: 'Exportar catálogos', href: route('reports.catalog-export.index'), match: ['reports.catalog-export.*'] },
                { label: 'Registro por tipo de documento', href: route('reports.document-type-register.index'), match: ['reports.document-type-register.*'] },
                { label: 'Reportes guardados', href: route('saved-reports.index'), match: ['saved-reports.*'] },
            ],
        },
    ];

    const adminChildren = [];
    if (page.props.auth?.user?.can_manage_users) {
        adminChildren.push({ label: 'Usuarios', href: route('users.index'), match: ['users.*'] });
    }
    if (page.props.auth?.user?.is_super_admin) {
        adminChildren.push({ label: 'Agregar compañía', href: route('companies.create'), match: ['companies.*'] });
    }
    if (adminChildren.length) {
        items.push({
            label: 'Administración', icon: '⚙',
            match: adminChildren.flatMap((c) => c.match),
            children: adminChildren,
        });
    }

    return items
        .map((item) => item.children
            ? { ...item, children: item.children.filter((child) => passesModule(child.module)) }
            : item)
        .filter((item) => {
            if (!passesModule(item.module)) return false;
            return item.children ? item.children.length > 0 : true;
        });
});

const ROLE_LABELS = {
    super_admin: 'Superusuario',
    admin: 'Administrador',
    user: 'Usuario',
};

const roleLabel = computed(() => ROLE_LABELS[page.props.auth?.user?.role_type] ?? null);

const CONTAPP_VERSION = 'v1.0.0';
const currentYear = computed(() => new Date().getFullYear());

const collapsed = ref(false);
const theme = ref('light');

onMounted(() => {
    const storedSidebar = localStorage.getItem('contapp-sidebar');
    collapsed.value = storedSidebar === 'collapsed';

    const storedTheme = localStorage.getItem('contapp-theme');
    theme.value = storedTheme === 'dark' ? 'dark' : (storedTheme === 'light' ? 'light' : theme.value);
});

function toggleSidebar() {
    collapsed.value = !collapsed.value;
    localStorage.setItem('contapp-sidebar', collapsed.value ? 'collapsed' : 'expanded');
}

function toggleTheme() {
    theme.value = theme.value === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', theme.value);
    localStorage.setItem('contapp-theme', theme.value);
}

const companies = computed(() => page.props.companies ?? []);
const currentCompanyId = computed(() => page.props.currentCompanyId);
const user = computed(() => page.props.auth?.user);

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

function switchCompany(event) {
    router.put(route('company-switch'), { company_id: event.target.value });
}

function isCurrent(patterns) {
    return patterns.some((pattern) => route().current(pattern));
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
    <div class="app-shell" :class="{ 'is-collapsed': collapsed }">
        <aside class="sidebar">
            <div class="sidebar-brand">
                <span class="brand-mark">C</span>
                <span v-if="!collapsed" class="brand-name">CONTAPP</span>
            </div>

            <nav class="sidebar-nav">
                <template v-for="item in nav" :key="item.label">
                    <Link
                        v-if="!item.children || collapsed"
                        :href="item.children ? item.children[0].href : item.href"
                        class="sidebar-link"
                        :class="{ active: isCurrent(item.match) }"
                    >
                        <span class="sidebar-icon">{{ item.icon }}</span>
                        <span v-if="!collapsed" class="sidebar-label">{{ item.label }}</span>
                    </Link>

                    <div v-else class="sidebar-group">
                        <button
                            type="button"
                            class="sidebar-link sidebar-group-toggle"
                            :class="{ active: isCurrent(item.match), 'is-open': isGroupExpanded(item) }"
                            @click="toggleGroup(item)"
                        >
                            <span class="sidebar-icon-chip">{{ item.icon }}</span>
                            <span class="sidebar-label">{{ item.label }}</span>
                            <span class="sidebar-chevron" :class="{ open: isGroupExpanded(item) }">›</span>
                        </button>

                        <div class="sidebar-subnav-wrap" :class="{ open: isGroupExpanded(item) }">
                            <div class="sidebar-subnav">
                                <Link
                                    v-for="child in item.children"
                                    :key="child.label"
                                    :href="child.href"
                                    class="sidebar-sublink"
                                    :class="{ active: isCurrent(child.match) }"
                                >
                                    <span class="sublink-node"></span>
                                    <span class="sidebar-label">{{ child.label }}</span>
                                </Link>
                            </div>
                        </div>
                    </div>
                </template>
            </nav>

            <button type="button" class="sidebar-collapse-btn" @click="toggleSidebar">
                {{ collapsed ? '»' : '« Colapsar' }}
            </button>
        </aside>

        <div class="app-main">
            <header class="topbar">
                <h1 class="topbar-title">{{ title }}</h1>

                <div class="topbar-actions">
                    <slot name="actions" />

                    <select
                        v-if="companies.length"
                        class="company-select"
                        :value="currentCompanyId"
                        @change="switchCompany"
                    >
                        <option v-for="c in companies" :key="c.id" :value="c.id">
                            {{ c.trade_name || c.legal_name }}
                        </option>
                    </select>

                    <button type="button" class="btn btn-ghost" @click="toggleTheme" title="Cambiar tema">
                        {{ theme === 'dark' ? '☀' : '☾' }}
                    </button>

                    <span v-if="roleLabel" class="badge badge-role">{{ roleLabel }}</span>
                    <span v-if="user" class="topbar-user">{{ user.name }}</span>

                    <Link v-if="user" :href="route('logout')" method="delete" as="button" class="btn btn-ghost">
                        Salir del sistema
                    </Link>
                </div>
            </header>

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

            <footer v-if="page.props.license" class="app-footer">
                Licencia {{ page.props.license.category ?? '—' }} · {{ page.props.license.masked_code }} · Vence {{ page.props.license.expires_at }}
                <span class="badge" :class="licenseStatus.cls">{{ licenseStatus.label }}</span>
                <span class="app-footer-meta">CONTAPP {{ CONTAPP_VERSION }} · © {{ currentYear }}</span>
            </footer>
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
    el contenido de la página — así el sidebar queda siempre visible y la
    barra de scroll horizontal de una tabla ancha queda siempre a una
    distancia alcanzable, no al fondo de una página de cientos de filas.
*/
.app-shell {
    display: grid;
    grid-template-columns: var(--sidebar-width) 1fr;
    height: 100vh;
    transition: grid-template-columns .15s ease;
}

.app-shell.is-collapsed {
    grid-template-columns: var(--sidebar-width-collapsed) 1fr;
}

.sidebar {
    background: var(--color-primary);
    color: var(--color-on-primary);
    display: flex;
    flex-direction: column;
    padding: 0.75rem 0.6rem;
    height: 100vh;
    overflow-y: auto;
}

.sidebar-brand {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.4rem 0.4rem 1rem;
}

.brand-mark {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: 8px;
    background: rgba(255, 255, 255, 0.12);
    font-weight: 800;
}

.brand-name {
    font-weight: 700;
    letter-spacing: .03em;
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
    color: var(--color-on-primary);
}

.sidebar-link.active {
    background: rgba(255, 255, 255, 0.16);
    color: var(--color-on-primary);
}

.sidebar-icon {
    width: 1.2em;
    text-align: center;
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
    font-size: 0.88em;
    transition: background-color .15s ease, color .15s ease;
}

.sidebar-group-toggle.is-open {
    background: rgba(224, 137, 74, 0.10);
    box-shadow: inset 3px 0 0 0 #e0894a;
}

.sidebar-group-toggle.is-open .sidebar-icon-chip {
    background: rgba(224, 137, 74, 0.28);
    color: #f2a05c;
}

.sidebar-group-toggle.is-open .sidebar-label {
    color: #f2a05c;
}

.sidebar-chevron {
    margin-left: auto;
    font-size: 0.9em;
    color: rgba(244, 246, 250, 0.5);
    transition: transform .15s ease, color .15s ease;
}

.sidebar-chevron.open {
    transform: rotate(90deg);
    color: #f2a05c;
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
    color: var(--color-on-primary);
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
    color: #f2a05c;
    font-weight: 700;
}

.sidebar-sublink.active .sublink-node {
    background: #f2a05c;
    box-shadow: 0 0 0 3px rgba(242, 160, 92, 0.25);
}

.sidebar-collapse-btn {
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
    overflow: hidden;
}

.topbar {
    height: var(--topbar-height);
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 1rem;
    background: var(--color-surface);
    border-bottom: 1px solid var(--color-border);
    z-index: 10;
}

.topbar-title {
    font-size: 1rem;
    font-weight: 700;
    margin: 0;
}

.topbar-actions {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.company-select {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.35rem 0.5rem;
    font-size: 0.82rem;
    color: var(--color-text);
}

.topbar-user {
    font-size: 0.82rem;
    color: var(--color-text-muted);
}

.content {
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
    gap: 0.5rem;
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
    color: var(--color-primary);
    font-weight: 700;
}
</style>
