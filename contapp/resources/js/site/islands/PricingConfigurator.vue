<script setup>
import { CheckIcon, CircleCheckIcon, InfoIcon, LockIcon, MinusIcon, PlusIcon, SendIcon, XIcon } from '@lucide/vue';
import { computed, nextTick, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { requestJson } from '../../Utils/http';
import { cycleLabel, dependentsOf, formatPrice, formatRate, normalizeSelection, packageSelection, quote } from '../../Utils/pricing';

/**
 * El configurador de /precios (CLAUDE.md secc. 42): se parte de un paquete
 * (o de cero) y se agregan o quitan funciones, compañías y usuarios, con el
 * precio al instante. El cálculo es el de Utils/pricing.js, el mismo que
 * repite el servidor al recibir la solicitud.
 *
 * - Una función que otra necesita se suma sola, y quitarla quita las que la
 *   necesitan: se avisa en las dos direcciones.
 * - Las obligatorias, y las que una obligatoria necesita, no se pueden quitar.
 * - El ciclo lo fija el paquete si se ofrece de una sola forma.
 */
const props = defineProps({
    catalog: { type: Object, required: true },
    preselect: { type: String, default: null },
    started: { type: String, required: true },
    requestUrl: { type: String, required: true },
    privacyUrl: { type: String, required: true },
    contactEmail: { type: String, default: '' },
});

const features = props.catalog.features;
const packages = props.catalog.packages;
const settings = props.catalog.settings;
const byKey = Object.fromEntries(features.map((feature) => [feature.key, feature]));

const currency = ref(settings.currency);
const cycle = ref('monthly');
const packageSlug = ref(null);
const selection = ref({});
const notice = ref('');

// Las que no se pueden quitar: las obligatorias y todo lo que ellas necesitan.
const locked = (() => {
    const result = new Set();
    let pending = features.filter((feature) => feature.required).map((feature) => feature.key);

    while (pending.length) {
        const key = pending.pop();
        if (result.has(key)) continue;
        result.add(key);
        pending = [...pending, ...(byKey[key]?.requires ?? [])];
    }

    return result;
})();

const groups = computed(() => {
    const map = new Map();

    for (const feature of features) {
        if (!map.has(feature.group)) map.set(feature.group, []);
        map.get(feature.group).push(feature);
    }

    return [...map.entries()].map(([name, items]) => ({ name, items }));
});

const names = (keys) => {
    const list = keys.map((key) => byKey[key]?.name ?? key);

    return list.length > 1 ? `${list.slice(0, -1).join(', ')} y ${list.at(-1)}` : list[0];
};

// Lo que una función necesita y se puede quitar: lo obligatorio siempre está.
const needs = (feature) => feature.requires.filter((key) => !locked.has(key));

function normalize() {
    const { quantities, added } = normalizeSelection(features, selection.value);
    selection.value = quantities;

    return added;
}

function choosePackage(slug) {
    const pkg = packages.find((candidate) => candidate.slug === slug) ?? null;

    packageSlug.value = pkg?.slug ?? null;
    selection.value = pkg ? packageSelection(props.catalog, pkg.slug) : {};
    if (pkg && pkg.billing !== 'both') cycle.value = pkg.billing;
    notice.value = '';
    normalize();
}

function start() {
    const fromUrl = props.preselect && packages.some((pkg) => pkg.slug === props.preselect) ? props.preselect : null;
    choosePackage(fromUrl ?? packages.find((pkg) => pkg.highlighted)?.slug ?? packages[0]?.slug ?? null);
}

start();

const result = computed(() => quote(props.catalog, packageSlug.value, selection.value, cycle.value, currency.value));
const currentPackage = computed(() => packages.find((pkg) => pkg.slug === packageSlug.value) ?? null);
const cycleLocked = computed(() => Boolean(currentPackage.value && currentPackage.value.billing !== 'both'));

const planName = computed(() => {
    if (!currentPackage.value) return 'Plan personalizado';

    return result.value.intact ? currentPackage.value.name : `${currentPackage.value.name} (personalizado)`;
});

// Cuánto se ahorra pagando por año, si se puede elegir.
const annualSaving = computed(() => {
    if (cycleLocked.value) return 0;

    const monthly = quote(props.catalog, packageSlug.value, selection.value, 'monthly', currency.value).total;
    const annual = quote(props.catalog, packageSlug.value, selection.value, 'annual', currency.value).total;

    return monthly > 0 ? Math.max(0, Math.round((1 - annual / (monthly * 12)) * 100)) : 0;
});

const perMonth = computed(() => (result.value.cycle === 'annual' ? Math.round(result.value.total / 12) : null));

function isOn(feature) {
    return (selection.value[feature.key] ?? 0) > 0;
}

function inPackage(feature) {
    return Boolean(currentPackage.value && feature.key in (currentPackage.value.features ?? {}));
}

function unitPrice(feature) {
    return feature.prices[currency.value][result.value.cycle];
}

function priceText(feature) {
    const price = formatPrice(unitPrice(feature), currency.value);
    const per = result.value.cycle === 'annual' ? 'año' : 'mes';

    if (feature.kind !== 'quantity') return `${price} por ${per}`;

    return feature.included > 0
        ? `${feature.included} ${feature.included === 1 ? feature.unit_singular : feature.unit_plural} sin costo; ${price} por ${feature.unit_singular} adicional, por ${per}`
        : `${price} por ${feature.unit_singular}, por ${per}`;
}

function turnOff(feature) {
    const dependents = dependentsOf(features, feature.key).filter((key) => isOn(byKey[key]));
    const next = { ...selection.value, [feature.key]: 0 };
    dependents.forEach((key) => { next[key] = 0; });
    selection.value = next;
    normalize();

    notice.value = dependents.length
        ? `Al quitar ${feature.name} también se quitó ${names(dependents)}, que lo necesita${dependents.length > 1 ? 'n' : ''}.`
        : '';
}

function toggle(feature) {
    if (locked.has(feature.key)) return;

    if (isOn(feature)) {
        turnOff(feature);
        return;
    }

    selection.value = { ...selection.value, [feature.key]: feature.kind === 'quantity' ? Math.max(1, feature.included) : 1 };
    const added = normalize().filter((key) => key !== feature.key);
    notice.value = added.length ? `Se sumó ${names(added)}, que ${feature.name} necesita.` : '';
}

function minimumOf(feature) {
    return locked.has(feature.key) ? Math.max(1, feature.included) : 0;
}

function setQuantity(feature, value) {
    const max = feature.max ?? 9999;
    const quantity = Math.min(max, Math.max(minimumOf(feature), Number.parseInt(value, 10) || 0));

    if (quantity === 0) {
        turnOff(feature);
        return;
    }

    selection.value = { ...selection.value, [feature.key]: quantity };
    const added = normalize().filter((key) => key !== feature.key);
    notice.value = added.length ? `Se sumó ${names(added)}, que ${feature.name} necesita.` : '';
}

defineExpose({ choosePackage });

// ── La solicitud ─────────────────────────────────────────────────────────

const formOpen = ref(false);
const sending = ref(false);
const sent = ref(null);
const formError = ref('');
const errors = ref({});
const dialog = ref(null);
const firstField = ref(null);
const form = reactive({ contact_name: '', company_name: '', identification: '', email: '', phone: '', message: '', consent: false, website: '' });

let returnFocusTo = null;

function openForm() {
    formError.value = '';
    errors.value = {};
    sent.value = null;
    returnFocusTo = document.activeElement;
    formOpen.value = true;
    nextTick(() => firstField.value?.focus());
}

function closeForm() {
    if (sending.value) return;
    formOpen.value = false;
    returnFocusTo?.focus?.();
}

function onKeydown(event) {
    if (!formOpen.value) return;

    if (event.key === 'Escape') {
        closeForm();
        return;
    }

    if (event.key !== 'Tab' || !dialog.value) return;

    const focusables = [...dialog.value.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]):not([tabindex="-1"]), textarea')];
    const first = focusables[0];
    const last = focusables.at(-1);

    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
}

