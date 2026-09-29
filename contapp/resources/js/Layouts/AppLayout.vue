<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue';
import { Link, usePage, router } from '@inertiajs/vue3';
import {
    BookOpenIcon, ChevronRightIcon, HandshakeIcon, LandmarkIcon, LayoutDashboardIcon, LogOutIcon, MenuIcon, MoonIcon,
    PackageIcon, PanelLeftCloseIcon, PanelLeftOpenIcon, PercentIcon, ReceiptIcon, SettingsIcon, SunIcon, TagsIcon,
    UsersIcon, XIcon,
} from '@lucide/vue';

/**
 * La barra superior es la MISMA en todas las pantallas (CLAUDE.md secc. 24):
 * título, compañía, tema y sesión. Lo único que cambia es el título. Por
 * eso este layout no tiene un slot para acciones: los botones, filtros y
 * buscadores de cada pantalla van en su .view-toolbar, arriba de la tabla.
 */
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
        { label: 'Panel', href: route('dashboard'), match: ['dashboard'], icon: LayoutDashboardIcon },
        {
            label: 'Contabilidad', icon: BookOpenIcon, module: 'accounting',
            match: ['document-types.*', 'chart-of-accounts.*', 'opening-balance.*', 'journal-entries.*', 'journal-entry-schedules.*', 'period-close.*',
                'reports.trial-balance.*', 'reports.income-statement.*', 'reports.balance-sheet.*', 'reports.period-comparison.*',
                'reports.multi-company-comparison.*', 'reports.document-type-register.*', 'reports.catalog-export.*', 'saved-reports.*'],
            children: [
                { label: 'Tipos de documento', href: route('document-types.index'), match: ['document-types.*'] },
                { label: 'Catálogo de cuentas', href: route('chart-of-accounts.index'), match: ['chart-of-accounts.*'] },
                { label: 'Saldos iniciales', href: route('opening-balance.create'), match: ['opening-balance.*'] },
                { label: 'Registros', href: route('journal-entries.index'), match: ['journal-entries.*'] },
                { label: 'Registros programados', href: route('journal-entry-schedules.index'), match: ['journal-entry-schedules.*'] },
                { label: 'Cierre de períodos', href: route('period-close.index'), match: ['period-close.*'] },

                // Los reportes de contabilidad, adentro del módulo al que
                // pertenecen. Van al final y no al principio porque lo diario
                // son los registros; los estados se consultan al cerrar.
                //
                // Cada uno conserva su propio `module: 'reports'`: el permiso
                // de reportería es distinto del de contabilidad, y el
                // middleware del backend sigue exigiendo el de reportes.
                { label: 'Balance de comprobación', href: route('reports.trial-balance.index'), match: ['reports.trial-balance.*'], module: 'reports' },
                { label: 'Estado de resultados', href: route('reports.income-statement.index'), match: ['reports.income-statement.*'], module: 'reports' },
                { label: 'Balance general', href: route('reports.balance-sheet.index'), match: ['reports.balance-sheet.*'], module: 'reports' },
                { label: 'Comparativo entre periodos', href: route('reports.period-comparison.index'), match: ['reports.period-comparison.*'], module: 'reports' },
                { label: 'Comparativo de empresas', href: route('reports.multi-company-comparison.index'), match: ['reports.multi-company-comparison.*'], module: 'reports' },
                { label: 'Registro por tipo de documento', href: route('reports.document-type-register.index'), match: ['reports.document-type-register.*'], module: 'reports' },
                // Exporta cuentas, centros de costo, normas de reparto,
                // indicadores de IVA y socios: casi todo catálogo contable, y
                // no tiene un módulo propio donde vivir.
                { label: 'Exportar catálogos', href: route('reports.catalog-export.index'), match: ['reports.catalog-export.*'], module: 'reports' },
                // Guarda combinaciones de parámetros de VARIOS reportes, no
                // solo de los contables; queda acá porque la mayoría lo son.
                { label: 'Reportes guardados', href: route('saved-reports.index'), match: ['saved-reports.*'], module: 'reports' },
            ],
        },
        {
            label: 'Centros de costo y cambiario', icon: TagsIcon, module: 'accounting',
            match: ['cost-centers.*', 'cost-allocation-rules.*', 'exchange-rates.*', 'fx-revaluation.*',
                'reports.cost-center.*', 'reports.cost-allocation-rule.*'],
            children: [
                { label: 'Centros de costo', href: route('cost-centers.index'), match: ['cost-centers.*'] },
                { label: 'Normas de reparto', href: route('cost-allocation-rules.index'), match: ['cost-allocation-rules.*'] },
                // Los dos reportes de este módulo, junto a lo que reportan.
                { label: 'Auxiliar por centro de costo', href: route('reports.cost-center.index'), match: ['reports.cost-center.*'], module: 'reports' },
                { label: 'Reporte de normas de reparto', href: route('reports.cost-allocation-rule.index'), match: ['reports.cost-allocation-rule.*'], module: 'reports' },
                { label: 'Tipos de cambio', href: route('exchange-rates.index'), match: ['exchange-rates.*'] },
                { label: 'Diferencial: ejecutar', href: route('fx-revaluation.create'), match: ['fx-revaluation.create', 'fx-revaluation.preview', 'fx-revaluation.store'] },
                { label: 'Diferencial: historial', href: route('fx-revaluation.index'), match: ['fx-revaluation.index', 'fx-revaluation.show'] },
            ],
        },
        {
            label: 'Inventario', icon: PackageIcon, module: 'inventory',
            match: ['items.*', 'item-groups.*', 'warehouses.*', 'units-of-measure.*', 'inventory-movements.*', 'gl-determinations.*', 'supplier-invoices.*', 'landed-costs.*', 'production-orders.*', 'warehouse-bins.*', 'stock-transfers.*', 'inventory-reports.*', 'price-lists.*', 'bills-of-materials.*', 'item-serials.*',
                'reports.inventory-valuation.*', 'reports.inventory-aging.*'],
            children: [
                { label: 'Reportes de inventario', href: route('inventory-reports.index'), match: ['inventory-reports.*'] },
                // Estos dos son de inventario y estaban en el menú general de
                // reportes. El índice de «Reportes de inventario» ya los
                // enlazaba: ahora también están donde se los busca.
                { label: 'Existencias valorizadas', href: route('reports.inventory-valuation.index'), match: ['reports.inventory-valuation.*'], module: 'reports' },
                { label: 'Antigüedad de inventario', href: route('reports.inventory-aging.index'), match: ['reports.inventory-aging.*'], module: 'reports' },
                { label: 'Artículos', href: route('items.index'), match: ['items.index', 'items.kardex', 'item-serials.*'] },
                { label: 'Listas de precios', href: route('price-lists.index'), match: ['price-lists.*'] },
                { label: 'Movimientos', href: route('inventory-movements.index'), match: ['inventory-movements.*'] },
                { label: 'Traslados', href: route('stock-transfers.index'), match: ['stock-transfers.*'] },
                { label: 'Órdenes de compra', href: route('purchase-orders.index'), match: ['purchase-orders.*'] },
                { label: 'Sugerencia de compra', href: route('reorder.index'), match: ['reorder.*'] },
                { label: 'Tomas físicas', href: route('stock-counts.index'), match: ['stock-counts.*'] },
                { label: 'Deterioro (NIC 2)', href: route('inventory-write-downs.index'), match: ['inventory-write-downs.*'] },
                { label: 'Lotes por vencer', href: route('lot-expiry.index'), match: ['lot-expiry.*'] },
                { label: 'Facturas de proveedor', href: route('supplier-invoices.index'), match: ['supplier-invoices.*'] },
                { label: 'Costos de importación', href: route('landed-costs.index'), match: ['landed-costs.*'] },
                { label: 'Rubros de nacionalización', href: route('import-costs.index'), match: ['import-costs.*'] },
                { label: 'Órdenes de fabricación', href: route('production-orders.index'), match: ['production-orders.*'] },
                { label: 'Listas de materiales', href: route('bills-of-materials.index'), match: ['bills-of-materials.*'] },
                { label: 'Grupos de artículos', href: route('item-groups.index'), match: ['item-groups.*'] },
                { label: 'Almacenes', href: route('warehouses.index'), match: ['warehouses.*', 'warehouse-bins.*'] },
                { label: 'Unidades de medida', href: route('units-of-measure.index'), match: ['units-of-measure.*'] },
                { label: 'Determinación de cuentas', href: route('gl-determinations.index'), match: ['gl-determinations.*'] },
            ],
        },
        {
            label: 'Facturación', icon: ReceiptIcon, module: 'billing',
            match: ['sales-documents.*', 'sales-orders.*', 'billing-settings.*', 'price-overrides.*'],
            children: [
                { label: 'Órdenes de pedido', href: route('sales-orders.index'), match: ['sales-orders.*'] },
                { label: 'Comprobantes', href: route('sales-documents.index'), match: ['sales-documents.index', 'sales-documents.show'] },
                { label: 'Nueva factura', href: route('sales-documents.create'), match: ['sales-documents.create'] },
                { label: 'Cambios de precio autorizados', href: route('price-overrides.index'), match: ['price-overrides.*'] },
                { label: 'Configuración', href: route('billing-settings.index'), match: ['billing-settings.*'] },
            ],
        },
        {
            label: 'Planillas', icon: UsersIcon, module: 'payroll',
            match: ['employees.*', 'payroll-periods.*', 'payslips.*', 'employee-deductions.*', 'recurring-inputs.*', 'personnel-actions.*', 'vacations.*', 'labor-settlements.*', 'payroll-reports.*', 'job-structure.*', 'payroll-settings.*'],
            children: [
                { label: 'Empleados', href: route('employees.index'), match: ['employees.*'] },
                { label: 'Períodos de planilla', href: route('payroll-periods.index'), match: ['payroll-periods.*', 'payslips.*'] },
                { label: 'Acciones de personal', href: route('personnel-actions.index'), match: ['personnel-actions.*'] },
                { label: 'Vacaciones', href: route('vacations.index'), match: ['vacations.*'] },
                { label: 'Liquidaciones laborales', href: route('labor-settlements.index'), match: ['labor-settlements.*'] },
                { label: 'Reportes', href: route('payroll-reports.index'), match: ['payroll-reports.*'] },
                { label: 'Departamentos y puestos', href: route('job-structure.index'), match: ['job-structure.*', 'departments.*', 'job-positions.*'] },
                { label: 'Rubros fijos', href: route('recurring-inputs.index'), match: ['recurring-inputs.*'] },
                { label: 'Deducciones y préstamos', href: route('employee-deductions.index'), match: ['employee-deductions.*'] },
                { label: 'Configuración', href: route('payroll-settings.index'), match: ['payroll-settings.*'] },
            ],
        },
        {
            label: 'Socios de negocio', icon: HandshakeIcon, module: 'business_partners',
            match: ['business-partners.*', 'bp-categories.*', 'reports.aging.*'],
            children: [
                { label: 'Socios de negocio', href: route('business-partners.index'), match: ['business-partners.*'] },
                { label: 'Categorías de socios', href: route('bp-categories.index'), match: ['bp-categories.*'] },
                // La antigüedad de saldos es de clientes y proveedores: su
                // módulo es este, no contabilidad.
                { label: 'Antigüedad de saldos', href: route('reports.aging.index'), match: ['reports.aging.*'], module: 'reports' },
            ],
        },
        {
            label: 'Bancos', icon: LandmarkIcon, module: 'banking',
            match: ['bank-accounts.*', 'bank-reconciliations.*', 'bank-reconciliation-report.*', 'reports.cash-flow-projection.*'],
            children: [
                { label: 'Cuentas bancarias', href: route('bank-accounts.index'), match: ['bank-accounts.*'] },
                { label: 'Conciliaciones bancarias', href: route('bank-reconciliations.hub'), match: ['bank-reconciliations.*'] },
                { label: 'Reporte de conciliaciones', href: route('bank-reconciliation-report.index'), match: ['bank-reconciliation-report.*'] },
                // Qué se espera cobrar y pagar: es tesorería, no contabilidad.
                { label: 'Proyección de cobros y pagos', href: route('reports.cash-flow-projection.index'), match: ['reports.cash-flow-projection.*'], module: 'reports' },
            ],
        },
        {
            label: 'Impuestos', icon: PercentIcon,
            match: ['tax-rates.*', 'tax-report.*'],
            children: [
                { label: 'Indicadores de impuesto', href: route('tax-rates.index'), match: ['tax-rates.*'] },
                { label: 'Reporte de IVA', href: route('tax-report.index'), match: ['tax-report.*'], module: 'tax' },
            ],
        },
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
        // La contrapartida: el permiso de módulo de cada reporte sigue siendo
        // `reports`, pero ahora también hay que poder ver el módulo que lo
        // contiene para que aparezca en el menú. Un usuario con reportería y
        // sin contabilidad ya no ve el balance en el menú —aunque la dirección
        // le sigue funcionando, porque el gate del backend es el de reportes—.
        // Es el precio de que los reportes vivan donde se los busca.
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
            label: 'Administración', icon: SettingsIcon,
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

