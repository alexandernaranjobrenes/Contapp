<script setup>
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import BackofficeLayout from '../../../Layouts/BackofficeLayout.vue';
import DetailModal from '../../../Components/DetailModal.vue';
import MoneyInput from '../../../Components/MoneyInput.vue';
import { confirmAction } from '../../../Utils/confirm';
import { useCrudModal } from '../../../Utils/crudModal';
import { formatPrice, formatRate, normalizeSelection, quote } from '../../../Utils/pricing';
import { DownloadIcon, ExternalLinkIcon, PencilIcon, PlusIcon, SaveIcon, Trash2Icon } from '@lucide/vue';

/**
 * Planes y precios (CLAUDE.md secc. 42): lo que se vende en pivoxcr.com/precios.
 *
 * - Ajustes: la moneda con que abre la página, el IVA y adónde llegan las
 *   solicitudes.
 * - Funciones: cada cosa que se vende, con su precio mensual y anual en
 *   colones y en dólares, y lo que necesita.
 * - Paquetes: puntos de partida que el visitante ajusta, con su forma de
 *   pago y un descuento opcional.
 *
 * Tablas, fichas y formularios en el mismo modal (secc. 20 y 21). El precio
 * de un paquete se calcula acá con lo mismo que usa el sitio (Utils/pricing.js).
 */
const props = defineProps({
    settings: { type: Object, required: true },
    features: { type: Array, required: true },
    packages: { type: Array, required: true },
    catalog: { type: Object, required: true },
    icons: { type: Array, required: true },
    modules: { type: Object, required: true },
    siteUrl: { type: String, required: true },
});

const iconSvg = (name) => props.icons.find((icon) => icon.name === name)?.svg ?? '';
// Como en la página de precios: «₡18.000», «US$36».
const money = (value, currency) => (value === null || value === '' ? '—' : formatPrice(Math.round(Number(value) * 100), currency));

// ── Ajustes ───────────────────────────────────────────────────────────────

const settingsForm = useForm({ ...props.settings });

function saveSettings() {
    settingsForm
        .transform((data) => ({ ...data, prices_include_tax: data.prices_include_tax ? 1 : 0 }))
        .put(route('backoffice.pricing.settings.update'), { preserveScroll: true });
}

function loadDefaults() {
    router.post(route('backoffice.pricing.defaults'), {}, { preserveScroll: true });
}

// ── Funciones ─────────────────────────────────────────────────────────────

const groups = computed(() => [...new Set(props.features.map((feature) => feature.group))]);
const nextOrder = (rows) => (rows.length ? Math.max(...rows.map((row) => row.sort_order)) + 10 : 10);
const nullable = (value) => (value === '' || value === null || value === undefined ? null : value);

const featureCrud = useCrudModal({
    records: () => props.features,
    defaults: () => ({
        key: '', name: '', description: '', icon: 'sparkles', group: groups.value[0] ?? '', kind: 'toggle',
        unit_singular: '', unit_plural: '', included_quantity: 0, max_quantity: '',
        monthly_crc: '0', annual_crc: '', monthly_usd: '0', annual_usd: '',
        is_required: false, is_active: true, module_key: '', sort_order: nextOrder(props.features), requires: [],
    }),
    toForm: (feature) => ({
        key: feature.key, name: feature.name, description: feature.description ?? '', icon: feature.icon, group: feature.group,
        kind: feature.kind, unit_singular: feature.unit_singular ?? '', unit_plural: feature.unit_plural ?? '',
        included_quantity: feature.included_quantity, max_quantity: feature.max_quantity ?? '',
        monthly_crc: String(feature.monthly_crc), annual_crc: feature.annual_crc === null ? '' : String(feature.annual_crc),
        monthly_usd: String(feature.monthly_usd), annual_usd: feature.annual_usd === null ? '' : String(feature.annual_usd),
        is_required: feature.is_required, is_active: feature.is_active, module_key: feature.module_key ?? '',
        sort_order: feature.sort_order, requires: [...feature.requires],
    }),
    store: () => route('backoffice.pricing.features.store'),
    update: (feature) => route('backoffice.pricing.features.update', feature.id),
    storePayload: (data) => featurePayload(data),
    updatePayload: (data) => featurePayload(data),
});

function featurePayload(data) {
    return {
        ...data,
        annual_crc: nullable(data.annual_crc),
        annual_usd: nullable(data.annual_usd),
        max_quantity: nullable(data.max_quantity),
        module_key: nullable(data.module_key),
        is_required: data.is_required ? 1 : 0,
        is_active: data.is_active ? 1 : 0,
    };
}

