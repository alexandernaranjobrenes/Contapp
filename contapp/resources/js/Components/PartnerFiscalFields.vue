<script setup>
/**
 * Los datos del socio que pide la factura electrónica (CLAUDE.md secc. 38):
 * el tipo de identificación de su cédula y, opcional, su ubicación con los
 * códigos de Hacienda. Va dentro del .form-grid del formulario del socio, en
 * Crear y en Editar.
 *
 * «Consultar en Hacienda» trae lo que Hacienda tiene inscrito de la cédula
 * (HaciendaLookupController::taxpayer): el tipo de identificación, y el nombre
 * y la actividad principal si todavía están vacíos. Nunca pisa lo escrito.
 *
 * `form` es el useForm de la pantalla: estos campos le escriben directo.
 */
import { ref } from 'vue';
import { SearchIcon } from '@lucide/vue';
import TerritorySelects from './TerritorySelects.vue';

const props = defineProps({
    form: { type: Object, required: true },
    identificationTypes: { type: Object, default: () => ({}) },
    territory: { type: Object, default: () => ({}) },
});

const lookup = ref(null); // { ok, text }
const looking = ref(false);

async function lookUp() {
    const identification = (props.form.tax_id ?? '').replace(/\D/g, '');
    if (! identification) return;

    looking.value = true;
    lookup.value = null;

    try {
        const res = await fetch(route('hacienda.taxpayer', { identification }), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        if (res.redirected || ! res.ok) throw new Error(`HTTP ${res.status}`);
        const { available, taxpayer } = await res.json();

        if (! available) {
            lookup.value = { ok: false, text: 'Hacienda no contestó. Probá de nuevo en un rato.' };
            return;
        }

        if (! taxpayer.found) {
            lookup.value = { ok: false, text: `Hacienda no tiene inscrita la cédula ${identification}.` };
            return;
        }

        if (props.identificationTypes[taxpayer.identification_type]) {
            props.form.identification_type = taxpayer.identification_type;
        }

        if (! (props.form.name ?? '').trim() && taxpayer.name) props.form.name = taxpayer.name;

        const active = taxpayer.activities.filter((a) => a.active);
        const primary = active.find((a) => a.primary) ?? active[0];
        if (! (props.form.economic_activity_code ?? '').trim() && primary) props.form.economic_activity_code = primary.code;

        lookup.value = {
            ok: true,
            text: [
                taxpayer.name,
                taxpayer.status,
                taxpayer.regime,
                active.length ? `Actividades: ${active.map((a) => `${a.code} ${a.description}`).join('; ')}` : 'Sin actividades activas',
            ].filter(Boolean).join(' · '),
        };
    } catch {
        lookup.value = { ok: false, text: 'No se pudo consultar a Hacienda.' };
    } finally {
        looking.value = false;
    }
}
</script>

<template>
    <div class="field">
        <label for="identification_type">Tipo de identificación</label>
        <select id="identification_type" v-model="form.identification_type">
            <option value="">— Sin indicar —</option>
            <option v-for="(label, code) in identificationTypes" :key="code" :value="code">{{ label }}</option>
        </select>
        <span class="hint">Obligatorio para recibir una factura electrónica (a consumidor final va un tiquete).</span>
        <span v-if="form.errors.identification_type" class="error">{{ form.errors.identification_type }}</span>
    </div>

    <div class="field span-full hacienda-lookup">
        <button type="button" class="btn btn-ghost" :disabled="looking || !form.tax_id" @click="lookUp">
            <SearchIcon /> {{ looking ? 'Consultando…' : 'Consultar la cédula en Hacienda' }}
        </button>
        <span v-if="lookup" class="hint" :class="{ warn: !lookup.ok }">{{ lookup.text }}</span>
    </div>

    <div class="field span-full fiscal-heading">
        <span class="fiscal-title">Ubicación para la factura electrónica (opcional)</span>
        <span class="hint">Provincia, cantón y distrito de la tabla de Hacienda. Si no está completa, la factura va sin ubicación del receptor.</span>
    </div>

    <TerritorySelects :form="form" :territory="territory" />

    <div class="field span-full">
        <label for="address_details">Otras señas</label>
        <input id="address_details" v-model="form.address_details" type="text" maxlength="250">
        <span v-if="form.errors.address_details" class="error">{{ form.errors.address_details }}</span>
    </div>
</template>

<style scoped>
.field .hint { display: block; font-size: 0.74rem; margin: 0; }
.fiscal-heading { gap: 0.15rem; }
.fiscal-title { font-weight: 600; font-size: 0.86rem; }
.hacienda-lookup { gap: 0.3rem; align-items: flex-start; }
.hacienda-lookup .warn { color: var(--color-danger); }
</style>
