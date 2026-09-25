<script setup>
import { Head, Link, useForm, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import { formatMoney } from '../../../Utils/money';

const props = defineProps({
    period: { type: Object, required: true },
    entries: { type: Array, default: () => [] },
    totals: { type: Object, required: true },
    concepts: { type: Array, default: () => [] },
    employees: { type: Array, default: () => [] },
    inputs: { type: Array, default: () => [] },
    readiness: { type: Object, default: null },
});

const page = usePage();

const inputForm = useForm({
    employee_id: '',
    payroll_concept_id: '',
    amount: '',
    quantity: '',
    notes: '',
});

const selectedConcept = computed(
    () => props.concepts.find((c) => c.id === Number(inputForm.payroll_concept_id)) ?? null
);

const byHours = computed(() => selectedConcept.value?.calculation === 'hours');

function submitInput() {
    inputForm.transform((data) => ({
        ...data,
        amount: data.amount === '' ? null : data.amount,
        quantity: data.quantity === '' ? null : data.quantity,
        notes: data.notes === '' ? null : data.notes,
    })).post(route('payroll-periods.inputs.store', props.period.id), {
        preserveScroll: true,
        onSuccess: () => inputForm.reset('amount', 'quantity', 'notes'),
    });
}

function removeInput(input) {
    router.delete(route('payroll-periods.inputs.destroy', [props.period.id, input.id]), { preserveScroll: true });
}

const calculating = ref(false);

function calculate() {
    calculating.value = true;
    router.post(route('payroll-periods.calculate', props.period.id), {}, {
        preserveScroll: true,
        onFinish: () => (calculating.value = false),
    });
}

function approve() {
    if (! confirm('¿Aprobar esta planilla? Después de aprobarla se puede contabilizar y generar el archivo de pago.')) return;

    router.post(route('payroll-periods.approve', props.period.id), {}, { preserveScroll: true });
}

const posting = ref(false);
const postingDate = ref('');

function post() {
    router.post(route('payroll-periods.post', props.period.id), {
        posting_date: postingDate.value === '' ? null : postingDate.value,
    }, {
        preserveScroll: true,
        onSuccess: () => (posting.value = false),
    });
}

// Con errores de configuración el botón no se ofrece: un empleado sin salario
// no hace fallar el cálculo, produce una boleta en cero que se pierde entre
// cincuenta. Las advertencias no bloquean — la decisión de seguir es del
// usuario, no del sistema.
const canCalculate = computed(
    () => props.period.is_recalculable && (props.readiness === null || props.readiness.ok)
);

const showAllFindings = ref(false);

const findings = computed(() => {
    if (! props.readiness) return [];

    // Los errores primero: son los que hay que resolver para poder calcular.
    const order = { error: 0, warning: 1 };

    return [...props.readiness.findings].sort((a, b) => order[a.severity] - order[b.severity]);
});

const visibleFindings = computed(
    () => (showAllFindings.value ? findings.value : findings.value.slice(0, 6))
);

function findingHref(finding) {
    return finding.route_parameter
        ? route(finding.route, finding.route_parameter)
        : route(finding.route);
}
const canApprove = computed(() => props.period.status === 'calculated');
const canPost = computed(() => ['calculated', 'approved'].includes(props.period.status));
const canPay = computed(() => ['approved', 'posted', 'closed'].includes(props.period.status));

const statusClass = {
    open: 'badge-neutral',
    calculated: 'badge-warning',
    approved: 'badge-warning',
    posted: 'badge-success',
    closed: 'badge-neutral',
};

// Quién cobra por transferencia y no tiene cuenta lo reporta la verificación
// previa, con el enlace para corregirlo: tenerlo también acá diría lo mismo
// dos veces y sin decir dónde se arregla.
</script>

<template>
    <Head :title="`Planilla ${period.name}`" />

    <AppLayout :title="`Planilla ${period.name}`">
        <template #actions>
            <Link :href="route('payroll-periods.index')" class="btn btn-ghost">← Períodos</Link>
            <a v-if="entries.length" :href="route('payroll-periods.export', period.id)" class="btn btn-ghost">⤓ Exportar XLSX</a>
            <a v-if="canPay" :href="route('payroll-periods.bank-file', period.id)" class="btn btn-ghost">⤓ Archivo de pago</a>
        </template>

        <div v-if="page.props.errors?.payroll" class="flash flash-error">{{ page.props.errors.payroll }}</div>

        <div class="header-card card">
            <div class="header-main">
                <span class="badge" :class="statusClass[period.status]">{{ period.status_label }}</span>
                <span class="muted small">
                    {{ period.frequency_label }} · del {{ period.start_date }} al {{ period.end_date }} ·
                    pago {{ period.payment_date }}
                </span>
                <span v-if="period.calculated_at" class="muted small">Calculada {{ period.calculated_at }}</span>
                <Link v-if="period.journal_entry_id" :href="route('journal-entries.show', period.journal_entry_id)" class="small">
                    Asiento #{{ period.journal_entry_id }}
                </Link>
            </div>

            <div class="header-actions">
                <button type="button" class="btn btn-primary" :disabled="!canCalculate || calculating" @click="calculate">
                    {{ calculating ? 'Calculando…' : (entries.length ? 'Recalcular' : 'Calcular planilla') }}
                </button>
                <button type="button" class="btn btn-ghost" :disabled="!canApprove" @click="approve">Aprobar</button>
                <button type="button" class="btn btn-ghost" :disabled="!canPost" @click="posting = true">Contabilizar</button>
            </div>
        </div>

        <section v-if="readiness && findings.length" class="card readiness">
            <div class="card-header">
                <h3>
                    Verificación previa
                    <span v-if="readiness.errors" class="badge badge-danger">{{ readiness.errors }} error(es)</span>
                    <span v-if="readiness.warnings" class="badge badge-warning">{{ readiness.warnings }} advertencia(s)</span>
                </h3>
                <button
                    v-if="findings.length > 6"
                    type="button" class="btn btn-ghost btn-sm"
                    @click="showAllFindings = !showAllFindings"
                >{{ showAllFindings ? 'Ver menos' : `Ver los ${findings.length}` }}</button>
            </div>

            <p class="hint small">
                Una planilla mal configurada no falla: produce números. Un empleado sin salario sale con neto cero
                y se pierde entre cincuenta boletas. Los <strong>errores</strong> impiden calcular; las
                <strong>advertencias</strong> dejan una planilla correcta con una consecuencia después —un archivo
                de pago incompleto, un renglón que la Caja rechaza— y seguir con ellas es tu decisión.
            </p>

            <ul class="finding-list">
                <li v-for="(f, i) in visibleFindings" :key="i" :class="f.severity">
                    <span class="finding-mark">{{ f.severity === 'error' ? '✕' : '!' }}</span>
                    <span class="finding-body">
                        <strong>{{ f.title }}</strong>
                        <span class="muted small">{{ f.detail }}</span>
                    </span>
                    <a :href="findingHref(f)" class="btn btn-ghost btn-sm">Corregir</a>
                </li>
            </ul>
        </section>

        <p v-else-if="readiness && !findings.length" class="flash flash-success">
            Verificación previa sin hallazgos: la configuración y las fichas están completas.
        </p>

        <section v-if="period.is_recalculable" class="card input-card">
            <div class="card-header">
                <h3>Movimientos del período</h3>
                <span class="muted small">{{ inputs.length }} registrado(s)</span>
            </div>

            <p class="hint small">
                Acá van horas extra, bonos y rebajos puntuales. El salario base sale de la ficha y las cargas
                sociales las calcula el motor: no se digitan, porque poder digitarlas permitiría cuadrar una
                planilla a mano y romper la conciliación con la Caja.
            </p>

            <form class="input-form" @submit.prevent="submitInput">
                <div class="field">
                    <label>Trabajador</label>
                    <select v-model="inputForm.employee_id" required>
                        <option value="">Elegí</option>
                        <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.code }} — {{ e.name }}</option>
                    </select>
                </div>

                <div class="field">
                    <label>Concepto</label>
                    <select v-model="inputForm.payroll_concept_id" required>
                        <option value="">Elegí</option>
                        <option v-for="c in concepts" :key="c.id" :value="c.id">
                            {{ c.code }} — {{ c.name }}{{ c.type === 'deduction' ? ' (rebajo)' : '' }}
                        </option>
                    </select>
                </div>

                <div v-if="byHours" class="field">
                    <label>Horas</label>
                    <input v-model="inputForm.quantity" type="number" step="0.01" min="0" required>
                    <span class="hint small">Se paga al factor {{ selectedConcept?.factor }} sobre la hora ordinaria.</span>
                    <span v-if="inputForm.errors.quantity" class="error">{{ inputForm.errors.quantity }}</span>
                </div>

                <div v-else class="field">
                    <label>Monto</label>
                    <input v-model="inputForm.amount" type="number" step="0.01" required>
                    <span v-if="inputForm.errors.amount" class="error">{{ inputForm.errors.amount }}</span>
                </div>

                <div class="field grow">
                    <label>Referencia</label>
                    <input v-model="inputForm.notes" type="text" maxlength="255" placeholder="De dónde salió el dato">
                </div>

                <button type="submit" class="btn btn-primary" :disabled="inputForm.processing">Agregar</button>
            </form>

            <div v-if="inputs.length" class="table-scroll compact">
                <table>
                    <thead>
                        <tr>
                            <th>Trabajador</th>
                            <th>Concepto</th>
                            <th class="right">Cantidad</th>
                            <th class="right">Monto</th>
                            <th>Referencia</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="i in inputs" :key="i.id">
                            <td class="small">{{ i.employee_code }}</td>
                            <td class="small">{{ i.concept_code }} — {{ i.concept_name }}</td>
                            <td class="right num small">{{ i.quantity ?? '—' }}</td>
                            <td class="right num small">{{ i.amount === null ? '—' : formatMoney(i.amount) }}</td>
                            <td class="muted small">{{ i.notes ?? '—' }}</td>
                            <td class="row-actions">
                                <button type="button" class="btn btn-ghost btn-sm" @click="removeInput(i)">Quitar</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <div v-if="entries.length" class="stat-row">
            <div class="stat">
                <span class="stat-label">Salario bruto</span>
                <span class="stat-value">{{ formatMoney(totals.total_earnings) }}</span>
            </div>
            <div class="stat">
                <span class="stat-label">Deducciones</span>
                <span class="stat-value">{{ formatMoney(totals.total_deductions) }}</span>
            </div>
            <div class="stat accent">
                <span class="stat-label">Neto a pagar</span>
                <span class="stat-value">{{ formatMoney(totals.net_pay) }}</span>
            </div>
            <div class="stat">
                <span class="stat-label">Cargas patronales</span>
                <span class="stat-value">{{ formatMoney(totals.total_employer_contributions) }}</span>
                <span class="stat-note">no rebaja al trabajador</span>
            </div>
            <div class="stat">
                <span class="stat-label">Provisiones</span>
                <span class="stat-value">{{ formatMoney(totals.total_provisions) }}</span>
                <span class="stat-note">aguinaldo, vacaciones, cesantía</span>
            </div>
            <div class="stat strong">
                <span class="stat-label">Costo total para la empresa</span>
                <span class="stat-value">{{ formatMoney(totals.employer_cost) }}</span>
                <span class="stat-note">bruto + cargas + provisiones</span>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3>Boletas</h3>
                <span class="muted small">{{ entries.length }} trabajador(es)</span>
            </div>

            <div class="table-scroll freeze-2">
                <table>
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Trabajador</th>
                            <th>C. costo</th>
                            <th class="right">Días</th>
                            <th class="right">Bruto</th>
                            <th class="right">Base CCSS</th>
                            <th class="right">Cargas obreras</th>
                            <th class="right">Impuesto</th>
                            <th class="right">Otros rebajos</th>
                            <th class="right">Neto</th>
                            <th class="right">Costo empresa</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="e in entries" :key="e.id">
                            <td class="num">{{ e.employee_code }}</td>
                            <td>{{ e.employee_name }}</td>
                            <td class="muted small">{{ e.cost_center ?? '—' }}</td>
                            <td class="right num">{{ e.days_worked }}</td>
                            <td class="right num">{{ formatMoney(e.total_earnings) }}</td>
                            <td class="right num muted">{{ formatMoney(e.ccss_base) }}</td>
                            <td class="right num">{{ formatMoney(e.total_employee_contributions) }}</td>
                            <td class="right num">{{ formatMoney(e.income_tax) }}</td>
                            <td class="right num">{{ formatMoney(e.total_other_deductions) }}</td>
                            <td class="right num strong">{{ formatMoney(e.net_pay) }}</td>
                            <td class="right num muted">{{ formatMoney(e.employer_cost) }}</td>
                            <td class="row-actions">
                                <Link :href="route('payslips.show', e.id)" class="btn btn-ghost btn-sm">Comprobante</Link>
                            </td>
                        </tr>
                        <tr v-if="!entries.length">
                            <td colspan="12" class="muted empty-row">
                                Todavía no se ha calculado. Registrá los movimientos del período y presioná
                                «Calcular planilla».
                            </td>
                        </tr>
                    </tbody>
                    <tfoot v-if="entries.length">
                        <tr>
                            <td colspan="4">TOTALES</td>
                            <td class="right num">{{ formatMoney(totals.total_earnings) }}</td>
                            <td class="right num">{{ formatMoney(totals.ccss_base) }}</td>
                            <td class="right num">{{ formatMoney(totals.total_employee_contributions) }}</td>
                            <td class="right num">{{ formatMoney(totals.income_tax) }}</td>
                            <td class="right num">{{ formatMoney(totals.total_other_deductions) }}</td>
                            <td class="right num strong">{{ formatMoney(totals.net_pay) }}</td>
                            <td class="right num">{{ formatMoney(totals.employer_cost) }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <div v-if="posting" class="modal-backdrop" @click.self="posting = false">
            <form class="modal card" @submit.prevent="post">
                <h2>Contabilizar la planilla</h2>

                <p class="hint small">
                    El asiento carga al gasto el <strong>salario bruto</strong> —no el neto—, abona las
                    retenciones a su pasivo y deja el neto en «planilla por pagar». El banco se toca en el
                    asiento de pago, que es otro hecho.
                </p>

                <div class="field">
                    <label>Fecha de contabilización</label>
                    <input v-model="postingDate" type="date">
                    <span class="hint small">Vacío usa la fecha de pago del período ({{ period.payment_date }}).</span>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-ghost" @click="posting = false">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Contabilizar</button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>

<style scoped>
.header-card {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
    padding: 0.9rem 1.1rem;
    margin-bottom: 1rem;
}

.header-main { display: flex; align-items: center; gap: 0.8rem; flex-wrap: wrap; }
.header-actions { display: flex; gap: 0.5rem; flex-wrap: wrap; }

.input-card { margin-bottom: 1rem; }
.input-card h3 { margin: 0; font-size: 0.9rem; }
.input-card .hint { padding: 0 1.1rem; }

.input-form {
    display: flex;
    gap: 0.7rem;
    align-items: flex-end;
    flex-wrap: wrap;
    padding: 0 1.1rem 1rem;
}

.input-form .field { margin-bottom: 0; min-width: 11rem; }
.input-form .field.grow { flex: 1; min-width: 14rem; }

.stat-row {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    margin-bottom: 1rem;
}

.stat {
    display: flex;
    flex-direction: column;
    padding: 0.7rem 1.1rem;
    border: 1px solid var(--color-border);
    border-radius: 0.5rem;
    background: var(--color-surface);
    min-width: 11rem;
    flex: 1;
}

.stat.accent { border-color: var(--color-primary); }
.stat.strong { background: var(--color-surface-alt); }

.stat-label {
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--color-text-muted);
}

