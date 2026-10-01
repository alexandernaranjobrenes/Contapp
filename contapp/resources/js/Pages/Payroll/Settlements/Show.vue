<script setup>
import { Head, Link, useForm, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DetailModal from '../../../Components/DetailModal.vue';
import { confirmAction } from '../../../Utils/confirm';
import { formatMoney } from '../../../Utils/money';
import { ArrowLeftIcon, BookOpenIcon, CheckIcon, PlusIcon, RefreshCwIcon, RotateCcwIcon, UserIcon, XIcon } from '@lucide/vue';

const props = defineProps({
    settlement: { type: Object, required: true },
    lines: { type: Array, default: () => [] },
    findings: { type: Array, default: () => [] },
    reasons: { type: Object, required: true },
    statuses: { type: Object, required: true },
    kinds: { type: Object, default: () => ({}) },
});

const page = usePage();

const isDraft = computed(() => props.settlement.status === 'draft');
const isApproved = computed(() => props.settlement.status === 'approved');
const isPosted = computed(() => props.settlement.status === 'posted');

// Los renglones que el motor no deriva solo: salarios pendientes, deducciones
// autorizadas, indemnizaciones pactadas. Van completos en cada recálculo —no
// de a uno— para que recalcular dé siempre lo mismo que calcular desde cero.
const manualKinds = ['pending_salary', 'indemnity', 'deduction'];

const manual = ref(
    props.lines
        .filter((l) => manualKinds.includes(l.kind))
        .map((l) => ({
            kind: l.kind,
            code: l.code,
            name: l.name,
            detail: l.detail ?? '',
            days: l.days ?? '',
            daily_rate: l.daily_rate ?? '',
            amount: l.amount ?? '',
        }))
);

const recalcForm = useForm({ reason: props.settlement.reason, reason_detail: props.settlement.reason_detail ?? '' });

function addManual(kind) {
    manual.value.push({
        kind,
        code: kind === 'deduction' ? 'DEDUCCION' : kind === 'pending_salary' ? 'SALARIO' : 'INDEMNIZACION',
        name: props.kinds[kind] ?? kind,
        detail: '',
        days: '',
        daily_rate: '',
        amount: '',
    });
}

function removeManual(index) {
    manual.value.splice(index, 1);
}

function recalculate() {
    recalcForm.transform((data) => ({
        ...data,
        reason_detail: data.reason_detail === '' ? null : data.reason_detail,
        lines: manual.value.map((l) => ({
            kind: l.kind,
            code: l.code || null,
            name: l.name,
            detail: l.detail === '' ? null : l.detail,
            days: l.days === '' ? null : l.days,
            daily_rate: l.daily_rate === '' ? null : l.daily_rate,
            amount: l.amount === '' ? null : l.amount,
        })),
    })).post(route('labor-settlements.calculate', props.settlement.id), { preserveScroll: true });
}

const approveForm = useForm({});
const postForm = useForm({ posting_date: '' });
const reopenForm = useForm({ reason: '' });
const voidForm = useForm({ reason: '', posting_date: '' });

const reopening = ref(false);
const voiding = ref(false);
const posting = ref(false);

function approve() {
    approveForm.post(route('labor-settlements.approve', props.settlement.id), { preserveScroll: true });
}

function post() {
    postForm.transform((data) => ({
        posting_date: data.posting_date === '' ? null : data.posting_date,
    })).post(route('labor-settlements.post', props.settlement.id), {
        preserveScroll: true, onSuccess: () => (posting.value = false),
    });
}

function reopen() {
    reopenForm.post(route('labor-settlements.reopen', props.settlement.id), {
        preserveScroll: true, onSuccess: () => (reopening.value = false),
    });
}

function voidIt() {
    voidForm.transform((data) => ({
        ...data,
        posting_date: data.posting_date === '' ? null : data.posting_date,
    })).post(route('labor-settlements.void', props.settlement.id), {
        preserveScroll: true, onSuccess: () => (voiding.value = false),
    });
}

function destroy() {
    confirmAction({
        title: 'Eliminar liquidación',
        message: 'La liquidación en borrador se elimina. No queda rastro porque nunca se contabilizó.',
        confirmLabel: 'Eliminar',
        danger: true,
        onConfirm: () => router.delete(route('labor-settlements.destroy', props.settlement.id)),
    });
}

const statusBadge = {
    draft: 'badge-neutral',
    approved: 'badge-warning',
    posted: 'badge-success',
    voided: 'badge-danger',
};

const earnings = computed(() => props.lines.filter((l) => ! l.is_deduction));
const deductions = computed(() => props.lines.filter((l) => l.is_deduction));

const warnings = computed(() => props.findings.filter((f) => f.level === 'warning'));
const infos = computed(() => props.findings.filter((f) => f.level === 'info'));
</script>

<template>
    <Head :title="`Liquidación — ${settlement.employee_name}`" />

    <AppLayout :title="`Liquidación — ${settlement.employee_name}`">
        <div class="view-toolbar">
            <Link :href="route('labor-settlements.index')" class="btn btn-ghost"><ArrowLeftIcon /> Liquidaciones</Link>
            <div class="view-actions">
                <Link :href="route('employees.show', settlement.employee_id)" class="btn btn-ghost"><UserIcon /> Ficha</Link>
                <template v-if="isDraft">
                    <button type="button" class="btn btn-ghost btn-danger-text" @click="destroy">Eliminar</button>
                    <button type="button" class="btn btn-ghost" @click="recalculate"><RefreshCwIcon /> Recalcular</button>
                    <button type="button" class="btn btn-primary" @click="approve"><CheckIcon /> Aprobar</button>
                </template>
                <template v-else-if="isApproved">
                    <button type="button" class="btn btn-ghost" @click="reopening = true"><RotateCcwIcon /> Devolver a borrador</button>
                    <button type="button" class="btn btn-primary" @click="posting = true"><BookOpenIcon /> Contabilizar</button>
                </template>
                <template v-else-if="isPosted">
                    <Link v-if="settlement.journal_entry_id"
                        :href="route('journal-entries.show', settlement.journal_entry_id)" class="btn btn-ghost">
                        Asiento {{ settlement.journal_entry_number }}
                    </Link>
                    <button type="button" class="btn btn-ghost btn-danger-text" @click="voiding = true">Anular</button>
                </template>
            </div>
        </div>

        <div v-if="page.props.errors?.payroll" class="flash flash-error">{{ page.props.errors.payroll }}</div>

        <div class="head-row">
            <span class="badge" :class="statusBadge[settlement.status]">{{ settlement.status_label }}</span>
            <span class="muted small">
                {{ settlement.reason_label }} · salida {{ settlement.termination_date }} ·
                {{ settlement.years_of_service }} año(s) de servicio
            </span>
        </div>

        <!--
            Las advertencias van arriba y sin doblar: una liquidación se firma,
            y lo que no se pudo comprobar tiene que estar dicho antes de la
            firma, no descubrirse en una demanda.
        -->
        <div v-for="(f, i) in warnings" :key="`w${i}`" class="flash flash-warning">{{ f.message }}</div>
        <div v-for="(f, i) in infos" :key="`i${i}`" class="flash flash-info">{{ f.message }}</div>

        <div class="grid-2">
            <section class="card">
                <div class="card-header"><h2>Bases del cálculo</h2></div>

                <dl class="facts">
                    <div>
                        <dt>Promedio mensual</dt>
                        <dd>{{ formatMoney(settlement.average_monthly_salary) }}</dd>
                    </div>
                    <div>
                        <dt>Valor del día (preaviso y cesantía)</dt>
                        <dd>{{ formatMoney(settlement.average_daily_salary) }}</dd>
                    </div>
                    <div>
                        <dt>Valor del día (vacaciones)</dt>
                        <dd>{{ formatMoney(settlement.vacation_daily_salary) }}</dd>
                    </div>
                    <div>
                        <dt>Salarios para el aguinaldo</dt>
                        <dd>{{ formatMoney(settlement.christmas_bonus_base) }}</dd>
                    </div>
                    <div>
                        <dt>Historial usado</dt>
                        <dd>
                            <template v-if="settlement.bases_from_history">
                                {{ settlement.history_months_found }} mes(es) de planilla
                            </template>
                            <template v-else>salario de la ficha</template>
                        </dd>
                    </div>
                    <div v-if="settlement.calculated_at">
                        <dt>Calculada</dt>
                        <dd>{{ settlement.calculated_at }}</dd>
                    </div>
                </dl>

                <p class="hint small">
                    El promedio es de lo <strong>realmente devengado</strong> —incluye horas extra, comisiones y
                    recargos— porque es lo que manda el art. 30. Liquidar con el salario de la ficha paga de
                    menos a cualquiera que haya hecho una hora extra en el semestre.
                </p>
            </section>

            <section class="card">
                <div class="card-header"><h2>Totales</h2></div>

                <div class="totals">
                    <div class="total-row">
                        <span>Bruto liquidado</span>
                        <span>{{ formatMoney(settlement.total_gross) }}</span>
                    </div>
                    <div class="total-row minus">
                        <span>Cargas sociales obreras</span>
                        <span>− {{ formatMoney(settlement.total_ccss) }}</span>
                    </div>
                    <div class="total-row minus">
                        <span>Impuesto al salario</span>
                        <span>− {{ formatMoney(settlement.total_income_tax) }}</span>
                    </div>
                    <div class="total-row minus">
                        <span>Otras deducciones</span>
                        <span>− {{ formatMoney(settlement.total_other_deductions) }}</span>
                    </div>
                    <div class="total-row final">
                        <span>Neto a pagar</span>
                        <span>{{ formatMoney(settlement.total_net) }}</span>
                    </div>
                </div>

                <p class="hint small">
                    Las cargas se cobran <strong>solo</strong> sobre las vacaciones y los salarios pendientes.
                    La cesantía, el preaviso y el aguinaldo están exentos: rebajarle el 10,83% a la cesantía le
                    quita al trabajador plata que la ley no permite rebajar.
                </p>
            </section>
        </div>

        <section class="card">
            <div class="card-header"><h2>Detalle de la liquidación</h2></div>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Extremo</th>
                            <th class="num">Días</th>
                            <th class="num">Valor del día</th>
                            <th class="num">Monto</th>
                            <th>Cargas</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="l in earnings" :key="l.id">
                            <td>
                                {{ l.name }}
                                <!-- El detalle es lo que el trabajador lee y firma. -->
                                <span class="block muted small">{{ l.detail ?? '—' }}</span>
                            </td>
                            <td data-label="Días" class="num">{{ l.days ?? '—' }}</td>
                            <td data-label="Valor del día" class="num">{{ l.daily_rate ? formatMoney(l.daily_rate) : '—' }}</td>
                            <td data-label="Monto" class="num">{{ formatMoney(l.amount) }}</td>
                            <td data-label="Cargas" class="small">{{ l.subject_to_ccss ? 'sí' : 'exento' }}</td>
                        </tr>
                        <tr v-for="l in deductions" :key="`d${l.id}`" class="deduction">
                            <td>
                                {{ l.name }}
                                <span class="block muted small">{{ l.detail ?? '—' }}</span>
                            </td>
                            <td data-label="Días" class="num">—</td>
                            <td data-label="Valor del día" class="num">—</td>
                            <td data-label="Monto" class="num">− {{ formatMoney(l.amount) }}</td>
                            <td data-label="Cargas" class="small">—</td>
                        </tr>
                        <tr v-if="! lines.length">
                            <td colspan="5" class="muted empty-row">Sin renglones: hay que recalcular.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section v-if="isDraft" class="card">
            <div class="card-header manual-header">
                <h2>Renglones manuales</h2>
                <div class="manual-add">
                    <button type="button" class="btn btn-ghost" @click="addManual('pending_salary')">
                        <PlusIcon /> Salarios pendientes
                    </button>
                    <button type="button" class="btn btn-ghost" @click="addManual('indemnity')">
                        <PlusIcon /> Indemnización
                    </button>
                    <button type="button" class="btn btn-ghost" @click="addManual('deduction')">
                        <PlusIcon /> Deducción
                    </button>
                </div>
            </div>

            <p class="hint small">
                Los salarios pendientes no se derivan solos a propósito: los días sin pagar dependen de hasta
                cuándo llegó la última planilla, y adivinarlo produciría un doble pago el día que la planilla del
                mes ya incluyó la fracción. Si se indican días sin tarifa, se usa el valor del día de la
                liquidación.
            </p>

            <div v-if="manual.length" class="table-responsive capture-grid">
                <table>
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Detalle</th>
                            <th class="num">Días</th>
                            <th class="num">Valor del día</th>
                            <th class="num">Monto</th>
                            <th><span class="sr-only">Quitar</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(l, i) in manual" :key="i">
                            <td>
                                <span class="block muted small">{{ kinds[l.kind] ?? l.kind }}</span>
                                <input v-model="l.name" type="text" maxlength="255" required :aria-label="`Nombre del renglón ${i + 1}`">
                            </td>
                            <td data-label="Detalle"><input v-model="l.detail" type="text" maxlength="255" :aria-label="`Detalle del renglón ${i + 1}`"></td>
                            <td data-label="Días"><input v-model="l.days" type="number" step="0.0001" min="0" class="num-input" :aria-label="`Días del renglón ${i + 1}`"></td>
                            <td data-label="Valor del día"><input v-model="l.daily_rate" type="number" step="0.01" min="0" class="num-input" :aria-label="`Valor del día del renglón ${i + 1}`"></td>
                            <td data-label="Monto"><input v-model="l.amount" type="number" step="0.01" min="0" class="num-input" :aria-label="`Monto del renglón ${i + 1}`"></td>
                            <td data-label="">
                                <button type="button" class="btn btn-ghost" :aria-label="`Quitar el renglón ${i + 1}`" @click="removeManual(i)">
                                    <XIcon />
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="form-grid recalc-grid">
                <div class="field">
                    <label for="recalc-reason">Causal</label>
                    <select id="recalc-reason" v-model="recalcForm.reason">
                        <option v-for="(label, value) in reasons" :key="value" :value="value">{{ label }}</option>
                    </select>
                    <span class="muted small">Cambiarla cambia qué extremos se pagan.</span>
                </div>
                <div class="field">
                    <label for="recalc-detail">Hechos de la salida</label>
                    <textarea id="recalc-detail" v-model="recalcForm.reason_detail" rows="2"></textarea>
                </div>
            </div>

            <div class="form-actions">
                <button type="button" class="btn btn-primary" :disabled="recalcForm.processing" @click="recalculate">
                    <RefreshCwIcon /> Recalcular
                </button>
            </div>
        </section>

        <p v-if="settlement.notes" class="hint">{{ settlement.notes }}</p>

        <DetailModal :open="posting" title="Contabilizar la liquidación" @close="posting = false">
            <form id="settle-post-form" @submit.prevent="post">
                <p class="hint small">
                    El asiento <strong>cancela las provisiones</strong> de aguinaldo, vacaciones y cesantía —el
                    gasto ya se reconoció mes a mes— y lleva al gasto solo el preaviso. El neto queda como
                    pasivo: el pago al banco es otro asiento.
                    Además se descargan los días de vacaciones pagados y el trabajador queda inactivo con su
                    fecha de salida.
                </p>

                <div class="field">
                    <label for="settle-post-date">Fecha del asiento</label>
                    <input id="settle-post-date" v-model="postForm.posting_date" type="date">
                    <span class="muted small">En blanco usa la fecha de salida.</span>
                </div>
            </form>

            <template #actions>
                <button type="button" class="btn btn-ghost" @click="posting = false">Cancelar</button>
                <button type="submit" form="settle-post-form" class="btn btn-primary" :disabled="postForm.processing">Contabilizar</button>
            </template>
        </DetailModal>

        <DetailModal :open="reopening" title="Devolver a borrador" @close="reopening = false">
            <form id="settle-reopen-form" @submit.prevent="reopen">
                <p class="hint small">
                    Todavía no hay asiento, así que alcanza con quitar la aprobación. Queda constancia de quién
                    la quitó y por qué.
                </p>

                <div class="field">
                    <label for="settle-reopen-reason">Motivo</label>
                    <textarea id="settle-reopen-reason" v-model="reopenForm.reason" rows="3" required minlength="5"></textarea>
                    <span v-if="reopenForm.errors.reason" class="error">{{ reopenForm.errors.reason }}</span>
                </div>
            </form>

            <template #actions>
                <button type="button" class="btn btn-ghost" @click="reopening = false">Cancelar</button>
                <button type="submit" form="settle-reopen-form" class="btn btn-primary" :disabled="reopenForm.processing">
                    Devolver a borrador
                </button>
            </template>
        </DetailModal>

        <DetailModal :open="voiding" title="Anular la liquidación" @close="voiding = false">
            <form id="settle-void-form" @submit.prevent="voidIt">
                <p class="hint small">
                    No se borra: se contabiliza un <strong>asiento de reversión</strong>. Se devuelven los días
                    de vacaciones que se liquidaron y el trabajador vuelve a estar activo.
                </p>

                <div class="field">
                    <label for="settle-void-reason">Motivo</label>
                    <textarea id="settle-void-reason" v-model="voidForm.reason" rows="3" required minlength="5"></textarea>
                    <span v-if="voidForm.errors.reason" class="error">{{ voidForm.errors.reason }}</span>
                </div>

                <div class="field">
                    <label for="settle-void-date">Fecha del asiento de reversión</label>
                    <input id="settle-void-date" v-model="voidForm.posting_date" type="date">
                    <span class="muted small">En blanco usa hoy.</span>
                </div>
            </form>

            <template #actions>
                <button type="button" class="btn btn-ghost" @click="voiding = false">Cancelar</button>
                <button type="submit" form="settle-void-form" class="btn btn-danger" :disabled="voidForm.processing">
                    Anular
                </button>
            </template>
        </DetailModal>
    </AppLayout>
</template>

<style scoped>
.head-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem 0.75rem;
    margin-bottom: 1rem;
}