// Hasta 1024px (una tablet, incluso en horizontal) la barra lateral le quita
// al contenido el espacio que las tablas necesitan: pasa a ser un panel que
// se abre con el botón de menú, siempre completo —el modo colapsado es solo
// del escritorio—. Se cierra al navegar, al tocar fuera de él o con Escape.
const narrowQuery = typeof window !== 'undefined' ? window.matchMedia('(max-width: 1024px)') : null;
const narrow = ref(narrowQuery?.matches ?? false);
const navOpen = ref(false);

// Colapsado de verdad (solo íconos): en escritorio y si el usuario lo pidió.
const compact = computed(() => collapsed.value && !narrow.value);

function onNarrowChange(event) {
    narrow.value = event.matches;
    if (!event.matches) navOpen.value = false;
}

function closeNavOnEscape(event) {
    if (event.key === 'Escape' && navOpen.value) navOpen.value = false;
}

watch(() => page.url, () => {
    navOpen.value = false;
});

onMounted(() => {
    const storedSidebar = localStorage.getItem('contapp-sidebar');
    collapsed.value = storedSidebar === 'collapsed';

    const storedTheme = localStorage.getItem('contapp-theme');
    theme.value = storedTheme === 'dark' ? 'dark' : (storedTheme === 'light' ? 'light' : theme.value);

    narrowQuery?.addEventListener('change', onNarrowChange);
    window.addEventListener('keydown', closeNavOnEscape);
});

