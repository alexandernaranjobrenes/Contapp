<script setup>
import { Head, Link, useForm, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DetailModal from '../../../Components/DetailModal.vue';
import { confirmAction } from '../../../Utils/confirm';
import { useRecordDetail } from '../../../Utils/recordDetail';
import { ArrowRightIcon, PlusIcon } from '@lucide/vue';

const props = defineProps({
    periods: { type: Array, default: () => [] },
    frequencies: { type: Object, required: true },
    statuses: { type: Object, required: true },
});

const page = usePage();

const creating = ref(false);
const form = useForm({
    year: new Date().getFullYear(),
    frequency: 'quincenal',
    number: 1,
    name: '',
    start_date: '',
    end_date: '',
    payment_date: '',
});

function openCreate() {
    form.reset();
    form.year = new Date().getFullYear();
    creating.value = true;
}

// El nombre se arma solo mientras nadie lo toque: nadie quiere escribir
// "Marzo 2026 · 1.ª quincena" veinticuatro veces al año.
const nameTouched = ref(false);

const suggestedName = computed(() => {
    if (! form.start_date) return '';

    const date = new Date(`${form.start_date}T00:00:00`);
    const month = date.toLocaleDateString('es-CR', { month: 'long' });
    const label = month.charAt(0).toUpperCase() + month.slice(1);

    if (form.frequency === 'mensual') return `${label} ${date.getFullYear()}`;
    if (form.frequency === 'semanal') return `${label} ${date.getFullYear()} · semana ${form.number}`;

    return `${label} ${date.getFullYear()} · ${date.getDate() <= 15 ? '1.ª' : '2.ª'} quincena`;
});

function syncName() {
    if (! nameTouched.value) form.name = suggestedName.value;
}

function submit() {
    if (! form.name) form.name = suggestedName.value;

    form.post(route('payroll-periods.store'), {
        onSuccess: () => { creating.value = false; nameTouched.value = false; },
        preserveScroll: true,
    });
}

function destroy() {
    const period = selected.value;

    confirmAction({
        title: 'Eliminar período',
        message: `El período «${period.name}» se elimina junto con sus boletas calculadas.`,
        confirmLabel: 'Eliminar',
        danger: true,
        onConfirm: () => router.delete(route('payroll-periods.destroy', period.id), { preserveScroll: true }),
    });
}

// Ficha del período (CLAUDE.md secc. 20): fechas, asiento y las acciones.
const { selected, openDetail, closeDetail } = useRecordDetail(() => props.periods);

const statusClass = {
    open: 'badge-neutral',
    calculated: 'badge-warning',
    approved: 'badge-warning',
    posted: 'badge-success',
    closed: 'badge-neutral',
};
</script>

<template>
    <Head title="Períodos de planilla" />

    <AppLayout title="Períodos de planilla">
        <div class="view-toolbar">
            <div class="view-actions">
                <Link :href="route('employees.index')" class="btn btn-ghost">Empleados</Link>
                <Link :href="route('payroll-settings.index')" class="btn btn-ghost">Configuración</Link>
                <button type="button" class="btn btn-primary" @click="openCreate()"><PlusIcon /> Crear nuevo</button>
            </div>
            <div class="view-filters">
                <span class="muted small">{{ periods.length }} período(s)</span>
            </div>
        </div>

        <div v-if="page.props.errors?.payroll" class="flash flash-error">{{ page.props.errors.payroll }}</div>

        <p class="hint">
            Una planilla recorre cuatro estados y solo se puede recalcular en los dos primeros:
            <strong>abierta</strong> → <strong>calculada</strong> → <strong>aprobada</strong> →
            <strong>contabilizada</strong>. Después hay un asiento que la respalda, y cambiarla por detrás
            dejaría la contabilidad diciendo una cosa y la planilla otra.
        </p>

        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Período</th>
                            <th>Pago</th>
                            <th class="num">Boletas</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="p in periods"
                            :key="p.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="openDetail(p)"
                            @keydown.enter="openDetail(p)"
                            @keydown.space.prevent="openDetail(p)"
                        >
                            <td>{{ p.name }}</td>
                            <td data-label="Pago" class="code-cell">{{ p.payment_date }}</td>
                            <td data-label="Boletas" class="num">{{ p.entries_count }}</td>
                            <td data-label="Estado">
                                <span class="badge" :class="statusClass[p.status]">{{ p.status_label }}</span>
                            </td>
                        </tr>
                        <tr v-if="!periods.length">
                            <td colspan="4" class="muted empty-row">
                                Todavía no hay períodos. Creá el primero para poder calcular una planilla.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <DetailModal :open="!!selected" :title="selected?.name ?? ''" @close="closeDetail">
            <template #badge>
                <span v-if="selected" class="badge" :class="statusClass[selected.status]">{{ selected.status_label }}</span>
            </template>

            <dl v-if="selected" class="detail-list">
                <div>
                    <dt>Frecuencia</dt>
                    <dd>{{ selected.frequency_label }}</dd>
                </div>
                <div>
                    <dt>Desde</dt>
                    <dd>{{ selected.start_date }}</dd>
                </div>
                <div>
                    <dt>Hasta</dt>
                    <dd>{{ selected.end_date }}</dd>
                </div>
                <div>
                    <dt>Fecha de pago</dt>
                    <dd>{{ selected.payment_date }}</dd>
                </div>
                <div>
                    <dt>Boletas</dt>
                    <dd>{{ selected.entries_count }}</dd>
                </div>
                <div>
                    <dt>Asiento</dt>
                    <dd>
                        <Link v-if="selected.journal_entry_id" :href="route('journal-entries.show', selected.journal_entry_id)">
                            #{{ selected.journal_entry_id }}
                        </Link>
                        <span v-else class="muted">—</span>
                    </dd>
                </div>
            </dl>

            <template #actions>
                <template v-if="selected">
                    <button
                        v-if="selected.status === 'open' || selected.status === 'calculated'"
                        type="button" class="btn btn-ghost btn-danger-text" @click="destroy"
                    >Eliminar</button>
                    <Link :href="route('payroll-periods.show', selected.id)" class="btn btn-primary"><ArrowRightIcon /> Abrir</Link>
                </template>
            </template>
        </DetailModal>

        <DetailModal :open="creating" wide title="Nuevo período de planilla" @close="creating = false">
            <form id="period-form" @submit.prevent="submit">
                <div class="form-grid">
                    <div class="field">
                        <label for="period-year">Año</label>
                        <input id="period-year" v-model="form.year" type="number" min="2000" max="2100" required>
                    </div>
                    <div class="field">
                        <label for="period-frequency">Frecuencia</label>
                        <select id="period-frequency" v-model="form.frequency" required @change="syncName">
                            <option v-for="(label, value) in frequencies" :key="value" :value="value">{{ label }}</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="period-number">Número</label>
                        <input id="period-number" v-model="form.number" type="number" min="1" max="60" required @input="syncName">
                        <span v-if="form.errors.number" class="error">{{ form.errors.number }}</span>
                    </div>
                    <div class="field">
                        <label for="period-start">Desde</label>
                        <input id="period-start" v-model="form.start_date" type="date" required @change="syncName">
                        <span v-if="form.errors.start_date" class="error">{{ form.errors.start_date }}</span>
                    </div>
                    <div class="field">
                        <label for="period-end">Hasta</label>
                        <input id="period-end" v-model="form.end_date" type="date" required>
                        <span v-if="form.errors.end_date" class="error">{{ form.errors.end_date }}</span>
                    </div>
                    <div class="field">
                        <label for="period-payment">Fecha de pago</label>
                        <input id="period-payment" v-model="form.payment_date" type="date" required>
                        <span v-if="form.errors.payment_date" class="error">{{ form.errors.payment_date }}</span>
                    </div>
                </div>

                <p class="hint small">
                    La fecha de pago manda para el asiento y para el archivo del banco, y puede caer en el mes
                    siguiente al trabajado. Es también la fecha con la que se eligen las tasas vigentes.
                </p>

                <div class="field">
                    <label for="period-name">Nombre</label>
                    <input id="period-name" v-model="form.name" type="text" required @input="nameTouched = true">
                    <span v-if="form.errors.name" class="error">{{ form.errors.name }}</span>
                </div>
            </form>

            <template #actions>
                <button type="button" class="btn btn-ghost" @click="creating = false">Cancelar</button>
                <button type="submit" form="period-form" class="btn btn-primary" :disabled="form.processing">Crear</button>
            </template>
        </DetailModal>
    </AppLayout>
</template>

<style scoped>
table { font-size: 0.85rem; }
.code-cell { font-variant-numeric: tabular-nums; white-space: nowrap; }
</style>
