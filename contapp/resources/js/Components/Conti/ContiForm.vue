<script setup>
import { computed, onBeforeUnmount, ref, useId, watch } from 'vue';
import { CircleCheckIcon, ClipboardListIcon, PlusIcon, XIcon } from '@lucide/vue';
import ContiFormField from './ContiFormField.vue';
import { openContiAction, submitContiForm } from '../../Utils/contiChat';
import { requestJson } from '../../Utils/http';

/**
 * Un formulario de Conti en el chat (la herramienta «formulario», CLAUDE.md
 * secc. 32): para registrar o editar algo sin tener que escribirle los datos
 * en un mensaje. Viene con lo que Conti ya sabía precargado.
 *
 * Al enviarlo se valida y se prepara (ContiFormController) y se abre la
 * ventana para confirmar: nada se guarda sin ese último paso. Si algo no
 * sirve, el error queda junto a su campo —o a su línea—.
 */
const props = defineProps({
    message: { type: Object, required: true },
});

const uid = useId();
const errors = ref({});
const generalError = ref('');
const busy = ref(false);

const form = computed(() => props.message.formulario);
const values = computed(() => props.message.valores);
const sent = computed(() => props.message.enviado);

const mainFields = computed(() => form.value.campos.filter((f) => !f.avanzado));
const advancedFields = computed(() => form.value.campos.filter((f) => f.avanzado));

const id = (name, row = null) => `${uid}-${name}${row === null ? '' : `-${row}`}`;
const errorFor = (path) => {
    const value = errors.value[path];
    return Array.isArray(value) ? value[0] : (value ?? null);
};

// Los errores que no son de ningún campo a la vista (uno de una línea
// entera, o uno general): arriba del botón.
const looseErrors = computed(() => {
    const shown = new Set();

    for (const field of form.value.campos) {
        shown.add(field.campo);
        if (field.tipo === 'lineas') {
            (values.value[field.campo] ?? []).forEach((_, i) => {
                for (const column of field.columnas) shown.add(`${field.campo}.${i}.${column.campo}`);
            });
        }
    }

    return Object.entries(errors.value)
        .filter(([path]) => !shown.has(path))
        .map(([, messages]) => (Array.isArray(messages) ? messages[0] : messages));
});

function today() {
    const now = new Date();
    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
}

// Una línea nueva, con los valores por defecto de cada columna (el almacén
// predeterminado, hoy…).
function emptyRow(field) {
    return Object.fromEntries(field.columnas.map((c) => {
        if (c.tipo === 'si_no') return [c.campo, Boolean(c.defecto)];
        if (c.defecto === 'hoy') return [c.campo, today()];
        return [c.campo, c.defecto ?? ''];
    }));
}

// ── Lo que sugirió CONTAPP ──────────────────────────────────────────────
// Se marca mientras el valor sea el sugerido: si la persona lo cambia, deja
// de ser una sugerencia.
const sameValue = (a, b) => String(a ?? '') === String(b ?? '');

function suggestionFor(name) {
    const suggestion = props.message.sugeridos?.[name];
    return suggestion && sameValue(values.value[name], suggestion.valor) ? suggestion.motivo : null;
}

function originFor(name) {
    return props.message.sugeridos?.[name]?.origen ?? null;
}

const hasSuggestions = computed(() => form.value.campos.some((f) => suggestionFor(f.campo)));

// Al cambiar un campo clave (la clase de una cuenta, el tipo de un socio),
// se vuelve a pedir: lo vacío, o lo que todavía es la sugerencia anterior,
// se completa con lo nuevo.
let suggestTimer = null;
let suggestTicket = 0;

async function refreshSuggestions() {
    const mine = ++suggestTicket;
    const result = await requestJson(window.route('conti.forms.suggest'), {
        method: 'POST',
        body: { accion: form.value.accion, valores: values.value },
    });

    if (mine !== suggestTicket || !result.ok || sent.value) return;

    const previous = props.message.sugeridos ?? {};
    const next = { ...previous };

    for (const [name, suggestion] of Object.entries(result.data.sugeridos ?? {})) {
        const current = values.value[name];
        const untouched = current === '' || current === null || current === undefined || (previous[name] && sameValue(current, previous[name].valor));

        if (untouched) {
            values.value[name] = suggestion.valor;
            next[name] = suggestion;
        }
    }

    props.message.sugeridos = next;
}

for (const field of form.value.campos.filter((f) => f.recalcula)) {
    watch(() => values.value[field.campo], () => {
        clearTimeout(suggestTimer);
        suggestTimer = setTimeout(refreshSuggestions, 300);
    });
}

onBeforeUnmount(() => clearTimeout(suggestTimer));

function addRow(field) {
    values.value[field.campo].push(emptyRow(field));
}

function removeRow(field, index) {
    values.value[field.campo].splice(index, 1);
}

