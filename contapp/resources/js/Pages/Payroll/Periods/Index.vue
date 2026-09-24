<script setup>
import { Head, Link, useForm, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DocumentToolbar from '../../../Components/DocumentToolbar.vue';

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

function destroy(period) {
    if (! confirm(`¿Eliminar el período «${period.name}»?`)) return;

    router.delete(route('payroll-periods.destroy', period.id), { preserveScroll: true });
}

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
        <template #actions>
            <Link :href="route('employees.index')" class="btn btn-ghost">Empleados</Link>
            <Link :href="route('payroll-settings.index')" class="btn btn-ghost">Configuración</Link>
        </template>

        <DocumentToolbar can-create @new="openCreate()" />

        <div v-if="page.props.errors?.payroll" class="flash flash-error">{{ page.props.errors.payroll }}</div>

        <p class="hint">
            Una planilla recorre cuatro estados y solo se puede recalcular en los dos primeros:
            <strong>abierta</strong> → <strong>calculada</strong> → <strong>aprobada</strong> →
            <strong>contabilizada</strong>. Después hay un asiento que la respalda, y cambiarla por detrás
            dejaría la contabilidad diciendo una cosa y la planilla otra.
        </p>

        <div class="card">
            <div class="card-header">
                <span class="muted">{{ periods.length }} período(s)</span>
                <button type="button" class="btn btn-primary" @click="openCreate()">+ Nuevo período</button>
            </div>

            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Período</th>
                            <th>Frecuencia</th>
                            <th>Desde</th>
                            <th>Hasta</th>
                            <th>Pago</th>
                            <th class="right">Boletas</th>
                            <th>Estado</th>
                            <th>Asiento</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="p in periods" :key="p.id">
                            <td>
                                <Link :href="route('payroll-periods.show', p.id)">{{ p.name }}</Link>
                            </td>
                            <td class="muted small">{{ p.frequency_label }}</td>
                            <td class="num small">{{ p.start_date }}</td>
                            <td class="num small">{{ p.end_date }}</td>
                            <td class="num small">{{ p.payment_date }}</td>
                            <td class="right num">{{ p.entries_count }}</td>
                            <td>
                                <span class="badge" :class="statusClass[p.status]">{{ p.status_label }}</span>
                            </td>
                            <td class="num small">
                                <Link v-if="p.journal_entry_id" :href="route('journal-entries.show', p.journal_entry_id)">
                                    #{{ p.journal_entry_id }}
                                </Link>
                                <span v-else class="muted">—</span>
                            </td>
                            <td class="row-actions">
                                <Link :href="route('payroll-periods.show', p.id)" class="btn btn-ghost btn-sm">Abrir</Link>
                                <button
                                    v-if="p.status === 'open' || p.status === 'calculated'"
                                    type="button" class="btn btn-ghost btn-sm" @click="destroy(p)"
                                >Eliminar</button>
                            </td>
                        </tr>
                        <tr v-if="!periods.length">
                            <td colspan="9" class="muted empty-row">
                                Todavía no hay períodos. Creá el primero para poder calcular una planilla.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="creating" class="modal-backdrop" @click.self="creating = false">
            <form class="modal card" @submit.prevent="submit">
                <h2>Nuevo período de planilla</h2>

                <div class="field-row">
                    <div class="field">
                        <label>Año</label>
                        <input v-model="form.year" type="number" min="2000" max="2100" required>
                    </div>
                    <div class="field">
                        <label>Frecuencia</label>
                        <select v-model="form.frequency" required @change="syncName">
                            <option v-for="(label, value) in frequencies" :key="value" :value="value">{{ label }}</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>Número</label>
                        <input v-model="form.number" type="number" min="1" max="60" required @input="syncName">
                        <span v-if="form.errors.number" class="error">{{ form.errors.number }}</span>
                    </div>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label>Desde</label>
                        <input v-model="form.start_date" type="date" required @change="syncName">
                        <span v-if="form.errors.start_date" class="error">{{ form.errors.start_date }}</span>
                    </div>
                    <div class="field">
                        <label>Hasta</label>
                        <input v-model="form.end_date" type="date" required>
                        <span v-if="form.errors.end_date" class="error">{{ form.errors.end_date }}</span>
                    </div>
                    <div class="field">
                        <label>Fecha de pago</label>
                        <input v-model="form.payment_date" type="date" required>
                        <span v-if="form.errors.payment_date" class="error">{{ form.errors.payment_date }}</span>
                    </div>
                </div>

                <span class="hint small">
                    La fecha de pago manda para el asiento y para el archivo del banco, y puede caer en el mes
                    siguiente al trabajado. Es también la fecha con la que se eligen las tasas vigentes.
                </span>

                <div class="field">
                    <label>Nombre</label>
                    <input v-model="form.name" type="text" required @input="nameTouched = true">
                    <span v-if="form.errors.name" class="error">{{ form.errors.name }}</span>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-ghost" @click="creating = false">Cancelar</button>
                    <button type="submit" class="btn btn-primary" :disabled="form.processing">Crear</button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>

<style scoped>
.error { color: var(--color-danger); font-size: 0.76rem; }
</style>
