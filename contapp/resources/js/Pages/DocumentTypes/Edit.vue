<script setup>
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';

import AppLayout from '../../Layouts/AppLayout.vue';
import DocumentToolbar from '../../Components/DocumentToolbar.vue';

const props = defineProps({
    documentType: { type: Object, required: true },
    accounts: { type: Array, default: () => [] },
    originModules: { type: Object, required: true },
    currencyModes: { type: Object, required: true },
    bpLineRequirements: { type: Object, required: true },
});

const form = useForm({
    name: props.documentType.name,
    origin_module: props.documentType.origin_module,
    generates_journal: props.documentType.generates_journal,
    requires_electronic_key: props.documentType.requires_electronic_key,
    bp_line_requirement: props.documentType.bp_line_requirement,
    currency_mode: props.documentType.currency_mode,
    default_debit_account_id: props.documentType.default_debit_account_id,
    default_credit_account_id: props.documentType.default_credit_account_id,
    status: props.documentType.status,
});

function submit() {
    form.put(route('document-types.update', props.documentType.id));
}

// --- series de numeración manual ---

const creatingSeries = ref(false);
const seriesForm = useForm({ name: '', holder_name: '', range_from: '', range_to: '' });

function openCreateSeries() {
    seriesForm.reset();
    creatingSeries.value = true;
}

function submitSeries() {
    seriesForm.post(route('document-type-number-series.store', props.documentType.id), {
        onSuccess: () => { creatingSeries.value = false; },
        preserveScroll: true,
    });
}

const editingSeries = ref(null);
const editSeriesForm = useForm({ name: '', holder_name: '', range_to: '', is_active: true });

function openEditSeries(series) {
    editSeriesForm.clearErrors();
    editSeriesForm.name = series.name;
    editSeriesForm.holder_name = series.holder_name ?? '';
    editSeriesForm.range_to = series.range_to;
    editSeriesForm.is_active = series.is_active;
    editingSeries.value = series;
}

function submitEditSeries() {
    editSeriesForm.put(route('document-type-number-series.update', editingSeries.value.id), {
        onSuccess: () => { editingSeries.value = null; },
        preserveScroll: true,
    });
}

function destroySeries(series) {
    if (! confirm(`¿Eliminar la serie "${series.name}"?`)) return;

    router.delete(route('document-type-number-series.destroy', series.id), { preserveScroll: true });
}

function remaining(series) {
    return Math.max(0, series.range_to - series.next_number + 1);
}
</script>