watch(formOpen, (open) => {
    if (open) window.addEventListener('keydown', onKeydown);
    else window.removeEventListener('keydown', onKeydown);

    document.body.classList.toggle('is-locked', open);
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
    document.body.classList.remove('is-locked');
});

async function submit() {
    if (sending.value) return;

    sending.value = true;
    formError.value = '';
    errors.value = {};

    const response = await requestJson(props.requestUrl, {
        method: 'POST',
        body: {
            ...form,
            consent: form.consent ? 1 : 0,
            started: props.started,
            package: packageSlug.value,
            cycle: result.value.cycle,
            currency: currency.value,
            selection: result.value.quantities,
        },
    });

    sending.value = false;

    if (response.ok) {
        sent.value = response.data;
        return;
    }

    if (response.status === 422) {
        errors.value = response.errors;
        formError.value = response.errors.form ?? response.errors.selection ?? 'Revisá los datos marcados.';
        return;
    }

    // El límite es por hora (AppServiceProvider): «esperá un momento» no sirve.
    if (response.status === 429) {
        const write = props.contactEmail ? ` o escribinos a ${props.contactEmail}` : '';
        formError.value = `Ya recibimos varias solicitudes desde tu conexión. Probá de nuevo más tarde${write}.`;
        return;
    }

    formError.value = response.message;
}
</script>

