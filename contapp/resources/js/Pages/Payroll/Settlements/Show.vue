<script setup>
import { Head, Link, useForm, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import { formatMoney } from '../../../Utils/money';

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
    if (! confirm('¿Eliminar esta liquidación en borrador? No queda rastro porque nunca se contabilizó.')) return;

    router.delete(route('labor-settlements.destroy', props.settlement.id));
}

const earnings = computed(() => props.lines.filter((l) => ! l.is_deduction));
const deductions = computed(() => props.lines.filter((l) => l.is_deduction));

const warnings = computed(() => props.findings.filter((f) => f.level === 'warning'));
const infos = computed(() => props.findings.filter((f) => f.level === 'info'));
</script>

<template>
    <Head :title="`Liquidación — ${settlement.employee_name}`" />

    <AppLayout :title="`Liquidación — ${settlement.employee_name}`">
        <template #actions>
            <Link :href="route('labor-settlements.index')" class="btn btn-ghost">Liquidaciones</Link>
            <Link :href="route('employees.show', settlement.employee_id)" class="btn btn-ghost">Ficha</Link>
        </template>

        <div v-if="page.props.errors?.payroll" class="flash flash-error">{{ page.props.errors.payroll }}</div>

        <div class="head-row">
            <div>
                <span class="badge" :class="`badge-${settlement.status}`">{{ settlement.status_label }}</span>
                <span class="muted small">
                    {{ settlement.reason_label }} · salida {{ settlement.termination_date }} ·
                    {{ settlement.years_of_service }} año(s) de servicio
                </span>
            </div>
            <div class="head-actions">
                <template v-if="isDraft">
                    <button type="button" class="btn btn-ghost" @click="recalculate">Recalcular</button>
                    <button type="button" class="btn btn-primary" @click="approve">Aprobar</button>
                    <button type="button" class="btn btn-ghost danger" @click="destroy">Eliminar</button>
                </template>
                <template v-else-if="isApproved">
                    <button type="button" class="btn btn-ghost" @click="reopening = true">Devolver a borrador</button>
                    <button type="button" class="btn btn-primary" @click="posting = true">Contabilizar</button>
                </template>
                <template v-else-if="isPosted">
                    <Link v-if="settlement.journal_entry_id"
                        :href="route('journal-entries.show', settlement.journal_entry_id)" class="btn btn-ghost">
                        Asiento {{ settlement.journal_entry_number }}
                    </Link>
                    <button type="button" class="btn btn-ghost danger" @click="voiding = true">Anular</button>
                </template>
            </div>
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

            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Extremo</th>
                            <th>Cómo se llegó al monto</th>
                            <th class="right">Días</th>
                            <th class="right">Valor del día</th>
                            <th class="right">Monto</th>
                            <th>Cargas</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="l in earnings" :key="l.id">
                            <td>{{ l.name }}</td>
                            <!-- El detalle es lo que el trabajador lee y firma. -->
                            <td class="muted small">{{ l.detail ?? '—' }}</td>
                            <td class="right">{{ l.days ?? '—' }}</td>
                            <td class="right">{{ l.daily_rate ? formatMoney(l.daily_rate) : '—' }}</td>
                            <td class="right">{{ formatMoney(l.amount) }}</td>
                            <td class="small">{{ l.subject_to_ccss ? 'sí' : 'exento' }}</td>
                        </tr>
                        <tr v-for="l in deductions" :key="`d${l.id}`" class="deduction">
                            <td>{{ l.name }}</td>
                            <td class="muted small">{{ l.detail ?? '—' }}</td>
                            <td class="right">—</td>
                            <td class="right">—</td>
                            <td class="right">− {{ formatMoney(l.amount) }}</td>
                            <td class="small">—</td>
                        </tr>
                        <tr v-if="! lines.length">
                            <td colspan="6" class="muted center">Sin renglones: hay que recalcular.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section v-if="isDraft" class="card">
            <div class="card-header">
                <h2>Renglones manuales</h2>
                <button type="button" class="btn btn-ghost btn-sm" @click="addManual('pending_salary')">
                    + Salarios pendientes
                </button>
                <button type="button" class="btn btn-ghost btn-sm" @click="addManual('indemnity')">
                    + Indemnización
                </button>
                <button type="button" class="btn btn-ghost btn-sm" @click="addManual('deduction')">
                    + Deducción
                </button>
            </div>

            <p class="hint small">
                Los salarios pendientes no se derivan solos a propósito: los días sin pagar dependen de hasta
                cuándo llegó la última planilla, y adivinarlo produciría un doble pago el día que la planilla del
                mes ya incluyó la fracción. Si se indican días sin tarifa, se usa el valor del día de la
                liquidación.
            </p>

            <div v-if="manual.length" class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Tipo</th>
                            <th>Nombre</th>
                            <th>Detalle</th>
                            <th class="right">Días</th>
                            <th class="right">Valor del día</th>
                            <th class="right">Monto</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(l, i) in manual" :key="i">
                            <td class="small">{{ kinds[l.kind] ?? l.kind }}</td>
                            <td><input v-model="l.name" type="text" maxlength="255" required></td>
                            <td><input v-model="l.detail" type="text" maxlength="255"></td>
                            <td><input v-model="l.days" type="number" step="0.0001" min="0" class="num-input"></td>
                            <td><input v-model="l.daily_rate" type="number" step="0.01" min="0" class="num-input"></td>
                            <td><input v-model="l.amount" type="number" step="0.01" min="0" class="num-input"></td>
                            <td class="row-actions">
                                <button type="button" class="btn btn-ghost btn-sm danger" @click="removeManual(i)">
                                    Quitar
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="field-row">
                <div class="field">
                    <label>Causal</label>
                    <select v-model="recalcForm.reason">
                        <option v-for="(label, value) in reasons" :key="value" :value="value">{{ label }}</option>
                    </select>
                    <span class="hint small">Cambiarla cambia qué extremos se pagan.</span>
                </div>
                <div class="field">
                    <label>Hechos de la salida</label>
                    <textarea v-model="recalcForm.reason_detail" rows="2"></textarea>
                </div>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn btn-primary" :disabled="recalcForm.processing" @click="recalculate">
                    Recalcular
                </button>
            </div>
        </section>

        <p v-if="settlement.notes" class="hint">{{ settlement.notes }}</p>

        <div v-if="posting" class="modal-backdrop" @click.self="posting = false">
            <form class="modal card" @submit.prevent="post">
                <h2>Contabilizar la liquidación</h2>

                <p class="hint small">
                    El asiento <strong>cancela las provisiones</strong> de aguinaldo, vacaciones y cesantía —el
                    gasto ya se reconoció mes a mes— y lleva al gasto solo el preaviso. El neto queda como
                    pasivo: el pago al banco es otro asiento.
                    Además se descargan los días de vacaciones pagados y el trabajador queda inactivo con su
                    fecha de salida.
                </p>

                <div class="field">
                    <label>Fecha del asiento</label>
                    <input v-model="postForm.posting_date" type="date">
                    <span class="hint small">En blanco usa la fecha de salida.</span>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-ghost" @click="posting = false">Cancelar</button>
                    <button type="submit" class="btn btn-primary" :disabled="postForm.processing">Contabilizar</button>
                </div>
            </form>
        </div>

        <div v-if="reopening" class="modal-backdrop" @click.self="reopening = false">
            <form class="modal card" @submit.prevent="reopen">
                <h2>Devolver a borrador</h2>

                <p class="hint small">
                    Todavía no hay asiento, así que alcanza con quitar la aprobación. Queda constancia de quién
                    la quitó y por qué.
                </p>

                <div class="field">
                    <label>Motivo</label>
                    <textarea v-model="reopenForm.reason" rows="3" required minlength="5"></textarea>
                    <span v-if="reopenForm.errors.reason" class="error">{{ reopenForm.errors.reason }}</span>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-ghost" @click="reopening = false">Cancelar</button>
                    <button type="submit" class="btn btn-primary" :disabled="reopenForm.processing">
                        Devolver a borrador
                    </button>
                </div>
            </form>
        </div>

        <div v-if="voiding" class="modal-backdrop" @click.self="voiding = false">
            <form class="modal card" @submit.prevent="voidIt">
                <h2>Anular la liquidación</h2>

                <p class="hint small">
                    No se borra: se contabiliza un <strong>asiento de reversión</strong>. Se devuelven los días
                    de vacaciones que se liquidaron y el trabajador vuelve a estar activo.
                </p>

                <div class="field">
                    <label>Motivo</label>
                    <textarea v-model="voidForm.reason" rows="3" required minlength="5"></textarea>
                    <span v-if="voidForm.errors.reason" class="error">{{ voidForm.errors.reason }}</span>
                </div>

                <div class="field">
                    <label>Fecha del asiento de reversión</label>
                    <input v-model="voidForm.posting_date" type="date">
                    <span class="hint small">En blanco usa hoy.</span>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-ghost" @click="voiding = false">Cancelar</button>
                    <button type="submit" class="btn btn-primary danger" :disabled="voidForm.processing">
                        Anular
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>