<template>
    <Head :title="`Editar ${documentType.code}`" />

    <AppLayout :title="`Tipo de documento — ${documentType.code}`">
        <DocumentToolbar :new-href="route('document-types.create')" can-save :saving="form.processing" @save="submit" />

        <div class="layout">
            <form class="card form-card" @submit.prevent="submit">
                <div class="grid">
                    <div class="field">
                        <label>Código</label>
                        <input :value="documentType.code" type="text" disabled>
                        <span class="muted small">El código no se puede cambiar una vez creado.</span>
                    </div>

                    <div class="field">
                        <label>Consecutivo interno actual</label>
                        <input :value="documentType.next_consecutive" type="text" disabled>
                        <span class="muted small">Automático e inalterable.</span>
                    </div>

                    <div class="field span-2">
                        <label>Nombre</label>
                        <input v-model="form.name" type="text" required>
                        <span v-if="form.errors.name" class="error">{{ form.errors.name }}</span>
                    </div>

                    <div class="field">
                        <label>Módulo de origen</label>
                        <select v-model="form.origin_module" required>
                            <option v-for="(label, key) in originModules" :key="key" :value="key">{{ label }}</option>
                        </select>
                    </div>

                    <div class="field">
                        <label>Moneda del documento</label>
                        <select v-model="form.currency_mode" required>
                            <option v-for="(label, key) in currencyModes" :key="key" :value="key">{{ label }}</option>
                        </select>
                    </div>

                    <div class="field">
                        <label>Cuenta débito por defecto</label>
                        <select v-model="form.default_debit_account_id">
                            <option :value="null">—</option>
                            <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.description_es }}</option>
                        </select>
                    </div>

                    <div class="field">
                        <label>Cuenta crédito por defecto</label>
                        <select v-model="form.default_credit_account_id">
                            <option :value="null">—</option>
                            <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.description_es }}</option>
                        </select>
                    </div>

                    <div class="field">
                        <label>Estado</label>
                        <select v-model="form.status">
                            <option value="active">Activo</option>
                            <option value="inactive">Inactivo</option>
                        </select>
                    </div>
                </div>

                <label class="check-row">
                    <input v-model="form.generates_journal" type="checkbox">
                    Genera asiento contable
                </label>

                <label class="check-row">
                    <input v-model="form.requires_electronic_key" type="checkbox">
                    Exige clave numérica electrónica de Hacienda (50 dígitos)
                </label>

                <div class="field">
                    <label>Control de socio de negocio en líneas</label>
                    <select v-model="form.bp_line_requirement">
                        <option v-for="(label, key) in bpLineRequirements" :key="key" :value="key">{{ label }}</option>
                    </select>
                    <span class="muted small">Qué exige este tipo de documento en cada línea que traiga un socio de negocio: abrir una partida nueva (vencimiento), cancelar una existente (aplicación), aceptar cualquiera de las dos, o no exigir nada. Base de los reportes de antigüedad de saldos y estados de cuenta por socio.</span>
                    <span v-if="form.errors.bp_line_requirement" class="error">{{ form.errors.bp_line_requirement }}</span>
                </div>

                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary" :disabled="form.processing">Guardar</button>
                </div>
            </form>

            <div class="card series-card">
                <div class="series-header">
                    <div>
                        <strong>Series de numeración manual</strong>
                        <p class="muted small">
                            El consecutivo interno de arriba siempre se asigna solo. Además, opcionalmente,
                            cada documento puede llevar un número de una de estas series (rango fijo, configurable).
                        </p>
                    </div>
                    <button type="button" class="btn btn-primary" @click="openCreateSeries">+ Serie</button>
                </div>

                <table>
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Encargado</th>
                            <th>Rango</th>
                            <th>Siguiente</th>
                            <th>Disponibles</th>
                            <th>Estado</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="s in documentType.number_series" :key="s.id">
                            <td>{{ s.name }}</td>
                            <td>{{ s.holder_name || '—' }}</td>
                            <td class="num">{{ s.range_from }} – {{ s.range_to }}</td>
                            <td class="num">{{ s.next_number > s.range_to ? 'agotada' : s.next_number }}</td>
                            <td class="num">{{ remaining(s) }}</td>
                            <td>
                                <span class="badge" :class="s.is_active ? 'badge-success' : 'badge-neutral'">
                                    {{ s.is_active ? 'Activa' : 'Inactiva' }}
                                </span>
                            </td>
                            <td class="actions-cell">
                                <button type="button" class="btn btn-ghost" @click="openEditSeries(s)">Editar</button>
                                <button type="button" class="btn btn-ghost" @click="destroySeries(s)">Eliminar</button>
                            </td>
                        </tr>
                        <tr v-if="!documentType.number_series.length">
                            <td colspan="7" class="muted empty-row">Todavía no hay series configuradas para este tipo de documento.</td>
                        </tr>
                    </tbody>
                </table>
                <p v-if="$page.props.errors.series" class="error series-error">{{ $page.props.errors.series }}</p>
            </div>
        </div>

        <!-- Nueva serie -->
        <div v-if="creatingSeries" class="modal-backdrop" @click.self="creatingSeries = false">
            <form class="modal-card card" @submit.prevent="submitSeries">
                <h2>Nueva serie</h2>

                <div class="field">
                    <label>Nombre</label>
                    <input v-model="seriesForm.name" type="text" placeholder="PJ01" required>
                    <span v-if="seriesForm.errors.name" class="error">{{ seriesForm.errors.name }}</span>
                </div>

                <div class="field">
                    <label>Encargado (opcional)</label>
                    <input v-model="seriesForm.holder_name" type="text" placeholder="Pedro Jiménez">
                    <span class="muted small">A quién se le entrega este talonario/rango — para identificar quién cobró.</span>
                    <span v-if="seriesForm.errors.holder_name" class="error">{{ seriesForm.errors.holder_name }}</span>
                </div>

                <div class="grid-2">
                    <div class="field">
                        <label>Número inicial</label>
                        <input v-model="seriesForm.range_from" type="number" min="1" required>
                        <span v-if="seriesForm.errors.range_from" class="error">{{ seriesForm.errors.range_from }}</span>
                    </div>
                    <div class="field">
                        <label>Número final</label>
                        <input v-model="seriesForm.range_to" type="number" min="1" required>
                        <span v-if="seriesForm.errors.range_to" class="error">{{ seriesForm.errors.range_to }}</span>
                    </div>
                </div>

                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary" :disabled="seriesForm.processing">Guardar</button>
                    <button type="button" class="btn btn-ghost" @click="creatingSeries = false">Cancelar</button>
                </div>
            </form>
        </div>

        <!-- Editar serie -->
        <div v-if="editingSeries" class="modal-backdrop" @click.self="editingSeries = null">
            <form class="modal-card card" @submit.prevent="submitEditSeries">
                <h2>Editar serie</h2>
                <p class="muted small">El número inicial no se puede cambiar una vez creada la serie.</p>

                <div class="field">
                    <label>Nombre</label>
                    <input v-model="editSeriesForm.name" type="text" required>
                    <span v-if="editSeriesForm.errors.name" class="error">{{ editSeriesForm.errors.name }}</span>
                </div>

                <div class="field">
                    <label>Encargado (opcional)</label>
                    <input v-model="editSeriesForm.holder_name" type="text" placeholder="Pedro Jiménez">
                    <span v-if="editSeriesForm.errors.holder_name" class="error">{{ editSeriesForm.errors.holder_name }}</span>
                </div>

                <div class="field">
                    <label>Número final</label>
                    <input v-model="editSeriesForm.range_to" type="number" min="1" required>
                    <span v-if="editSeriesForm.errors.range_to" class="error">{{ editSeriesForm.errors.range_to }}</span>
                </div>

                <label class="check-row">
                    <input v-model="editSeriesForm.is_active" type="checkbox">
                    Activa
                </label>

                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary" :disabled="editSeriesForm.processing">Guardar</button>
                    <button type="button" class="btn btn-ghost" @click="editingSeries = null">Cancelar</button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>