<template>
    <div class="configurator">
        <!-- El paquete de partida -->
        <div class="configurator-packages" role="radiogroup" aria-label="Paquete de partida">
            <button
                v-for="pkg in packages"
                :key="pkg.slug"
                type="button"
                role="radio"
                class="configurator-package"
                :class="{ 'is-active': packageSlug === pkg.slug }"
                :aria-checked="packageSlug === pkg.slug"
                @click="choosePackage(pkg.slug)"
            >
                <span>{{ pkg.name }}</span>
                <small v-if="pkg.badge">{{ pkg.badge }}</small>
            </button>
            <button
                type="button"
                role="radio"
                class="configurator-package"
                :class="{ 'is-active': !packageSlug }"
                :aria-checked="!packageSlug"
                @click="choosePackage(null)"
            >
                <span>Desde cero</span>
            </button>
        </div>

        <div class="configurator-layout">
            <div class="configurator-features">
                <p v-if="notice" class="configurator-notice" role="status"><InfoIcon /> {{ notice }}</p>

                <section v-for="group in groups" :key="group.name" class="feature-group" :aria-label="group.name">
                    <h3 class="feature-group-title">{{ group.name }}</h3>

                    <div
                        v-for="feature in group.items"
                        :key="feature.key"
                        class="feature-row"
                        :class="{ 'is-on': isOn(feature) }"
                    >
                        <span class="feature-icon" aria-hidden="true" v-html="feature.icon_svg" />

                        <div class="feature-text">
                            <span class="feature-name">
                                {{ feature.name }}
                                <span v-if="inPackage(feature)" class="feature-tag">En el paquete</span>
                            </span>
                            <span v-if="feature.description" class="feature-description">{{ feature.description }}</span>
                            <span class="feature-price">{{ priceText(feature) }}</span>
                            <span v-if="needs(feature).length" class="feature-requires">Necesita {{ names(needs(feature)) }}.</span>
                        </div>

                        <div class="feature-control">
                            <template v-if="feature.kind === 'quantity'">
                                <div class="stepper" role="group" :aria-label="`Cantidad de ${feature.unit_plural}`">
                                    <button
                                        type="button"
                                        :aria-label="`Una ${feature.unit_singular} menos`"
                                        :disabled="(selection[feature.key] ?? 0) <= minimumOf(feature)"
                                        @click="setQuantity(feature, (selection[feature.key] ?? 0) - 1)"
                                    ><MinusIcon /></button>
                                    <input
                                        type="number"
                                        inputmode="numeric"
                                        :min="minimumOf(feature)"
                                        :max="feature.max ?? 9999"
                                        :value="selection[feature.key] ?? 0"
                                        :aria-label="feature.unit_plural"
                                        @change="setQuantity(feature, $event.target.value)"
                                    >
                                    <button
                                        type="button"
                                        :aria-label="`Una ${feature.unit_singular} más`"
                                        :disabled="feature.max !== null && (selection[feature.key] ?? 0) >= feature.max"
                                        @click="setQuantity(feature, (selection[feature.key] ?? 0) + 1)"
                                    ><PlusIcon /></button>
                                </div>
                            </template>

                            <span v-else-if="locked.has(feature.key)" class="feature-locked"><LockIcon /> Incluida</span>

                            <button
                                v-else
                                type="button"
                                role="switch"
                                class="switch"
                                :aria-checked="isOn(feature)"
                                :aria-label="`${isOn(feature) ? 'Quitar' : 'Agregar'} ${feature.name}`"
                                @click="toggle(feature)"
                            ><span /></button>
                        </div>
                    </div>
                </section>
            </div>

            <!-- El resumen: al lado en escritorio, abajo en un teléfono. -->
            <aside id="resumen" class="configurator-summary" aria-label="Tu plan">
                <div class="summary-card">
                    <p class="summary-eyebrow">Tu plan</p>
                    <h3 class="summary-plan">{{ planName }}</h3>

                    <div class="segmented" role="group" aria-label="Forma de pago">
                        <button type="button" :class="{ 'is-active': result.cycle === 'monthly' }" :aria-pressed="result.cycle === 'monthly'" :disabled="cycleLocked" @click="cycle = 'monthly'">Mensual</button>
                        <button type="button" :class="{ 'is-active': result.cycle === 'annual' }" :aria-pressed="result.cycle === 'annual'" :disabled="cycleLocked" @click="cycle = 'annual'">
                            Anual<small v-if="annualSaving > 0"> −{{ annualSaving }} %</small>
                        </button>
                    </div>
                    <p v-if="cycleLocked" class="summary-note">Este paquete se paga {{ result.cycle === 'annual' ? 'por año' : 'por mes' }}.</p>

                    <div class="segmented" role="group" aria-label="Moneda">
                        <button type="button" :class="{ 'is-active': currency === 'CRC' }" :aria-pressed="currency === 'CRC'" @click="currency = 'CRC'">Colones (₡)</button>
                        <button type="button" :class="{ 'is-active': currency === 'USD' }" :aria-pressed="currency === 'USD'" @click="currency = 'USD'">Dólares (US$)</button>
                    </div>

                    <ul class="summary-lines">
                        <li v-for="line in result.lines" :key="line.key">
                            <span>
                                {{ line.name }}
                                <small v-if="line.kind === 'quantity'">{{ line.quantity }} {{ line.unit_label }}<template v-if="line.quantity !== line.billable"> ({{ line.billable }} con costo)</template></small>
                            </span>
                            <b>{{ line.amount ? formatPrice(line.amount, result.currency) : 'Incluido' }}</b>
                        </li>
                    </ul>

                    <dl class="summary-totals">
                        <div v-if="result.discount > 0" class="is-discount">
                            <dt>Descuento del paquete ({{ formatRate(result.package.discount_bp / 100) }} %)</dt>
                            <dd>{{ formatPrice(-result.discount, result.currency) }}</dd>
                        </div>
                        <div>
                            <dt>{{ result.prices_include_tax ? 'Subtotal (con IVA)' : 'Subtotal' }}</dt>
                            <dd>{{ formatPrice(result.net, result.currency) }}</dd>
                        </div>
                        <div>
                            <dt>{{ result.prices_include_tax ? `Incluye IVA (${formatRate(result.tax_rate)} %)` : `IVA (${formatRate(result.tax_rate)} %)` }}</dt>
                            <dd>{{ formatPrice(result.tax, result.currency) }}</dd>
                        </div>
                        <div class="is-total">
                            <dt>Total {{ cycleLabel(result.cycle) }}</dt>
                            <dd>{{ formatPrice(result.total, result.currency) }}</dd>
                        </div>
                    </dl>
                    <p v-if="perMonth" class="summary-note">Equivale a {{ formatPrice(perMonth, result.currency) }} por mes.</p>
                    <p v-if="currentPackage && !result.intact && currentPackage.discount_bp > 0" class="summary-note is-warning">
                        Quitaste funciones del paquete: ya no tiene su descuento.
                    </p>

                    <button type="button" class="btn btn-primary btn-lg summary-cta" :disabled="!result.lines.length" @click="openForm">
                        <SendIcon /> Solicitar licencia
                    </button>
                    <p class="summary-note">Es una cotización: te confirmamos todo al responderte.</p>
                </div>
            </aside>
        </div>

        <!-- En un teléfono, el total siempre a la vista. -->
        <div class="configurator-bar" :class="{ 'is-hidden': formOpen }">
            <a href="#resumen" class="configurator-bar-total">
                <small>Total {{ cycleLabel(result.cycle) }}</small>
                <b>{{ formatPrice(result.total, result.currency) }}</b>
            </a>
            <button type="button" class="btn btn-primary" :disabled="!result.lines.length" @click="openForm">Solicitar licencia</button>
        </div>

        <!-- La solicitud -->
        <div v-if="formOpen" class="request-backdrop" @click.self="closeForm">
            <div ref="dialog" class="request-dialog" role="dialog" aria-modal="true" aria-labelledby="request-title">
                <header class="request-head">
                    <h3 id="request-title">{{ sent ? 'Solicitud enviada' : 'Solicitar licencia' }}</h3>
                    <button type="button" class="request-close" aria-label="Cerrar" :disabled="sending" @click="closeForm"><XIcon :size="18" /></button>
                </header>

                <div v-if="sent" class="request-done" role="status">
                    <CircleCheckIcon :size="40" />
                    <p v-if="sent.reference"><strong>¡Recibimos tu solicitud {{ sent.reference }}!</strong></p>
                    <p v-else><strong>¡Recibimos tu solicitud!</strong></p>
                    <p v-if="sent.email">Te vamos a escribir a <strong>{{ sent.email }}</strong> para coordinar el pago y la activación.</p>
                    <p v-if="sent.total" class="muted">{{ planName }} · {{ sent.total }} {{ sent.cycle }}</p>
                    <button type="button" class="btn btn-primary" @click="closeForm"><CheckIcon /> Listo</button>
                </div>

                <form v-else novalidate @submit.prevent="submit">
                    <p class="request-plan">
                        <b>{{ planName }}</b> · {{ formatPrice(result.total, result.currency) }} {{ cycleLabel(result.cycle) }}
                    </p>

                    <p v-if="formError" class="flash flash-error" role="alert">{{ formError }}</p>

                    <div class="form-grid">
                        <div class="field">
                            <label for="request-name">Nombre y apellidos</label>
                            <input id="request-name" ref="firstField" v-model="form.contact_name" type="text" maxlength="120" autocomplete="name" required>
                            <span v-if="errors.contact_name" class="error">{{ errors.contact_name }}</span>
                        </div>
                        <div class="field">
                            <label for="request-company">Compañía</label>
                            <input id="request-company" v-model="form.company_name" type="text" maxlength="160" autocomplete="organization" required>
                            <span v-if="errors.company_name" class="error">{{ errors.company_name }}</span>
                        </div>
                        <div class="field">
                            <label for="request-id">Cédula (opcional)</label>
                            <input id="request-id" v-model="form.identification" type="text" maxlength="30" inputmode="numeric">
                            <span v-if="errors.identification" class="error">{{ errors.identification }}</span>
                        </div>
                        <div class="field">
                            <label for="request-email">Correo</label>
                            <input id="request-email" v-model="form.email" type="email" maxlength="190" autocomplete="email" required>
                            <span v-if="errors.email" class="error">{{ errors.email }}</span>
                        </div>
                        <div class="field">
                            <label for="request-phone">Teléfono o WhatsApp (opcional)</label>
                            <input id="request-phone" v-model="form.phone" type="tel" maxlength="40" autocomplete="tel">
                            <span v-if="errors.phone" class="error">{{ errors.phone }}</span>
                        </div>
                        <div class="field span-full">
                            <label for="request-message">Mensaje (opcional)</label>
                            <textarea id="request-message" v-model="form.message" rows="3" maxlength="2000" placeholder="Contanos algo de tu empresa o lo que necesitás." />
                            <span v-if="errors.message" class="error">{{ errors.message }}</span>
                        </div>
                    </div>

                    <!-- La trampa para robots: una persona no la ve ni la llena. -->
                    <div class="request-trap" aria-hidden="true">
                        <label for="request-website">No llenar</label>
                        <input id="request-website" v-model="form.website" type="text" tabindex="-1" autocomplete="off">
                    </div>

                    <label class="check request-consent">
                        <input v-model="form.consent" type="checkbox" required>
                        <span>
                            Acepto que Pivox use estos datos solo para responder mi solicitud
                            (<a :href="privacyUrl" target="_blank" rel="noopener">privacidad</a>).
                        </span>
                    </label>
                    <span v-if="errors.consent" class="error-text">{{ errors.consent }}</span>

                    <div class="request-actions">
                        <button type="button" class="btn btn-ghost" :disabled="sending" @click="closeForm">Cancelar</button>
                        <button type="submit" class="btn btn-primary" :disabled="sending" :data-busy="sending ? '' : null" :aria-busy="sending">
                            <SendIcon /> Enviar solicitud
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