// Un asiento: lo que suman débitos y créditos, para ver si cuadra antes de enviar.
const amountFormat = new Intl.NumberFormat('es-CR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

function totals(field) {
    const columns = field.columnas.map((c) => c.campo);
    if (!columns.includes('debito') || !columns.includes('credito')) return null;

    const sum = (key) => (values.value[field.campo] ?? []).reduce((total, row) => total + (Number(row[key]) || 0), 0);
    const debit = sum('debito');
    const credit = sum('credito');

    return { debit: amountFormat.format(debit), credit: amountFormat.format(credit), balanced: Math.abs(debit - credit) < 0.005, difference: amountFormat.format(Math.abs(debit - credit)) };
}

async function submit() {
    if (busy.value || sent.value) return;

    busy.value = true;
    errors.value = {};
    generalError.value = '';

    const result = await submitContiForm(props.message);

    busy.value = false;
    errors.value = result.errors ?? {};
    generalError.value = result.message ?? (Object.keys(errors.value).length ? 'Revisá lo marcado y volvé a enviarlo.' : '');
}
</script>

<template>
    <section class="conti-form" :class="{ 'is-sent': sent }" :aria-label="form.titulo">
        <header class="cf-head">
            <ClipboardListIcon :size="16" aria-hidden="true" />
            <strong>{{ form.titulo }}</strong>
        </header>

        <!-- Enviado: queda lo preparado, para volver a abrir la ventana. -->
        <div v-if="sent" class="cf-sent">
            <CircleCheckIcon :size="15" aria-hidden="true" />
            <span>Preparado: {{ sent.titulo }}</span>
            <button type="button" class="btn btn-ghost btn-sm" @click="openContiAction(sent.id)">Revisar y confirmar</button>
        </div>

        <form v-else novalidate @submit.prevent="submit">
            <ul v-if="message.nota?.length" class="cf-note">
                <li v-for="line in message.nota" :key="line">{{ line }}</li>
            </ul>
            <p class="cf-intro">
                Revisá y completá. Al enviarlo te muestro el resumen para confirmar: nada se guarda antes.
                <template v-if="hasSuggestions"> Lo marcado como «Sugerido» lo completé según cómo se viene trabajando en la compañía.</template>
            </p>

            <div class="cf-grid">
                <template v-for="field in mainFields" :key="field.campo">
                    <fieldset v-if="field.tipo === 'lineas'" class="cf-lines">
                        <legend>{{ field.etiqueta }}<span v-if="field.requerido" class="cf-required">*</span></legend>
                        <p v-if="errorFor(field.campo)" class="cf-error">{{ errorFor(field.campo) }}</p>

                        <div v-for="(row, i) in values[field.campo]" :key="i" class="cf-line">
                            <div class="cf-line-head">
                                <span>Línea {{ i + 1 }}</span>
                                <button
                                    v-if="values[field.campo].length > (field.minimo ?? 1)"
                                    type="button"
                                    class="cf-remove"
                                    :aria-label="`Quitar la línea ${i + 1}`"
                                    @click="removeRow(field, i)"
                                ><XIcon :size="14" /></button>
                            </div>
                            <p v-if="errorFor(`${field.campo}.${i}`)" class="cf-error">{{ errorFor(`${field.campo}.${i}`) }}</p>
                            <div class="cf-grid">
                                <ContiFormField
                                    v-for="column in field.columnas.filter((c) => !c.avanzado)"
                                    :key="column.campo"
                                    v-model="row[column.campo]"
                                    :field="column"
                                    :input-id="id(`${field.campo}-${column.campo}`, i)"
                                    :error="errorFor(`${field.campo}.${i}.${column.campo}`)"
                                />
                            </div>
                            <details v-if="field.columnas.some((c) => c.avanzado)" class="cf-more">
                                <summary>Más datos de la línea</summary>
                                <div class="cf-grid">
                                    <ContiFormField
                                        v-for="column in field.columnas.filter((c) => c.avanzado)"
                                        :key="column.campo"
                                        v-model="row[column.campo]"
                                        :field="column"
                                        :input-id="id(`${field.campo}-${column.campo}`, i)"
                                        :error="errorFor(`${field.campo}.${i}.${column.campo}`)"
                                    />
                                </div>
                            </details>
                        </div>

                        <div class="cf-lines-foot">
                            <button type="button" class="btn btn-ghost btn-sm" @click="addRow(field)"><PlusIcon /> Agregar línea</button>
                            <span v-if="totals(field)" class="cf-totals" :class="{ 'is-off': !totals(field).balanced }">
                                Débitos {{ totals(field).debit }} · Créditos {{ totals(field).credit }}
                                <template v-if="!totals(field).balanced"> · No cuadra por {{ totals(field).difference }}</template>
                            </span>
                        </div>
                    </fieldset>

                    <ContiFormField
                        v-else
                        v-model="values[field.campo]"
                        :field="field"
                        :input-id="id(field.campo)"
                        :error="errorFor(field.campo)"
                        :suggestion="suggestionFor(field.campo)"
                        :suggestion-origin="originFor(field.campo)"
                    />
                </template>
            </div>

            <details v-if="advancedFields.length" class="cf-more">
                <summary>Más datos</summary>
                <div class="cf-grid">
                    <ContiFormField
                        v-for="field in advancedFields"
                        :key="field.campo"
                        v-model="values[field.campo]"
                        :field="field"
                        :input-id="id(field.campo)"
                        :error="errorFor(field.campo)"
                        :suggestion="suggestionFor(field.campo)"
                        :suggestion-origin="originFor(field.campo)"
                    />
                </div>
            </details>

            <div v-if="generalError || looseErrors.length" class="cf-problems" role="alert">
                <p v-if="generalError">{{ generalError }}</p>
                <ul v-if="looseErrors.length">
                    <li v-for="message in looseErrors" :key="message">{{ message }}</li>
                </ul>
            </div>

            <div class="cf-actions">
                <button
                    type="submit"
                    class="btn btn-primary btn-sm"
                    :disabled="busy"
                    :data-busy="busy ? '' : null"
                    :aria-busy="busy"
                >Revisar y confirmar</button>
            </div>
        </form>
    </section>
</template>

<style scoped>
.conti-form {
    align-self: stretch;
    display: flex;
    flex-direction: column;
    gap: 0.6rem;
    padding: 0.75rem 0.8rem;
    border: 1px solid color-mix(in srgb, var(--color-primary) 30%, var(--color-border));
    border-radius: var(--radius-md);
    background: var(--color-surface);
    font-size: 0.84rem;
}

.conti-form.is-sent {
    padding: 0.55rem 0.7rem;
    border-color: var(--color-border);
    background: var(--color-surface-alt);
}

.cf-head {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    color: color-mix(in srgb, var(--color-primary) 70%, var(--color-text));
}

.cf-head strong {
    color: var(--color-text);
}

/* Lo que avisa Conti al leer un documento escaneado. */
.cf-note {
    margin: 0 0 0.6rem;
    padding: 0.5rem 0.6rem 0.5rem 1.5rem;
    border-radius: var(--radius-sm);
    background: var(--color-primary-soft);
    font-size: 0.76rem;
    line-height: 1.45;
}

.cf-note li + li {
    margin-top: 0.2rem;
}

.cf-intro {
    margin: 0 0 0.6rem;
    font-size: 0.76rem;
    color: var(--color-text-muted);
}

.cf-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(min(100%, 10.5rem), 1fr));
    gap: 0.6rem 0.7rem;
    align-items: start;
}