<style scoped>
.layout {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
    align-items: start;
}

.form-card { padding: 1.25rem; }
.grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 1.25rem; }
.span-2 { grid-column: span 2; }

.field {
    display: flex;
    flex-direction: column;
    gap: 0.2rem;
    margin-bottom: 0.85rem;
}

.field label {
    font-size: 0.78rem;
    color: var(--color-text-muted);
}

.field input, .field select {
    width: 100%;
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.45rem 0.6rem;
    font-size: 0.85rem;
    color: var(--color-text);
}

.field input:disabled {
    color: var(--color-text-muted);
    background: var(--color-surface-alt);
}

.muted { color: var(--color-text-muted); }
.small { font-size: 0.74rem; }
.error { color: var(--color-danger); font-size: 0.76rem; }

.check-row {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.85rem;
    margin: 0.25rem 0 1.25rem;
}

.modal-actions { display: flex; gap: 0.6rem; }

.series-card { padding: 0; }

.series-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    padding: 1rem 1.1rem;
    border-bottom: 1px solid var(--color-border);
}

.series-error { padding: 0 1.1rem 1rem; }

table { font-size: 0.82rem; width: 100%; }
th, td { text-align: left; padding: 0.5rem 0.9rem; border-top: 1px solid var(--color-border); white-space: nowrap; }
.num { font-variant-numeric: tabular-nums; }
.empty-row { text-align: center; padding: 1.25rem; white-space: normal; }
.actions-cell { display: flex; gap: 0.4rem; }

.modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(11, 31, 58, 0.45);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 50;
    padding: 1rem;
}

.modal-card {
    width: 420px;
    max-width: 100%;
    padding: 1.5rem;
}

.modal-card h2 { font-size: 1rem; margin: 0 0 0.5rem; }

.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0 1rem; }
</style>
