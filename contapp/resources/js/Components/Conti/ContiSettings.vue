<script setup>
import { onMounted, ref } from 'vue';
import { ArrowLeftIcon } from '@lucide/vue';
import { conti, showContiChat } from '../../Utils/contiChat';
import { requestJson } from '../../Utils/http';

/**
 * «Modelo y consumo», dentro del panel de Conti (ContiPanel.vue, CLAUDE.md
 * secc. 32): con qué modelo de OpenAI le responde Conti a la persona, cuánto
 * gastó ella hoy, esta semana y este mes, y los límites que le aplican
 * (ContiSettingsController). Se vuelve a pedir cada vez que se abre: el
 * consumo cambia con cada mensaje.
 */
const data = ref(null);
const loading = ref(false);
const loadError = ref('');
const saving = ref(null); // el id del modelo que se está guardando
const saveError = ref('');
const saved = ref('');

const PERIODS = [
    { key: 'hoy', label: 'Hoy' },
    { key: 'semana', label: 'Esta semana' },
    { key: 'mes', label: 'Este mes' },
];

const COST_BADGES = { bajo: 'badge-success', medio: 'badge-neutral', alto: 'badge-warning' };

const tokenFormat = new Intl.NumberFormat('es-CR');
const creditFormat = new Intl.NumberFormat('es-CR', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
const usdFormat = new Intl.NumberFormat('es-CR', { style: 'currency', currency: 'USD', minimumFractionDigits: 2 });

const tokens = (value) => tokenFormat.format(Number(value ?? 0));
const credits = (value) => creditFormat.format(Number(value ?? 0));

function percent(limit) {
    const max = Number(limit.limite);
    return max > 0 ? Math.min(100, (Number(limit.usado) / max) * 100) : 100;
}

function level(limit) {
    const value = percent(limit);
    if (value >= 100) return 'is-full';
    return value >= 80 ? 'is-high' : '';
}

async function load() {
    loading.value = true;
    loadError.value = '';

    const result = await requestJson(window.route('conti.settings.show'));

    loading.value = false;
    if (result.ok) {
        data.value = result.data;
    } else {
        loadError.value = result.status === 403 && result.data?.message ? result.data.message : result.message;
    }
}

async function choose(id) {
    if (saving.value || !data.value || id === data.value.modelo) return;

    const previous = data.value.modelo;
    data.value.modelo = id;
    saving.value = id;
    saveError.value = '';
    saved.value = '';

    const result = await requestJson(window.route('conti.settings.model'), { method: 'PUT', body: { modelo: id } });

    saving.value = null;
    if (!result.ok) {
        data.value.modelo = previous;
        saveError.value = result.errors?.modelo ?? result.message;
        return;
    }

    conti.modelName = result.data.nombre;
    saved.value = `Listo: desde ahora te responde ${result.data.nombre}.`;
}

onMounted(load);
</script>

<template>
    <div class="conti-settings">
        <button type="button" class="btn btn-ghost btn-sm settings-back" @click="showContiChat">
            <ArrowLeftIcon /> Volver a la conversación
        </button>

        <p v-if="loading && !data" class="muted settings-state">Cargando…</p>

        <div v-else-if="loadError" class="flash flash-error settings-state" role="alert">
            <span>{{ loadError }}</span>
            <button type="button" class="btn btn-ghost btn-sm" @click="load">Reintentar</button>
        </div>

        <template v-else-if="data">
            <section class="settings-section" aria-labelledby="conti-model-title">
                <h3 id="conti-model-title">Modelo</h3>
                <p class="settings-intro">
                    El modelo de OpenAI con el que Conti te responde. Los más capaces gastan más créditos por mensaje. Es tuyo: no cambia el de las demás personas.
                </p>
                <p v-if="data.modelos_limitados" class="settings-note">Estos son los modelos que te habilitó el Superusuario de la licencia.</p>

                <fieldset class="model-list" :disabled="saving !== null">
                    <legend class="sr-only">Modelo de OpenAI</legend>
                    <label
                        v-for="model in data.modelos"
                        :key="model.id"
                        class="model-option"
                        :class="{ 'is-selected': model.id === data.modelo }"
                    >
                        <input
                            type="radio"
                            name="conti-model"
                            :value="model.id"
                            :checked="model.id === data.modelo"
                            @change="choose(model.id)"
                        >
                        <span class="model-body">
                            <span class="model-head">
                                <strong>{{ model.nombre }}</strong>
                                <span v-if="model.costo" class="badge" :class="COST_BADGES[model.costo]">Costo {{ model.costo }}</span>
                                <span v-if="model.predeterminado" class="badge badge-neutral">Predeterminado</span>
                            </span>
                            <span class="model-desc">{{ model.descripcion }}</span>
                        </span>
                    </label>
                </fieldset>

                <p v-if="saving" class="settings-status muted" role="status">Guardando…</p>
                <p v-else-if="saveError" class="settings-status is-error" role="alert">{{ saveError }}</p>
                <p v-else-if="saved" class="settings-status is-ok" role="status">{{ saved }}</p>
            </section>

            <section class="settings-section" aria-labelledby="conti-usage-title">
                <h3 id="conti-usage-title">Tu consumo</h3>
                <table class="usage-table">
                    <thead>
                        <tr>
                            <th scope="col"><span class="sr-only">Período</span></th>
                            <th scope="col">Tokens</th>
                            <th scope="col">Créditos</th>
                            <th scope="col">Mensajes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="period in PERIODS" :key="period.key">
                            <th scope="row">{{ period.label }}</th>
                            <td>{{ tokens(data[period.key].tokens) }}</td>
                            <td>{{ credits(data[period.key].creditos) }}</td>
                            <td>{{ tokens(data[period.key].mensajes) }}</td>
                        </tr>
                    </tbody>
                </table>
                <p class="settings-note">
                    Los tokens son los pedazos de texto que lee y escribe el modelo: tus mensajes, lo que consulta y lo que responde.
                </p>
            </section>

            <section class="settings-section" aria-labelledby="conti-limits-title">
                <h3 id="conti-limits-title">Límites</h3>

                <ul v-if="data.limites.length" class="limit-list">
                    <li v-for="limit in data.limites" :key="limit.clave" class="limit" :class="level(limit)">
                        <div class="limit-head">
                            <span>{{ limit.nombre }}</span>
                            <span class="limit-value"><strong>{{ credits(limit.usado) }}</strong> de {{ credits(limit.limite) }} créditos</span>
                        </div>
                        <div
                            class="limit-bar"
                            role="progressbar"
                            :aria-label="limit.nombre"
                            aria-valuemin="0"
                            :aria-valuemax="Number(limit.limite)"
                            :aria-valuenow="Math.min(Number(limit.usado), Number(limit.limite))"
                        >
                            <span :style="{ width: `${percent(limit)}%` }" />
                        </div>
                        <span class="limit-renews">Se renueva {{ limit.renueva }}.</span>
                    </li>
                </ul>
                <p v-else class="settings-intro">Tu licencia no tiene límites de uso de Conti.</p>

                <p class="settings-note">
                    Los límites se miden en créditos: 1 crédito = {{ usdFormat.format(data.credito_usd) }} de lo que cobra OpenAI.
                    El cupo de la licencia lo comparten todas sus compañías y personas: si se agota, nadie puede usar Conti hasta que se renueve.
                    Los días y las semanas (de lunes a domingo) son en hora de Costa Rica.
                </p>
            </section>
        </template>
    </div>
</template>

<style scoped>
.conti-settings {
    flex: 1;
    min-height: 0;
    display: flex;
    flex-direction: column;
    gap: 1.1rem;
    padding: 0.9rem;
    overflow-y: auto;
    overscroll-behavior: contain;
}

.settings-back {
    align-self: flex-start;
}

.settings-state {
    margin: 0;
}

.flash.settings-state {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
}

.settings-section {
    display: flex;
    flex-direction: column;
    gap: 0.55rem;
}

.settings-section h3 {
    margin: 0;
    font-size: 0.9rem;
}

.settings-intro,
.settings-note,
.settings-status {
    margin: 0;
    font-size: 0.8rem;
    line-height: 1.45;
}

.settings-intro {
    color: var(--color-text);
}

.settings-note {
    color: var(--color-text-muted);
    font-size: 0.74rem;
}

.settings-status.is-ok {
    color: var(--color-success);
}

.settings-status.is-error {
    color: var(--color-danger);
}

.model-list {
    display: flex;
    flex-direction: column;
    gap: 0.45rem;
    margin: 0;
    padding: 0;
    border: none;
    min-width: 0;
}

.model-option {
    display: flex;
    align-items: flex-start;
    gap: 0.6rem;
    padding: 0.6rem 0.7rem;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    background: var(--color-surface);
    cursor: pointer;
}

.model-option:hover {
    border-color: color-mix(in srgb, var(--color-primary) 40%, var(--color-border));
}

.model-option.is-selected {
    border-color: var(--color-primary);
    background: var(--color-primary-soft);
}

.model-list:disabled .model-option {
    cursor: progress;
}

.model-option input {
    flex-shrink: 0;
    margin: 0.2rem 0 0;
    accent-color: var(--color-primary);
}

.model-body {
    display: flex;
    flex-direction: column;
    gap: 0.2rem;
    min-width: 0;
}

.model-head {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.35rem;
    font-size: 0.86rem;
}

.model-head .badge {
    font-size: 0.66rem;
}

.model-desc {
    font-size: 0.78rem;
    color: var(--color-text-muted);
    line-height: 1.4;
}

.usage-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.8rem;
}

