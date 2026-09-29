<script setup>
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { LockIcon, LockOpenIcon, PlusIcon } from '@lucide/vue';

import AppLayout from '../../Layouts/AppLayout.vue';
import DetailModal from '../../Components/DetailModal.vue';
import { confirmAction } from '../../Utils/confirm';

const props = defineProps({
    fiscalYears: { type: Array, default: () => [] },
    equityAccounts: { type: Array, default: () => [] },
    canReopen: { type: Boolean, default: false },
});

const page = usePage();
const retainedEarningsAccount = ref(props.equityAccounts[0]?.id ?? null);

const statusLabels = { open: 'Abierto', blocked: 'Bloqueado', closed: 'Cerrado' };
const statusBadge = { open: 'badge-success', blocked: 'badge-warning', closed: 'badge-neutral' };

// Ficha del período (CLAUDE.md secc. 20): cerrarlo o reabrirlo se hace desde
// ahí, no con un botón en la fila.
const selectedPeriodId = ref(null);

const selectedPeriod = computed(() => {
    for (const fy of props.fiscalYears) {
        const found = fy.periods.find((p) => p.id === selectedPeriodId.value);
        if (found) return { ...found, year: fy.year };
    }

    return null;
});

function openPeriod(period) {
    selectedPeriodId.value = period.id;
}

function closeDetail() {
    selectedPeriodId.value = null;
}

function closePeriod() {
    router.post(route('period-close.close', selectedPeriod.value.id), {}, { preserveScroll: true });
}

function reopenPeriod() {
    router.post(route('period-close.reopen', selectedPeriod.value.id), {}, { preserveScroll: true });
}

// Cerrar el año contabiliza el asiento de cierre contra la cuenta de
// utilidades acumuladas elegida: se confirma antes.
function closeYear(fiscalYear) {
    const account = props.equityAccounts.find((a) => a.id === retainedEarningsAccount.value);

    confirmAction({
        title: `Cerrar el año fiscal ${fiscalYear.year}`,
        message: `Se contabiliza el asiento de cierre contra ${account ? `${account.code} — ${account.description_es}` : 'la cuenta de utilidades acumuladas elegida'}.`,
        confirmLabel: 'Cerrar año',
        onConfirm: () => router.post(route('period-close.close-year', fiscalYear.id), {
            retained_earnings_account_id: retainedEarningsAccount.value,
        }, { preserveScroll: true }),
    });
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
        <div class="view-toolbar">
            <div class="view-actions">
                <button type="button" class="btn btn-primary" @click="createNextYear"><PlusIcon /> Crear próximo año fiscal</button>
            </div>
        </div>

        <div v-if="page.props.errors?.period || page.props.errors?.year" class="flash flash-error">
            {{ page.props.errors.period || page.props.errors.year }}
        </div>

        <section v-for="fy in fiscalYears" :key="fy.id" class="year-block">
            <div class="year-header">
                <h2 class="block-title year-title">
                    Año fiscal {{ fy.year }}
                    <span class="badge" :class="fy.status === 'closed' ? 'badge-neutral' : 'badge-success'">
                        {{ fy.status === 'closed' ? 'Cerrado' : 'Abierto' }}
                    </span>
                </h2>

                <!-- Cerrar el año es una acción del año entero: va arriba de su
                     tabla, no en una fila. -->
                <div v-if="fy.status !== 'closed'" class="view-actions close-year">
                    <label class="filter-field">
                        <span>Utilidades acumuladas</span>
                        <select v-model="retainedEarningsAccount">
                            <option v-for="a in equityAccounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.description_es }}</option>
                        </select>
                    </label>
                    <button type="button" class="btn btn-primary" @click="closeYear(fy)"><LockIcon /> Cerrar año</button>
                </div>
            </div>

            <div class="card">
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Período</th>
                                <th>Desde</th>
                                <th>Hasta</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="p in fy.periods"
                                :key="p.id"
                                class="clickable-row"
                                tabindex="0"
                                @click="openPeriod(p)"
                                @keydown.enter="openPeriod(p)"
                                @keydown.space.prevent="openPeriod(p)"
                            >
                                <td>Período {{ p.period_number }}</td>
                                <td data-label="Desde">{{ p.start_date }}</td>
                                <td data-label="Hasta">{{ p.end_date }}</td>
                                <td data-label="Estado"><span class="badge" :class="statusBadge[p.status]">{{ statusLabels[p.status] }}</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <p v-if="!fiscalYears.length" class="muted">No hay años fiscales configurados para esta compañía.</p>

        <DetailModal
            :open="!!selectedPeriod"
            :title="selectedPeriod ? `Período ${selectedPeriod.period_number} de ${selectedPeriod.year}` : ''"
            @close="closeDetail"
        >
            <template #badge>
                <span v-if="selectedPeriod" class="badge" :class="statusBadge[selectedPeriod.status]">{{ statusLabels[selectedPeriod.status] }}</span>
            </template>

            <template v-if="selectedPeriod">
                <dl class="detail-list">
                    <div>
                        <dt>Desde</dt>
                        <dd>{{ selectedPeriod.start_date }}</dd>
                    </div>
                    <div>
                        <dt>Hasta</dt>
                        <dd>{{ selectedPeriod.end_date }}</dd>
                    </div>
                </dl>
                <p v-if="selectedPeriod.status === 'closed' && !canReopen" class="hint reopen-hint">
                    Solo un superusuario puede reabrir un período cerrado.
                </p>
            </template>

            <template #actions>
                <template v-if="selectedPeriod">
                    <button v-if="selectedPeriod.status !== 'closed'" type="button" class="btn btn-primary" @click="closePeriod">
                        <LockIcon /> Cerrar período
                    </button>
                    <button v-else-if="canReopen" type="button" class="btn btn-primary" @click="reopenPeriod">
                        <LockOpenIcon /> Reabrir período
                    </button>
                </template>
            </template>
        </DetailModal>
    </AppLayout>
</template>

<style scoped>
table { font-size: 0.85rem; }

.year-block + .year-block { margin-top: 1.5rem; }

.year-header {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 0.75rem 1rem;
    margin-bottom: 0.75rem;
}

.year-title {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    margin: 0;
}

.close-year {
    align-items: flex-end;
}

.close-year select {
    max-width: 22rem;
}

.reopen-hint { margin: 0.9rem 0 0; }
</style>
