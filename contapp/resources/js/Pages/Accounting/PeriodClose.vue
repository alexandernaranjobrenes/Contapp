<script setup>
import { Head, router, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';

const props = defineProps({
    fiscalYears: { type: Array, default: () => [] },
    equityAccounts: { type: Array, default: () => [] },
    canReopen: { type: Boolean, default: false },
});

const page = usePage();
const retainedEarningsAccount = ref(props.equityAccounts[0]?.id ?? null);

const statusLabels = { open: 'Abierto', blocked: 'Bloqueado', closed: 'Cerrado' };
const statusBadge = { open: 'badge-success', blocked: 'badge-warning', closed: 'badge-neutral' };

function closePeriod(period) {
    router.post(route('period-close.close', period.id), {}, { preserveScroll: true });
}

function reopenPeriod(period) {
    router.post(route('period-close.reopen', period.id), {}, { preserveScroll: true });
}

function closeYear(fiscalYear) {
    router.post(route('period-close.close-year', fiscalYear.id), {
        retained_earnings_account_id: retainedEarningsAccount.value,
    }, { preserveScroll: true });
}

// No depende de que el año anterior esté cerrado, a propósito — un negocio
// real necesita poder seguir contabilizando enero aunque diciembre del año
// pasado todavía no esté auditado/cerrado (ver PeriodCloseService::createNextYear()).
function createNextYear() {
    router.post(route('period-close.create-year'), {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Cierre de períodos" />

    <AppLayout title="Cierre de períodos">
        <template #actions>
            <button type="button" class="btn btn-primary" @click="createNextYear">+ Crear próximo año fiscal</button>
        </template>

        <div v-if="page.props.errors?.period || page.props.errors?.year" class="flash flash-error">
            {{ page.props.errors.period || page.props.errors.year }}
        </div>

        <div v-for="fy in fiscalYears" :key="fy.id" class="card year-card">
            <div class="year-header">
                <h2>Año fiscal {{ fy.year }}</h2>
                <span class="badge" :class="fy.status === 'closed' ? 'badge-neutral' : 'badge-success'">
                    {{ fy.status === 'closed' ? 'Cerrado' : 'Abierto' }}
                </span>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Período</th>
                        <th>Desde</th>
                        <th>Hasta</th>
                        <th>Estado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="p in fy.periods" :key="p.id">
                        <td>#{{ p.period_number }}</td>
                        <td>{{ p.start_date }}</td>
                        <td>{{ p.end_date }}</td>
                        <td><span class="badge" :class="statusBadge[p.status]">{{ statusLabels[p.status] }}</span></td>
                        <td>
                            <button
                                v-if="p.status !== 'closed'"
                                type="button"
                                class="btn btn-ghost"
                                @click="closePeriod(p)"
                            >Cerrar</button>
                            <button
                                v-else-if="canReopen"
                                type="button"
                                class="btn btn-ghost"
                                @click="reopenPeriod(p)"
                            >Reabrir</button>
                            <span v-else class="muted small">Solo super usuario</span>
                        </td>
                    </tr>
                </tbody>
            </table>

            <div v-if="fy.status !== 'closed'" class="close-year-bar">
                <label>Utilidades acumuladas:</label>
                <select v-model="retainedEarningsAccount">
                    <option v-for="a in equityAccounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.description_es }}</option>
                </select>
                <button type="button" class="btn btn-primary" @click="closeYear(fy)">Cerrar año</button>
            </div>
        </div>

        <p v-if="!fiscalYears.length" class="muted">No hay años fiscales configurados para esta compañía.</p>
    </AppLayout>
</template>

<style scoped>
.year-card { padding: 1rem 1.1rem; margin-bottom: 1rem; }
.year-header { display: flex; align-items: center; gap: 0.6rem; margin-bottom: 0.5rem; }
.year-header h2 { font-size: 0.95rem; margin: 0; }

table { font-size: 0.85rem; }
th, td { text-align: left; padding: 0.45rem 0.7rem; border-top: 1px solid var(--color-border); }

.close-year-bar {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    margin-top: 0.85rem;
    padding-top: 0.85rem;
    border-top: 1px solid var(--color-border);
    font-size: 0.82rem;
}

.close-year-bar select {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.35rem 0.5rem;
    font-size: 0.82rem;
}

.muted { color: var(--color-text-muted); }
.small { font-size: 0.78rem; }

.flash { margin-bottom: 1rem; padding: 0.6rem 0.9rem; border-radius: var(--radius-sm); font-size: 0.85rem; }
.flash-error { background: var(--color-danger-soft); color: var(--color-danger); }
</style>