.usage-table th,
.usage-table td {
    padding: 0.4rem 0.35rem;
    border-bottom: 1px solid var(--color-border);
    text-align: right;
    white-space: nowrap;
}

.usage-table thead th {
    font-size: 0.72rem;
    font-weight: 600;
    color: var(--color-text-muted);
}

.usage-table th[scope="row"] {
    text-align: left;
    font-weight: 600;
}

.usage-table td {
    font-variant-numeric: tabular-nums;
}

.limit-list {
    display: flex;
    flex-direction: column;
    gap: 0.8rem;
    margin: 0;
    padding: 0;
    list-style: none;
}

.limit {
    display: flex;
    flex-direction: column;
    gap: 0.3rem;
    font-size: 0.8rem;
}

.limit-head {
    display: flex;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 0.25rem 0.75rem;
}

.limit-value {
    font-variant-numeric: tabular-nums;
}

.limit-bar {
    height: 0.45rem;
    border-radius: 999px;
    background: var(--color-surface-alt);
    overflow: hidden;
}

.limit-bar span {
    display: block;
    height: 100%;
    border-radius: inherit;
    background: var(--color-primary);
}

.limit.is-high .limit-bar span {
    background: var(--color-warning);
}

.limit.is-full .limit-bar span {
    background: var(--color-danger);
}

.limit-renews {
    font-size: 0.72rem;
    color: var(--color-text-muted);
}
</style>