.grid-2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 20rem), 1fr)); gap: 1rem; margin-bottom: 1rem; }

.facts { margin: 0; }
.facts > div { display: flex; justify-content: space-between; gap: 1rem; padding: 0.3rem 0; border-bottom: 1px solid var(--color-border); }
.facts dt { color: var(--color-text-muted); font-size: 0.8rem; }
.facts dd { margin: 0; font-variant-numeric: tabular-nums; text-align: right; }

.totals { display: flex; flex-direction: column; }
.total-row { display: flex; justify-content: space-between; gap: 1rem; padding: 0.3rem 0; font-variant-numeric: tabular-nums; }
.total-row.minus { color: var(--color-text-muted); }
.total-row.final { border-top: 2px solid var(--color-text); margin-top: 0.3rem; padding-top: 0.5rem; font-weight: 600; font-size: 1.05rem; }

table { font-size: 0.85rem; }
.num { font-variant-numeric: tabular-nums; }
.block { display: block; }
tr.deduction td { color: var(--color-danger); }

.num-input { width: 6.5rem; text-align: right; }
.capture-grid td input:not(.num-input) { width: 100%; }

.manual-header { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.5rem; }
.manual-add { display: flex; flex-wrap: wrap; gap: 0.4rem; }
.recalc-grid { margin-top: 0.75rem; }

@media screen and (max-width: 1024px) {
    .num-input { width: 100%; }
}
</style>