onBeforeUnmount(() => {
    narrowQuery?.removeEventListener('change', onNarrowChange);
    window.removeEventListener('keydown', closeNavOnEscape);
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
    <div class="app-shell" :class="{ 'is-collapsed': compact, 'nav-open': navOpen }">
        <aside id="app-nav" class="sidebar">
            <div class="sidebar-brand">
                <span class="brand-mark">C</span>
                <span v-if="!compact" class="brand-name">CONTAPP</span>
                <button type="button" class="nav-close" aria-label="Cerrar menú" @click="navOpen = false"><XIcon :size="18" /></button>
            </div>

            <nav class="sidebar-nav" aria-label="Menú principal">
                <template v-for="item in nav" :key="item.label">
                    <!-- Colapsado se ve solo el ícono: el nombre queda como
                         tooltip y como etiqueta para el lector de pantalla. -->
                    <Link
                        v-if="!item.children || compact"
                        :href="item.children ? item.children[0].href : item.href"
                        class="sidebar-link"
                        :class="{ active: isCurrent(item.match) }"
                        :title="compact ? item.label : null"
                        :aria-label="compact ? item.label : null"
                    >
                        <span class="sidebar-icon"><component :is="item.icon" :size="18" /></span>
                        <span v-if="!compact" class="sidebar-label">{{ item.label }}</span>
                    </Link>

                    <div v-else class="sidebar-group">
                        <button
                            type="button"
                            class="sidebar-link sidebar-group-toggle"
                            :class="{ active: isCurrent(item.match), 'is-open': isGroupExpanded(item) }"
                            :aria-expanded="isGroupExpanded(item)"
                            @click="toggleGroup(item)"
                        >
                            <span class="sidebar-icon-chip"><component :is="item.icon" :size="16" /></span>
                            <span class="sidebar-label">{{ item.label }}</span>
                            <ChevronRightIcon class="sidebar-chevron" :class="{ open: isGroupExpanded(item) }" />
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

            <button
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

        <div class="app-main">
            <header class="topbar">
                <div class="topbar-heading">
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
                    <select
                        v-if="companies.length"
                        class="company-select"
                        aria-label="Compañía"
                        :value="currentCompanyId"
                        @change="switchCompany"
                    >
                        <option v-for="c in companies" :key="c.id" :value="c.id">
                            {{ c.trade_name || c.legal_name }}
                        </option>
                    </select>

                    <button type="button" class="btn btn-ghost topbar-icon-btn" title="Cambiar tema" aria-label="Cambiar tema" @click="toggleTheme">
                        <SunIcon v-if="theme === 'dark'" />
                        <MoonIcon v-else />
                    </button>

                    <span v-if="roleLabel" class="badge badge-role">{{ roleLabel }}</span>
                    <span v-if="user" class="topbar-user">{{ user.name }}</span>

                    <Link
                        v-if="user"
                        :href="route('logout')"
                        method="delete"
                        as="button"
                        class="btn btn-ghost logout-btn"
                        title="Salir del sistema"
                    >
                        <LogOutIcon />
                        <span class="logout-label">Salir del sistema</span>
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
                <span>Licencia {{ page.props.license.category ?? '—' }} · {{ page.props.license.masked_code }} · Vence {{ page.props.license.expires_at }}</span>
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
    background: var(--color-primary);
    color: var(--color-on-primary);
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

.brand-mark {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
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
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.25rem;
    flex-shrink: 0;
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
    flex-shrink: 0;
    margin-left: auto;
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

/* Si no cabe todo, cede primero el nombre del usuario (se recorta), no el
   título de la pantalla: el título conserva un mínimo legible. */
.topbar-actions {
    display: flex;
    align-items: center;
    flex: 0 1 auto;
    min-width: 0;
    gap: 0.5rem;
}

.topbar-actions > :not(.topbar-user) {
    flex-shrink: 0;
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

.topbar-user {
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    font-size: 0.82rem;
    color: var(--color-text-muted);
    white-space: nowrap;
}

@media (min-width: 641px) {
    .topbar-heading {
        min-width: 11rem;
    }
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
    color: var(--color-primary);
    font-weight: 700;
    white-space: nowrap;
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

    /* El panel siempre se abre completo: colapsar es cosa del escritorio. */
    .sidebar-collapse-btn {
        display: none;
    }

    .badge-role {
        display: none;
    }
}

@media (max-width: 768px) {
    .topbar-user {
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
    .flash { margin: 0.75rem 0.75rem 0; }
    .app-footer { padding: 0.5rem 0.75rem; }
    .app-footer-meta { margin-left: 0; }
}

@media (prefers-reduced-motion: reduce) {
    .sidebar,
    .nav-open .sidebar {
        transition: none;
    }
}
</style>