const featureTitle = computed(() => (featureCrud.mode.value === 'create' ? 'Nueva función' : featureCrud.selected.value?.name ?? ''));
const otherFeatures = computed(() => props.features.filter((feature) => feature.id !== featureCrud.selected.value?.id));

function destroyFeature() {
    const feature = featureCrud.selected.value;

    confirmAction({
        title: 'Eliminar función',
        message: feature.packages_count
            ? `«${feature.name}» se elimina y se quita de ${feature.packages_count} ${feature.packages_count === 1 ? 'paquete' : 'paquetes'}. Las solicitudes ya recibidas conservan su cotización.`
            : `«${feature.name}» se elimina. Las solicitudes ya recibidas conservan su cotización.`,
        confirmLabel: 'Eliminar',
        danger: true,
        onConfirm: () => router.delete(route('backoffice.pricing.features.destroy', feature.id), {
            preserveScroll: true,
            onSuccess: () => featureCrud.close(),
        }),
    });
}

const requirementNames = (feature) => feature.requires
    .map((id) => props.features.find((other) => other.id === id)?.name)
    .filter(Boolean)
    .join(', ');

// ── Paquetes ──────────────────────────────────────────────────────────────

const packageCrud = useCrudModal({
    records: () => props.packages,
    defaults: () => ({
        name: '', tagline: '', description: '', badge: '', is_highlighted: false, billing: 'both', discount_percent: '0',
        is_active: true, sort_order: nextOrder(props.packages), features: requiredFeatures(),
    }),
    toForm: (pkg) => ({
        name: pkg.name, tagline: pkg.tagline ?? '', description: pkg.description ?? '', badge: pkg.badge ?? '',
        is_highlighted: pkg.is_highlighted, billing: pkg.billing, discount_percent: String(pkg.discount_percent),
        is_active: pkg.is_active, sort_order: pkg.sort_order, features: { ...pkg.features },
    }),
    store: () => route('backoffice.pricing.packages.store'),
    update: (pkg) => route('backoffice.pricing.packages.update', pkg.id),
    storePayload: (data) => packagePayload(data),
    updatePayload: (data) => packagePayload(data),
});

function packagePayload(data) {
    return {
        ...data,
        is_highlighted: data.is_highlighted ? 1 : 0,
        is_active: data.is_active ? 1 : 0,
        discount_percent: nullable(data.discount_percent) ?? 0,
    };
}

function requiredFeatures() {
    return Object.fromEntries(props.features
        .filter((feature) => feature.is_required)
        .map((feature) => [feature.id, feature.kind === 'quantity' ? Math.max(1, feature.included_quantity) : 1]));
}

const packageTitle = computed(() => (packageCrud.mode.value === 'create' ? 'Nuevo paquete' : packageCrud.selected.value?.name ?? ''));
const catalogById = computed(() => Object.fromEntries(props.catalog.features.map((feature) => [feature.id, feature])));

/** El paquete (id → cantidad) con lo que necesitan sus funciones, como lo guarda el servidor. */
function completed(featureMap) {
    const selection = {};

    for (const [id, quantity] of Object.entries(featureMap)) {
        const feature = catalogById.value[id];
        if (feature && quantity > 0) selection[feature.key] = quantity;
    }

    const { quantities } = normalizeSelection(props.catalog.features, selection);

    return quantities;
}

/** El precio de un paquete, en las dos monedas, con lo mismo que usa el sitio. */
function packagePrices(pkg) {
    const quantities = completed(pkg.features);
    const features = Object.fromEntries(Object.entries(quantities).filter(([, quantity]) => quantity > 0));
    const catalog = {
        ...props.catalog,
        packages: [{ slug: '__preview', name: pkg.name, billing: pkg.billing, discount_bp: Math.round(Number(pkg.discount_percent || 0) * 100), features }],
    };
    const cycle = pkg.billing === 'annual' ? 'annual' : 'monthly';
    const price = (currency, chosenCycle) => {
        const result = quote(catalog, '__preview', features, chosenCycle, currency);

        return props.settings.prices_include_tax ? result.total : result.net;
    };

    return {
        cycle,
        crc: price('CRC', cycle),
        usd: price('USD', cycle),
        annualCrc: pkg.billing === 'both' ? price('CRC', 'annual') : null,
        annualUsd: pkg.billing === 'both' ? price('USD', 'annual') : null,
        inactive: Object.keys(features).filter((key) => !props.catalog.features.find((feature) => feature.key === key)?.active),
    };
}

const preview = computed(() => packagePrices(packageCrud.form));

function toggleFeature(feature, checked) {
    const next = { ...packageCrud.form.features };

    if (checked) next[feature.id] = feature.kind === 'quantity' ? Math.max(1, feature.included_quantity) : 1;
    else delete next[feature.id];

    // Lo que necesita, solo, como al guardar.
    const quantities = completed(next);
    for (const catalogFeature of props.catalog.features) {
        if (quantities[catalogFeature.key] > 0 && !(catalogFeature.id in next)) next[catalogFeature.id] = quantities[catalogFeature.key];
    }

    packageCrud.form.features = next;
}