<style scoped>
.head-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    margin-bottom: 1rem;
}

.head-actions { display: flex; flex-wrap: wrap; gap: 0.4rem; }

.grid-2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(20rem, 1fr)); gap: 1rem; }

.facts { margin: 0; }
.facts > div { display: flex; justify-content: space-between; gap: 1rem; padding: 0.3rem 0; border-bottom: 1px solid #f3f4f6; }
.facts dt { color: #6b7280; font-size: 0.8rem; }
.facts dd { margin: 0; font-variant-numeric: tabular-nums; }

.totals { display: flex; flex-direction: column; }
.total-row { display: flex; justify-content: space-between; padding: 0.3rem 0; font-variant-numeric: tabular-nums; }
.total-row.minus { color: #6b7280; }
.total-row.final { border-top: 2px solid #111827; margin-top: 0.3rem; padding-top: 0.5rem; font-weight: 600; font-size: 1.05rem; }

tr.deduction td { color: #b91c1c; }

.num-input { width: 8rem; text-align: right; }

.badge {
    display: inline-block;
    padding: 0.1rem 0.45rem;
    border-radius: 999px;
    font-size: 0.72rem;
    border: 1px solid #d1d5db;
    margin-right: 0.5rem;
}

.badge-draft { background: #f3f4f6; color: #374151; }
.badge-approved { background: #eff6ff; border-color: #bfdbfe; color: #1d4ed8; }
.badge-posted { background: #ecfdf5; border-color: #a7f3d0; color: #047857; }
.badge-voided { background: #fef2f2; border-color: #fecaca; color: #b91c1c; }

.danger { color: #b91c1c; }
.center { text-align: center; }
</style>