.stat-value { font-size: 1.15rem; font-weight: 600; font-variant-numeric: tabular-nums; }
.stat-note { font-size: 0.68rem; color: var(--color-text-muted); }

.card-header h3 { margin: 0; font-size: 0.9rem; display: flex; align-items: center; gap: 0.5rem; }
.table-scroll.compact { max-height: 18rem; }

/* ── Verificación previa ─────────────────────────────────────────────── */

.readiness { margin-bottom: 1rem; }
.readiness .hint { padding: 0 1.1rem; }

.finding-list {
    list-style: none;
    margin: 0;
    padding: 0 1.1rem 1rem;
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
}

.finding-list li {
    display: flex;
    align-items: flex-start;
    gap: 0.6rem;
    padding: 0.5rem 0.7rem;
    border-radius: var(--radius-sm);
    background: var(--color-surface-alt);
    border-left: 3px solid transparent;
}

/* El error se distingue del aviso por color Y por símbolo: solo por color no
   lo distingue quien no ve bien los rojos. */
.finding-list li.error {
    border-left-color: var(--color-danger);
    background: var(--color-danger-soft);
}

.finding-list li.warning { border-left-color: #d08a55; }

.finding-mark {
    flex-shrink: 0;
    width: 1.1rem;
    text-align: center;
    font-weight: 700;
    font-size: 0.8rem;
}

.finding-list li.error .finding-mark { color: var(--color-danger); }
.finding-list li.warning .finding-mark { color: #a04000; }

.finding-body {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 0.1rem;
    font-size: 0.82rem;
}

td.strong { font-weight: 600; }
tfoot td { font-weight: 600; border-top: 2px solid var(--color-border); }

.error { color: var(--color-danger); font-size: 0.76rem; }
</style>