function setPackageQuantity(feature, value) {
    const quantity = Math.max(1, Number.parseInt(value, 10) || 1);
    packageCrud.form.features = { ...packageCrud.form.features, [feature.id]: feature.max_quantity ? Math.min(quantity, feature.max_quantity) : quantity };
}

const billingLabel = { monthly: 'Mensual', annual: 'Anual', both: 'Mensual o anual' };

function destroyPackage() {
    const pkg = packageCrud.selected.value;

    confirmAction({
        title: 'Eliminar paquete',
        message: `«${pkg.name}» deja de verse en la página de precios. Las solicitudes que lo pidieron conservan su cotización.`,
        confirmLabel: 'Eliminar',
        danger: true,
        onConfirm: () => router.delete(route('backoffice.pricing.packages.destroy', pkg.id), {
            preserveScroll: true,
            onSuccess: () => packageCrud.close(),
        }),
    });
}
</script>

<template>
    <Head title="Planes y precios" />

    <BackofficeLayout title="Planes y precios">
        <div class="view-toolbar">
            <div class="view-actions">
                <a :href="siteUrl" target="_blank" rel="noopener" class="btn btn-ghost"><ExternalLinkIcon /> Ver la página de precios</a>
            </div>
        </div>

        <p class="hint">
            Lo que se vende en la página de precios del sitio: cada función con su precio, y paquetes que el visitante ajusta
            agregando o quitando funciones. Una función o un paquete inactivo no se ve en el sitio.
        </p>

        <!-- Ajustes -->
        <h2 class="block-title">Ajustes</h2>
        <div class="card settings-card">
            <form class="form-grid" @submit.prevent="saveSettings">
                <div class="field">
                    <label for="settings-currency">Moneda con que abre la página</label>
                    <select id="settings-currency" v-model="settingsForm.default_currency">
                        <option value="CRC">Colones (₡)</option>
                        <option value="USD">Dólares (US$)</option>
                    </select>
                    <span v-if="settingsForm.errors.default_currency" class="error">{{ settingsForm.errors.default_currency }}</span>
                </div>
                <div class="field">
                    <label for="settings-tax">IVA (%)</label>
                    <input id="settings-tax" v-model="settingsForm.tax_rate" type="number" min="0" max="100" step="0.01" required>
                    <span v-if="settingsForm.errors.tax_rate" class="error">{{ settingsForm.errors.tax_rate }}</span>
                </div>
                <div class="field">
                    <label for="settings-email">Correo que recibe las solicitudes</label>
                    <input id="settings-email" v-model="settingsForm.notification_email" type="email" required>
                    <span v-if="settingsForm.errors.notification_email" class="error">{{ settingsForm.errors.notification_email }}</span>
                </div>
                <div class="field">
                    <span class="field-label">Precios</span>
                    <label class="check settings-check"><input v-model="settingsForm.prices_include_tax" type="checkbox"> Los precios ya incluyen el IVA</label>
                </div>
                <div class="form-actions span-full">
                    <button type="submit" class="btn btn-primary" :disabled="settingsForm.processing"><SaveIcon /> Guardar ajustes</button>
                </div>
            </form>
        </div>

        <!-- Funciones -->
        <h2 class="block-title">Funciones</h2>
        <div class="card">
            <div class="card-header">
                <span class="muted">{{ features.length }} {{ features.length === 1 ? 'función' : 'funciones' }} · {{ features.filter((f) => f.is_active).length }} activas</span>
                <span class="header-actions">
                    <button v-if="!features.length" type="button" class="btn btn-ghost" @click="loadDefaults"><DownloadIcon /> Cargar funciones sugeridas</button>
                    <button type="button" class="btn btn-primary" @click="featureCrud.openCreate()"><PlusIcon /> Crear nuevo</button>
                </span>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Función</th>
                            <th class="right">Mensual</th>
                            <th class="right">Anual</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="feature in features"
                            :key="feature.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="featureCrud.openDetail(feature)"
                            @keydown.enter="featureCrud.openDetail(feature)"
                            @keydown.space.prevent="featureCrud.openDetail(feature)"
                        >
                            <td>
                                <span class="feature-cell">
                                    <span class="feature-icon" aria-hidden="true" v-html="feature.icon_svg" />
                                    <span>
                                        <strong>{{ feature.name }}</strong>
                                        <span class="block muted small">{{ feature.group }}<template v-if="feature.kind === 'quantity'"> · por {{ feature.unit_singular }}</template></span>
                                    </span>
                                </span>
                            </td>
                            <td data-label="Mensual" class="right">{{ money(feature.monthly_crc, 'CRC') }}<span class="block muted small">{{ money(feature.monthly_usd, 'USD') }}</span></td>
                            <td data-label="Anual" class="right">
                                <template v-if="feature.annual_crc !== null">{{ money(feature.annual_crc, 'CRC') }}</template>
                                <template v-else>12 × mensual</template>
                                <span v-if="feature.annual_usd !== null" class="block muted small">{{ money(feature.annual_usd, 'USD') }}</span>
                            </td>
                            <td data-label="Estado">
                                <span class="badge" :class="feature.is_active ? 'badge-success' : 'badge-neutral'">{{ feature.is_active ? 'Activa' : 'Inactiva' }}</span>
                                <span v-if="feature.is_required" class="block muted small">Obligatoria</span>
                            </td>
                        </tr>
                        <tr v-if="!features.length">
                            <td colspan="4" class="muted empty-row">
                                Todavía no hay funciones. «Cargar funciones sugeridas» trae las de los módulos de Pivox, sin precio e inactivas.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Paquetes -->
        <h2 class="block-title">Paquetes</h2>
        <div class="card">
            <div class="card-header">
                <span class="muted">{{ packages.length }} {{ packages.length === 1 ? 'paquete' : 'paquetes' }}</span>
                <button type="button" class="btn btn-primary" :disabled="!features.length" @click="packageCrud.openCreate()"><PlusIcon /> Crear nuevo</button>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Paquete</th>
                            <th>Pago</th>
                            <th class="right">Precio</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="pkg in packages"
                            :key="pkg.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="packageCrud.openDetail(pkg)"
                            @keydown.enter="packageCrud.openDetail(pkg)"
                            @keydown.space.prevent="packageCrud.openDetail(pkg)"
                        >
                            <td>
                                <strong>{{ pkg.name }}</strong>
                                <span v-if="pkg.badge" class="badge badge-warning package-badge">{{ pkg.badge }}</span>
                                <span class="block muted small">{{ Object.keys(pkg.features).length }} funciones<template v-if="pkg.discount_percent > 0"> · {{ formatRate(pkg.discount_percent) }} % de descuento</template></span>
                            </td>
                            <td data-label="Pago">{{ billingLabel[pkg.billing] }}</td>
                            <td data-label="Precio" class="right">
                                {{ formatPrice(packagePrices(pkg).crc, 'CRC') }}
                                <span class="block muted small">{{ formatPrice(packagePrices(pkg).usd, 'USD') }} {{ packagePrices(pkg).cycle === 'annual' ? 'por año' : 'por mes' }}</span>
                            </td>
                            <td data-label="Estado">
                                <span class="badge" :class="pkg.is_active ? 'badge-success' : 'badge-neutral'">{{ pkg.is_active ? 'Activo' : 'Inactivo' }}</span>
                                <span v-if="pkg.is_highlighted" class="block muted small">Destacado</span>
                            </td>
                        </tr>
                        <tr v-if="!packages.length">
                            <td colspan="4" class="muted empty-row">Todavía no hay paquetes. Armá el primero con «Crear nuevo».</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Función -->
        <DetailModal :open="featureCrud.modalOpen.value" :title="featureTitle" wide @close="featureCrud.close">
            <template v-if="featureCrud.selected.value && featureCrud.mode.value === 'details'">
                <dl class="detail-list">
                    <div><dt>Grupo</dt><dd>{{ featureCrud.selected.value.group }}</dd></div>
                    <div><dt>Clave</dt><dd><code>{{ featureCrud.selected.value.key }}</code></dd></div>
                    <div class="full" v-if="featureCrud.selected.value.description"><dt>Descripción</dt><dd>{{ featureCrud.selected.value.description }}</dd></div>
                    <div>
                        <dt>Se cobra</dt>
                        <dd>
                            <template v-if="featureCrud.selected.value.kind === 'quantity'">
                                Por {{ featureCrud.selected.value.unit_singular }}<template v-if="featureCrud.selected.value.included_quantity">, {{ featureCrud.selected.value.included_quantity }} sin costo</template><template v-if="featureCrud.selected.value.max_quantity">, hasta {{ featureCrud.selected.value.max_quantity }}</template>
                            </template>
                            <template v-else>Se elige o no</template>
                        </dd>
                    </div>
                    <div><dt>Módulo de Pivox</dt><dd>{{ modules[featureCrud.selected.value.module_key] ?? '—' }}</dd></div>
                    <div><dt>Mensual</dt><dd>{{ money(featureCrud.selected.value.monthly_crc, 'CRC') }} · {{ money(featureCrud.selected.value.monthly_usd, 'USD') }}</dd></div>
                    <div>
                        <dt>Anual</dt>
                        <dd>
                            {{ featureCrud.selected.value.annual_crc !== null ? money(featureCrud.selected.value.annual_crc, 'CRC') : '12 × mensual' }}
                            · {{ featureCrud.selected.value.annual_usd !== null ? money(featureCrud.selected.value.annual_usd, 'USD') : '12 × mensual' }}
                        </dd>
                    </div>
                    <div class="full"><dt>Necesita</dt><dd>{{ requirementNames(featureCrud.selected.value) || 'Nada más' }}</dd></div>
                    <div><dt>Estado</dt><dd>{{ featureCrud.selected.value.is_active ? 'Activa: se ve en el sitio' : 'Inactiva: no se ve en el sitio' }}{{ featureCrud.selected.value.is_required ? ' · obligatoria' : '' }}</dd></div>
                    <div><dt>En paquetes</dt><dd>{{ featureCrud.selected.value.packages_count }}</dd></div>
                </dl>
            </template>

            <form v-if="featureCrud.mode.value !== 'details'" id="feature-form" @submit.prevent="featureCrud.submit">
                <div class="form-grid">
                    <div class="field">
                        <label for="feature-name">Nombre</label>
                        <input id="feature-name" v-model="featureCrud.form.name" type="text" maxlength="120" required>
                        <span v-if="featureCrud.form.errors.name" class="error">{{ featureCrud.form.errors.name }}</span>
                    </div>
                    <div class="field">
                        <label for="feature-group">Grupo</label>
                        <input id="feature-group" v-model="featureCrud.form.group" type="text" maxlength="60" list="feature-groups" required placeholder="Por ejemplo: Operación">
                        <datalist id="feature-groups"><option v-for="group in groups" :key="group" :value="group" /></datalist>
                        <span v-if="featureCrud.form.errors.group" class="error">{{ featureCrud.form.errors.group }}</span>
                    </div>
                    <div class="field">
                        <label for="feature-key">Clave (opcional)</label>
                        <input id="feature-key" v-model="featureCrud.form.key" type="text" maxlength="60" placeholder="Se arma del nombre">
                        <span v-if="featureCrud.form.errors.key" class="error">{{ featureCrud.form.errors.key }}</span>
                    </div>
                    <div class="field span-full">
                        <label for="feature-description">Descripción (opcional)</label>
                        <input id="feature-description" v-model="featureCrud.form.description" type="text" maxlength="300">
                    </div>

                    <div class="field span-full">
                        <span class="field-label">Ícono</span>
                        <div class="icon-picker" role="radiogroup" aria-label="Ícono">
                            <button
                                v-for="icon in icons"
                                :key="icon.name"
                                type="button"
                                role="radio"
                                class="icon-option"
                                :class="{ 'is-active': featureCrud.form.icon === icon.name }"
                                :aria-checked="featureCrud.form.icon === icon.name"
                                :aria-label="icon.name"
                                :title="icon.name"
                                @click="featureCrud.form.icon = icon.name"
                            ><span v-html="icon.svg" /></button>
                        </div>
                        <span v-if="featureCrud.form.errors.icon" class="error">{{ featureCrud.form.errors.icon }}</span>
                    </div>

                    <div class="field">
                        <label for="feature-kind">Cómo se cobra</label>
                        <select id="feature-kind" v-model="featureCrud.form.kind">
                            <option value="toggle">Se elige o no (un módulo, un servicio)</option>
                            <option value="quantity">Por cantidad (compañías, usuarios)</option>
                        </select>
                    </div>
                    <template v-if="featureCrud.form.kind === 'quantity'">
                        <div class="field">
                            <label for="feature-unit">Unidad</label>
                            <div class="field-row">
                                <input id="feature-unit" v-model="featureCrud.form.unit_singular" type="text" maxlength="40" placeholder="compañía" aria-label="Unidad en singular">
                                <input v-model="featureCrud.form.unit_plural" type="text" maxlength="40" placeholder="compañías" aria-label="Unidad en plural">
                            </div>
                            <span v-if="featureCrud.form.errors.unit_singular || featureCrud.form.errors.unit_plural" class="error">{{ featureCrud.form.errors.unit_singular || featureCrud.form.errors.unit_plural }}</span>
                        </div>
                        <div class="field">
                            <label for="feature-included">Incluidas sin costo</label>
                            <input id="feature-included" v-model="featureCrud.form.included_quantity" type="number" min="0" max="9999">
                        </div>
                        <div class="field">
                            <label for="feature-max">Máximo (opcional)</label>
                            <input id="feature-max" v-model="featureCrud.form.max_quantity" type="number" min="1" max="9999">
                            <span v-if="featureCrud.form.errors.max_quantity" class="error">{{ featureCrud.form.errors.max_quantity }}</span>
                        </div>
                    </template>

                    <div class="field">
                        <label for="feature-monthly-crc">Precio mensual en colones<template v-if="featureCrud.form.kind === 'quantity'"> (por unidad)</template></label>
                        <MoneyInput id="feature-monthly-crc" v-model="featureCrud.form.monthly_crc" required />
                        <span v-if="featureCrud.form.errors.monthly_crc" class="error">{{ featureCrud.form.errors.monthly_crc }}</span>
                    </div>
                    <div class="field">
                        <label for="feature-annual-crc">Precio anual en colones (opcional)</label>
                        <MoneyInput id="feature-annual-crc" v-model="featureCrud.form.annual_crc" placeholder="12 × mensual" />
                        <span v-if="featureCrud.form.errors.annual_crc" class="error">{{ featureCrud.form.errors.annual_crc }}</span>
                    </div>
                    <div class="field">
                        <label for="feature-monthly-usd">Precio mensual en dólares<template v-if="featureCrud.form.kind === 'quantity'"> (por unidad)</template></label>
                        <MoneyInput id="feature-monthly-usd" v-model="featureCrud.form.monthly_usd" required />
                        <span v-if="featureCrud.form.errors.monthly_usd" class="error">{{ featureCrud.form.errors.monthly_usd }}</span>
                    </div>
                    <div class="field">
                        <label for="feature-annual-usd">Precio anual en dólares (opcional)</label>
                        <MoneyInput id="feature-annual-usd" v-model="featureCrud.form.annual_usd" placeholder="12 × mensual" />
                        <span v-if="featureCrud.form.errors.annual_usd" class="error">{{ featureCrud.form.errors.annual_usd }}</span>
                    </div>

                    <div class="field">
                        <label for="feature-module">Módulo de Pivox (opcional)</label>
                        <select id="feature-module" v-model="featureCrud.form.module_key">
                            <option value="">Ninguno</option>
                            <option v-for="(label, key) in modules" :key="key" :value="key">{{ label }}</option>
                        </select>
                        <span class="field-hint">Para cuando la licencia limite módulos. Hoy es informativo.</span>
                    </div>
                    <div class="field">
                        <label for="feature-order">Orden</label>
                        <input id="feature-order" v-model="featureCrud.form.sort_order" type="number" min="0" max="65000">
                    </div>

                    <div class="field span-full">
                        <span class="field-label">Necesita (se suman solas al elegirla)</span>
                        <div class="check-grid">
                            <label v-for="other in otherFeatures" :key="other.id" class="check">
                                <input v-model="featureCrud.form.requires" type="checkbox" :value="other.id"> {{ other.name }}
                            </label>
                            <span v-if="!otherFeatures.length" class="muted small">No hay otras funciones todavía.</span>
                        </div>
                        <span v-if="featureCrud.form.errors.requires" class="error">{{ featureCrud.form.errors.requires }}</span>
                    </div>

                    <div class="field span-full">
                        <label class="check"><input v-model="featureCrud.form.is_required" type="checkbox"> Obligatoria: va en todos los planes (como la Contabilidad)</label>
                        <label class="check"><input v-model="featureCrud.form.is_active" type="checkbox"> Activa: se ve en la página de precios</label>
                    </div>
                </div>
            </form>

            <template #actions>
                <template v-if="featureCrud.selected.value && featureCrud.mode.value === 'details'">
                    <button type="button" class="btn btn-ghost btn-danger-text footer-start" @click="destroyFeature"><Trash2Icon /> Eliminar</button>
                    <button type="button" class="btn btn-primary" @click="featureCrud.startEdit"><PencilIcon /> Editar</button>
                </template>
                <template v-else>
                    <button type="button" class="btn btn-ghost" @click="featureCrud.cancelForm">Cancelar</button>
                    <button type="submit" form="feature-form" class="btn btn-primary" :disabled="featureCrud.form.processing">Guardar</button>
                </template>
            </template>
        </DetailModal>

        <!-- Paquete -->
        <DetailModal :open="packageCrud.modalOpen.value" :title="packageTitle" wide @close="packageCrud.close">
            <template v-if="packageCrud.selected.value && packageCrud.mode.value === 'details'">
                <p v-if="packageCrud.selected.value.tagline" class="package-tagline">{{ packageCrud.selected.value.tagline }}</p>
                <dl class="detail-list">
                    <div><dt>Pago</dt><dd>{{ billingLabel[packageCrud.selected.value.billing] }}</dd></div>
                    <div><dt>Descuento</dt><dd>{{ packageCrud.selected.value.discount_percent > 0 ? `${formatRate(packageCrud.selected.value.discount_percent)} %` : 'Sin descuento' }}</dd></div>
                    <div class="full" v-if="packageCrud.selected.value.description"><dt>Descripción</dt><dd>{{ packageCrud.selected.value.description }}</dd></div>
                    <div class="full">
                        <dt>Trae</dt>
                        <dd>
                            <ul class="package-list">
                                <li v-for="(quantity, id) in packageCrud.selected.value.features" :key="id">
                                    {{ catalogById[id]?.name }}<template v-if="catalogById[id]?.kind === 'quantity'"> · {{ quantity }} {{ quantity === 1 ? catalogById[id].unit_singular : catalogById[id].unit_plural }}</template>
                                    <span v-if="catalogById[id] && !catalogById[id].active" class="badge badge-warning">Inactiva</span>
                                </li>
                            </ul>
                        </dd>
                    </div>
                    <div class="full">
                        <dt>Precio ({{ settings.prices_include_tax ? 'con IVA' : 'sin IVA' }})</dt>
                        <dd>
                            {{ formatPrice(packagePrices(packageCrud.selected.value).crc, 'CRC') }} · {{ formatPrice(packagePrices(packageCrud.selected.value).usd, 'USD') }}
                            {{ packagePrices(packageCrud.selected.value).cycle === 'annual' ? 'por año' : 'por mes' }}
                        </dd>
                    </div>
                    <div><dt>Estado</dt><dd>{{ packageCrud.selected.value.is_active ? 'Activo: se ve en el sitio' : 'Inactivo' }}{{ packageCrud.selected.value.is_highlighted ? ' · destacado' : '' }}</dd></div>
                </dl>
            </template>

            <form v-if="packageCrud.mode.value !== 'details'" id="package-form" @submit.prevent="packageCrud.submit">
                <div class="form-grid">
                    <div class="field">
                        <label for="package-name">Nombre</label>
                        <input id="package-name" v-model="packageCrud.form.name" type="text" maxlength="80" required placeholder="Por ejemplo: Pyme">
                        <span v-if="packageCrud.form.errors.name" class="error">{{ packageCrud.form.errors.name }}</span>
                    </div>
                    <div class="field">
                        <label for="package-badge">Distintivo (opcional)</label>
                        <input id="package-badge" v-model="packageCrud.form.badge" type="text" maxlength="40" placeholder="Por ejemplo: Más elegido">
                    </div>
                    <div class="field span-full">
                        <label for="package-tagline">Frase (opcional)</label>
                        <input id="package-tagline" v-model="packageCrud.form.tagline" type="text" maxlength="160" placeholder="Para quién es este paquete">
                    </div>
                    <div class="field span-full">
                        <label for="package-description">Descripción (opcional)</label>
                        <textarea id="package-description" v-model="packageCrud.form.description" rows="2" maxlength="500" />
                    </div>
                    <div class="field">
                        <label for="package-billing">Forma de pago</label>
                        <select id="package-billing" v-model="packageCrud.form.billing">
                            <option value="both">Mensual o anual (elige el visitante)</option>
                            <option value="monthly">Solo mensual</option>
                            <option value="annual">Solo anual</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="package-discount">Descuento del paquete (%)</label>
                        <input id="package-discount" v-model="packageCrud.form.discount_percent" type="number" min="0" max="90" step="0.01">
                        <span class="field-hint">Vale mientras no se le quite ninguna función.</span>
                        <span v-if="packageCrud.form.errors.discount_percent" class="error">{{ packageCrud.form.errors.discount_percent }}</span>
                    </div>
                    <div class="field">
                        <label for="package-order">Orden</label>
                        <input id="package-order" v-model="packageCrud.form.sort_order" type="number" min="0" max="65000">
                    </div>

                    <div class="field span-full">
                        <span class="field-label">Funciones que trae</span>
                        <div class="package-features">
                            <div v-for="feature in features" :key="feature.id" class="package-feature" :class="{ 'is-on': feature.id in packageCrud.form.features }">
                                <label class="check">
                                    <input
                                        type="checkbox"
                                        :checked="feature.id in packageCrud.form.features"
                                        :disabled="feature.is_required"
                                        @change="toggleFeature(feature, $event.target.checked)"
                                    >
                                    <span>
                                        {{ feature.name }}
                                        <span v-if="feature.is_required" class="muted small">· obligatoria</span>
                                        <span v-if="!feature.is_active" class="badge badge-warning">Inactiva</span>
                                    </span>
                                </label>
                                <input
                                    v-if="feature.kind === 'quantity' && feature.id in packageCrud.form.features"
                                    type="number"
                                    min="1"
                                    :max="feature.max_quantity ?? 9999"
                                    :value="packageCrud.form.features[feature.id]"
                                    :aria-label="`Cantidad de ${feature.unit_plural}`"
                                    class="package-quantity"
                                    @change="setPackageQuantity(feature, $event.target.value)"
                                >
                            </div>
                        </div>
                        <span v-if="packageCrud.form.errors.features" class="error">{{ packageCrud.form.errors.features }}</span>
                    </div>

                    <div class="field span-full package-preview">
                        <span class="field-label">Precio en el sitio ({{ settings.prices_include_tax ? 'con IVA' : 'sin IVA' }})</span>
                        <p>
                            <strong>{{ formatPrice(preview.crc, 'CRC') }}</strong> · <strong>{{ formatPrice(preview.usd, 'USD') }}</strong>
                            {{ preview.cycle === 'annual' ? 'por año' : 'por mes' }}
                            <template v-if="preview.annualCrc !== null"> · por año: {{ formatPrice(preview.annualCrc, 'CRC') }} · {{ formatPrice(preview.annualUsd, 'USD') }}</template>
                        </p>
                        <p v-if="preview.inactive.length" class="error">Trae funciones inactivas: no se van a ver en el sitio hasta activarlas.</p>
                    </div>

                    <div class="field span-full">
                        <label class="check"><input v-model="packageCrud.form.is_highlighted" type="checkbox"> Destacado: se ve resaltado y es el que abre el configurador</label>
                        <label class="check"><input v-model="packageCrud.form.is_active" type="checkbox"> Activo: se ve en la página de precios</label>
                    </div>
                </div>
            </form>

            <template #actions>
                <template v-if="packageCrud.selected.value && packageCrud.mode.value === 'details'">
                    <button type="button" class="btn btn-ghost btn-danger-text footer-start" @click="destroyPackage"><Trash2Icon /> Eliminar</button>
                    <button type="button" class="btn btn-primary" @click="packageCrud.startEdit"><PencilIcon /> Editar</button>
                </template>
                <template v-else>
                    <button type="button" class="btn btn-ghost" @click="packageCrud.cancelForm">Cancelar</button>
                    <button type="submit" form="package-form" class="btn btn-primary" :disabled="packageCrud.form.processing">Guardar</button>
                </template>
            </template>
        </DetailModal>
    </BackofficeLayout>