.cf-lines {
    grid-column: 1 / -1;
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    margin: 0;
    padding: 0;
    border: none;
    min-width: 0;
}

.cf-lines legend {
    margin-bottom: 0.2rem;
    padding: 0;
    font-size: 0.74rem;
    font-weight: 600;
    color: var(--color-text-muted);
}

.cf-line {
    display: flex;
    flex-direction: column;
    gap: 0.45rem;
    padding: 0.55rem 0.6rem;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    background: var(--color-bg);
}

.cf-line-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: 0.72rem;
    font-weight: 600;
    color: var(--color-text-muted);
}

.cf-remove {
    display: inline-grid;
    place-items: center;
    width: 1.5rem;
    height: 1.5rem;
    padding: 0;
    border: none;
    border-radius: var(--radius-sm);
    background: transparent;
    color: var(--color-text-muted);
    cursor: pointer;
}

.cf-remove:hover {
    background: var(--color-surface-alt);
    color: var(--color-danger);
}

.cf-lines-foot {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 0.4rem;
}

.cf-totals {
    font-size: 0.74rem;
    font-variant-numeric: tabular-nums;
    color: var(--color-text-muted);
}

.cf-totals.is-off {
    color: var(--color-warning);
    font-weight: 600;
}

.cf-more {
    margin-top: 0.1rem;
}

.cf-more summary {
    font-size: 0.76rem;
    font-weight: 600;
    color: var(--color-text-muted);
    cursor: pointer;
}

.cf-more[open] summary {
    margin-bottom: 0.5rem;
}

.cf-required {
    margin-left: 0.15rem;
    color: var(--color-danger);
}

.cf-error {
    margin: 0;
    font-size: 0.72rem;
    color: var(--color-danger);
}

.cf-problems {
    margin-top: 0.6rem;
    padding: 0.45rem 0.6rem;
    border-radius: var(--radius-sm);
    background: var(--color-danger-soft);
    color: var(--color-danger);
    font-size: 0.76rem;
}

.cf-problems p,
.cf-problems ul {
    margin: 0;
}

.cf-problems ul {
    padding-left: 1rem;
}

.cf-actions {
    display: flex;
    justify-content: flex-end;
    margin-top: 0.7rem;
}

.cf-sent {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.4rem 0.5rem;
    font-size: 0.8rem;
}

.cf-sent svg {
    color: var(--color-success);
}

.cf-sent span {
    flex: 1;
    min-width: 0;
}
</style>