</template>

<style scoped>
.block { display: block; }

.settings-card {
    padding: 1.1rem;
}

.settings-check {
    min-height: 2.25rem;
    margin: 0;
}

.header-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
}

.feature-cell {
    display: flex;
    align-items: center;
    gap: 0.7rem;
}

.feature-icon {
    flex-shrink: 0;
    display: inline-grid;
    place-items: center;
    width: 2.25rem;
    height: 2.25rem;
    border-radius: var(--radius-sm);
    background: var(--color-primary-soft);
    color: color-mix(in srgb, var(--color-primary) 78%, var(--color-text));
}

.package-badge {
    margin-left: 0.35rem;
}

.package-tagline {
    margin: 0 0 1rem;
    color: var(--color-text-muted);
}

.package-list {
    display: grid;
    gap: 0.25rem;
    margin: 0;
    padding-left: 1.1rem;
}

.field :is(input:not([type="checkbox"], [type="radio"]), select, textarea) {
    width: 100%;
}

.field-label {
    display: block;
    margin-bottom: 0.3rem;
    font-size: 0.78rem;
    font-weight: 600;
    color: var(--color-text-muted);
}

.field-hint {
    color: var(--color-text-muted);
    font-size: 0.75rem;
}

.icon-picker {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(2.6rem, 1fr));
    gap: 0.35rem;
}

.icon-option {
    display: inline-grid;
    place-items: center;
    min-height: 2.6rem;
    padding: 0;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    background: var(--color-surface);
    color: var(--color-text);
    cursor: pointer;
}

.icon-option.is-active {
    border-color: var(--color-primary);
    background: var(--color-primary);
    color: var(--color-on-primary);
}

.icon-option span {
    display: inline-grid;
}

.check-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(min(100%, 14rem), 1fr));
    gap: 0 1rem;
}

.package-features {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(min(100%, 17rem), 1fr));
    gap: 0.4rem 1rem;
}

.package-feature {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.6rem;
    padding: 0.35rem 0.55rem;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
}

.package-feature.is-on {
    border-color: color-mix(in srgb, var(--color-primary) 40%, var(--color-border));
    background: var(--color-primary-soft);
}

.package-feature .check {
    margin: 0;
}

.package-quantity {
    width: 4.5rem;
}

.package-preview p {
    margin: 0.2rem 0 0;
}

/* Eliminar va a la izquierda del pie (CLAUDE.md secc. 27). */
.footer-start {
    margin-right: auto;
}
</style>
