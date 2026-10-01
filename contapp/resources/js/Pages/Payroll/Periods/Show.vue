<script setup>
import { Head, Link, useForm, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DetailModal from '../../../Components/DetailModal.vue';
import { confirmAction } from '../../../Utils/confirm';
import { useRecordDetail } from '../../../Utils/recordDetail';
import { formatMoney } from '../../../Utils/money';
import {
    ArrowLeftIcon, BookOpenIcon, CalculatorIcon, CheckIcon, CircleAlertIcon, CircleXIcon, DownloadIcon,
    FileTextIcon, MailIcon, PlusIcon, RotateCcwIcon, UploadIcon,
} from '@lucide/vue';

const props = defineProps({
    period: { type: Object, required: true },
    entries: { type: Array, default: () => [] },
    totals: { type: Object, required: true },
    concepts: { type: Array, default: () => [] },
    employees: { type: Array, default: () => [] },
    inputs: { type: Array, default: () => [] },
    readiness: { type: Object, default: null },
    events: { type: Array, default: () => [] },
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

// Un movimiento se agrega en un modal que abre «Agregar movimiento», arriba
// de su tabla (CLAUDE.md secc. 21). Después de guardar, el modal queda
// abierto con el mismo trabajador y concepto: casi siempre se digitan varios
// seguidos del mismo tipo.
const addingInput = ref(false);

function openAddInput() {
    inputForm.clearErrors();
    addingInput.value = true;
}

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

// Fichas (CLAUDE.md secc. 20): la del movimiento, con «Quitar», y la de la
// boleta, con el desglose y el enlace al comprobante.
const { selected: selectedInput, openDetail: openInput, closeDetail: closeInput } = useRecordDetail(() => props.inputs);
const { selected: selectedEntry, openDetail: openEntry, closeDetail: closeEntry } = useRecordDetail(() => props.entries);

function removeInput() {
    const input = selectedInput.value;

    confirmAction({
        title: 'Quitar movimiento',
        message: `El movimiento ${input.concept_code} de ${input.employee_code} se quita del período.`,
        confirmLabel: 'Quitar',
        danger: true,
        onConfirm: () => router.delete(route('payroll-periods.inputs.destroy', [props.period.id, input.id]), { preserveScroll: true }),
    });
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
    confirmAction({
        title: 'Aprobar planilla',
        message: 'Después de aprobarla se puede contabilizar y generar el archivo de pago.',
        confirmLabel: 'Aprobar',
        onConfirm: () => router.post(route('payroll-periods.approve', props.period.id), {}, { preserveScroll: true }),
    });
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
// Deshacer una planilla son dos operaciones distintas, no una: reabrir una
// aprobada no sacó nada del sistema; anular una contabilizada exige revertir
// el asiento y devolver saldos.
const undoing = ref(null);
const undoForm = useForm({ reason: '', posting_date: '' });

// El envío masivo de comprobantes. Se confirma con la cuenta a la vista porque
// manda el salario de cada persona a su correo y no se puede deshacer.
const emailForm = useForm({});

function emailPayslips() {
    const withoutEmail = props.entries.filter((e) => ! e.employee_email).length;

    const warning = withoutEmail
        ? ` ${withoutEmail} trabajador(es) no tienen correo en la ficha y no lo van a recibir.`
        : '';

    confirmAction({
        title: 'Enviar comprobantes',
        message: `El comprobante de pago se envía por correo a cada trabajador de ${props.period.name}.${warning}`,
        confirmLabel: 'Enviar',
        onConfirm: () => emailForm.post(route('payroll-periods.email-payslips', props.period.id), { preserveScroll: true }),
    });
}

function openUndo(kind) {
    undoForm.reset();
    undoForm.clearErrors();
    undoing.value = kind;
}

function submitUndo() {
    const target = undoing.value === 'void' ? 'payroll-periods.void' : 'payroll-periods.reopen';

    undoForm.transform((data) => ({
        reason: data.reason,
        ...(undoing.value === 'void' && data.posting_date !== '' ? { posting_date: data.posting_date } : {}),
    })).post(route(target, props.period.id), {
        preserveScroll: true,
        onSuccess: () => (undoing.value = null),
    });
}

// ── Carga masiva ────────────────────────────────────────────────────────

const importForm = useForm({ file: null });
const importInput = ref(null);

function pickImport(event) {
    const file = event.target.files?.[0];
    if (! file) return;

    importForm.file = file;
    importForm.post(route('payroll-periods.inputs-import', props.period.id), {
        preserveScroll: true,
        forceFormData: true,
        onFinish: () => { if (importInput.value) importInput.value.value = ''; },
    });
}

// Los errores por celda llegan numerados para poder corregirlos de una
// pasada, en vez de descubrirlos uno por uno al reintentar.
const importErrors = computed(
    () => Object.entries(page.props.errors ?? {})
        .filter(([key]) => key.startsWith('payroll_import_'))
        .map(([, message]) => message)
);

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
        <div class="view-toolbar">
            <Link :href="route('payroll-periods.index')" class="btn btn-ghost"><ArrowLeftIcon /> Períodos</Link>
            <div class="view-actions">
                <a v-if="entries.length" :href="route('payroll-periods.export', period.id)" class="btn btn-ghost"><DownloadIcon /> Exportar XLSX</a>
                <a v-if="canPay" :href="route('payroll-periods.bank-file', period.id)" class="btn btn-ghost"><DownloadIcon /> Archivo de pago</a>
                <button
                    v-if="entries.length"
                    type="button" class="btn btn-ghost"
                    :disabled="emailForm.processing"
                    @click="emailPayslips"
                >
                    <MailIcon /> Enviar comprobantes
                </button>
                <button v-if="period.can_void" type="button" class="btn btn-ghost btn-danger-text" @click="openUndo('void')">Anular</button>
                <button v-if="period.can_reopen" type="button" class="btn btn-ghost" @click="openUndo('reopen')"><RotateCcwIcon /> Reabrir</button>
                <button v-if="canApprove" type="button" class="btn btn-ghost" @click="approve"><CheckIcon /> Aprobar</button>
                <button v-if="canPost" type="button" class="btn btn-ghost" @click="posting = true"><BookOpenIcon /> Contabilizar</button>
                <button v-if="period.is_recalculable" type="button" class="btn btn-primary" :disabled="!canCalculate || calculating" @click="calculate">
                    <CalculatorIcon /> {{ calculating ? 'Calculando…' : (entries.length ? 'Recalcular' : 'Calcular planilla') }}
                </button>
            </div>
        </div>

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
                <Link
                    v-if="period.reversal_journal_entry_id"
                    :href="route('journal-entries.show', period.reversal_journal_entry_id)" class="small"
                >Reversión #{{ period.reversal_journal_entry_id }}</Link>
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
                    type="button" class="btn btn-ghost"
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
                    <span class="finding-mark"><CircleXIcon v-if="f.severity === 'error'" /><CircleAlertIcon v-else /></span>
                    <span class="finding-body">
                        <strong>{{ f.title }}</strong>
                        <span class="muted small">{{ f.detail }}</span>
                    </span>
                    <a :href="findingHref(f)" class="btn btn-ghost">Corregir</a>
                </li>
            </ul>
        </section>

        <p v-else-if="readiness && !findings.length" class="flash flash-success">
            Verificación previa sin hallazgos: la configuración y las fichas están completas.
        </p>

        <section v-if="period.is_recalculable" class="card input-card">
            <div class="card-header">
                <h3>Movimientos del período <span class="muted small">{{ inputs.length }} registrado(s)</span></h3>
                <button type="button" class="btn btn-primary" @click="openAddInput"><PlusIcon /> Agregar movimiento</button>
            </div>

            <p class="hint small">
                Acá van horas extra, bonos y rebajos puntuales. El salario base sale de la ficha y las cargas
                sociales las calcula el motor: no se digitan, porque poder digitarlas permitiría cuadrar una
                planilla a mano y romper la conciliación con la Caja.
            </p>

            <div class="bulk-bar">
                <div class="bulk-text">
                    <strong>Carga masiva</strong>
                    <span class="muted small">
                        Descargá la plantilla con todos los trabajadores del período, llenala y subila. Los
                        trabajadores se emparejan por su <strong>código</strong>, así que podés ordenar, insertar
                        o borrar filas en Excel. La carga <strong>reemplaza</strong> los movimientos del período:
                        subir un archivo corregido lo sustituye en vez de sumarse.
                    </span>
                </div>

                <div class="bulk-actions">
                    <a :href="route('payroll-periods.inputs-template', period.id)" class="btn btn-ghost">
                        <DownloadIcon /> Descargar plantilla
                    </a>
                    <label class="btn btn-ghost file-btn" :class="{ disabled: importForm.processing }">
                        <UploadIcon /> {{ importForm.processing ? 'Cargando…' : 'Subir archivo' }}
                        <input ref="importInput" type="file" accept=".xlsx" class="file-input"
                            :disabled="importForm.processing" @change="pickImport">
                    </label>
                </div>
            </div>

            <div v-if="page.props.errors?.payroll_import" class="import-errors">
                <p class="import-errors-title">{{ page.props.errors.payroll_import }}</p>
                <ul v-if="importErrors.length">
                    <li v-for="(e, i) in importErrors" :key="i">{{ e }}</li>
                </ul>
                <p v-if="importErrors.length" class="muted small">
                    No se cargó ningún movimiento: una carga a medias dejaría sin saber qué quedó adentro.
                </p>
            </div>

            <div v-if="inputs.length" class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Trabajador</th>
                            <th>Concepto</th>
                            <th class="num">Cantidad</th>
                            <th class="num">Monto</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="i in inputs"
                            :key="i.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="openInput(i)"
                            @keydown.enter="openInput(i)"
                            @keydown.space.prevent="openInput(i)"
                        >
                            <td class="small">{{ i.employee_code }}</td>
                            <td data-label="Concepto" class="small">{{ i.concept_code }} — {{ i.concept_name }}</td>
                            <td data-label="Cantidad" class="num small">{{ i.quantity ?? '—' }}</td>
                            <td data-label="Monto" class="num small">{{ i.amount === null ? '—' : formatMoney(i.amount) }}</td>
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

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Trabajador</th>
                            <th class="num">Días</th>
                            <th class="num">Bruto</th>
                            <th class="num">Neto</th>
                            <th class="num">Costo empresa</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="e in entries"
                            :key="e.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="openEntry(e)"
                            @keydown.enter="openEntry(e)"
                            @keydown.space.prevent="openEntry(e)"
                        >
                            <td><span class="code">{{ e.employee_code }}</span> — {{ e.employee_name }}</td>
                            <td data-label="Días" class="num">{{ e.days_worked }}</td>
                            <td data-label="Bruto" class="num">{{ formatMoney(e.total_earnings) }}</td>
                            <td data-label="Neto" class="num strong">{{ formatMoney(e.net_pay) }}</td>
                            <td data-label="Costo empresa" class="num muted">{{ formatMoney(e.employer_cost) }}</td>
                        </tr>
                        <tr v-if="!entries.length">
                            <td colspan="5" class="muted empty-row">
                                Todavía no se ha calculado. Registrá los movimientos del período y presioná
                                «Calcular planilla».
                            </td>
                        </tr>
                    </tbody>
                    <tfoot v-if="entries.length">
                        <tr>
                            <td colspan="2">TOTALES</td>
                            <td data-label="Bruto" class="num">{{ formatMoney(totals.total_earnings) }}</td>
                            <td data-label="Neto" class="num strong">{{ formatMoney(totals.net_pay) }}</td>
                            <td data-label="Costo empresa" class="num">{{ formatMoney(totals.employer_cost) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <section v-if="events.length" class="card">
            <div class="card-header">
                <h3>Bitácora del período</h3>
                <span class="muted small">{{ events.length }} movimiento(s)</span>
            </div>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Cuándo</th>
                            <th>Qué pasó</th>
                            <th>Estado</th>
                            <th>Quién</th>
                            <th>Asiento</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="e in events" :key="e.id">
                            <td class="num small">{{ e.at }}</td>
                            <td data-label="Qué pasó" class="small">
                                <strong>{{ e.event_label }}</strong>
                                <span v-if="e.reason" class="block muted">{{ e.reason }}</span>
                            </td>
                            <td data-label="Estado" class="small">{{ e.from_status ?? '—' }} → {{ e.to_status }}</td>
                            <td data-label="Quién" class="muted small">{{ e.user ?? '—' }}</td>
                            <td data-label="Asiento" class="num small">
                                <Link v-if="e.journal_entry_id" :href="route('journal-entries.show', e.journal_entry_id)">
                                    #{{ e.journal_entry_id }}
                                </Link>
                                <span v-else class="muted">—</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Ficha de un movimiento -->
        <DetailModal :open="!!selectedInput" :title="selectedInput ? `${selectedInput.concept_code} — ${selectedInput.employee_code}` : ''" @close="closeInput">
            <dl v-if="selectedInput" class="detail-list">
                <div>
                    <dt>Concepto</dt>
                    <dd>{{ selectedInput.concept_code }} — {{ selectedInput.concept_name }}</dd>
                </div>
                <div>
                    <dt>Cantidad</dt>
                    <dd>{{ selectedInput.quantity ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Monto</dt>
                    <dd>{{ selectedInput.amount === null ? '—' : formatMoney(selectedInput.amount) }}</dd>
                </div>
                <div>
                    <dt>Referencia</dt>
                    <dd>{{ selectedInput.notes ?? '—' }}</dd>
                </div>
            </dl>

            <template #actions>
                <button v-if="selectedInput && period.is_recalculable" type="button" class="btn btn-ghost btn-danger-text" @click="removeInput">Quitar</button>
            </template>
        </DetailModal>

        <!-- Ficha de una boleta -->
        <DetailModal :open="!!selectedEntry" :title="selectedEntry ? `${selectedEntry.employee_code} — ${selectedEntry.employee_name}` : ''" @close="closeEntry">
            <dl v-if="selectedEntry" class="detail-list">
                <div>
                    <dt>Centro de costo</dt>
                    <dd>{{ selectedEntry.cost_center ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Días</dt>
                    <dd>{{ selectedEntry.days_worked }}</dd>
                </div>
                <div>
                    <dt>Bruto</dt>
                    <dd>{{ formatMoney(selectedEntry.total_earnings) }}</dd>
                </div>
                <div>
                    <dt>Base CCSS</dt>
                    <dd>{{ formatMoney(selectedEntry.ccss_base) }}</dd>
                </div>
                <div>
                    <dt>Cargas obreras</dt>
                    <dd>{{ formatMoney(selectedEntry.total_employee_contributions) }}</dd>
                </div>
                <div>
                    <dt>Impuesto</dt>
                    <dd>{{ formatMoney(selectedEntry.income_tax) }}</dd>
                </div>
                <div>
                    <dt>Otros rebajos</dt>
                    <dd>{{ formatMoney(selectedEntry.total_other_deductions) }}</dd>
                </div>
                <div>
                    <dt>Neto</dt>
                    <dd><strong>{{ formatMoney(selectedEntry.net_pay) }}</strong></dd>
                </div>
                <div>
                    <dt>Costo empresa</dt>
                    <dd>{{ formatMoney(selectedEntry.employer_cost) }}</dd>
                </div>
            </dl>

            <template #actions>
                <Link v-if="selectedEntry" :href="route('payslips.show', selectedEntry.id)" class="btn btn-primary"><FileTextIcon /> Comprobante</Link>
            </template>
        </DetailModal>

        <!-- Agregar un movimiento -->
        <DetailModal :open="addingInput" title="Agregar movimiento del período" @close="addingInput = false">
            <form id="period-input-form" @submit.prevent="submitInput">
                <div class="field">
                    <label for="input-employee">Trabajador</label>
                    <select id="input-employee" v-model="inputForm.employee_id" required>
                        <option value="">Elegí</option>
                        <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.code }} — {{ e.name }}</option>
                    </select>
                    <span v-if="inputForm.errors.employee_id" class="error">{{ inputForm.errors.employee_id }}</span>
                </div>

                <div class="field">
                    <label for="input-concept">Concepto</label>
                    <select id="input-concept" v-model="inputForm.payroll_concept_id" required>
                        <option value="">Elegí</option>
                        <option v-for="c in concepts" :key="c.id" :value="c.id">
                            {{ c.code }} — {{ c.name }}{{ c.type === 'deduction' ? ' (rebajo)' : '' }}
                        </option>
                    </select>
                    <span v-if="inputForm.errors.payroll_concept_id" class="error">{{ inputForm.errors.payroll_concept_id }}</span>
                </div>

                <div class="field-row">
                    <div v-if="byHours" class="field">
                        <label for="input-quantity">Horas</label>
                        <input id="input-quantity" v-model="inputForm.quantity" type="number" step="0.01" min="0" required>
                        <span class="muted small">Se paga al factor {{ selectedConcept?.factor }} sobre la hora ordinaria.</span>
                        <span v-if="inputForm.errors.quantity" class="error">{{ inputForm.errors.quantity }}</span>
                    </div>

                    <div v-else class="field">
                        <label for="input-amount">Monto</label>
                        <input id="input-amount" v-model="inputForm.amount" type="number" step="0.01" required>
                        <span v-if="inputForm.errors.amount" class="error">{{ inputForm.errors.amount }}</span>
                    </div>

                    <div class="field">
                        <label for="input-notes">Referencia</label>
                        <input id="input-notes" v-model="inputForm.notes" type="text" maxlength="255" placeholder="De dónde salió el dato">
                    </div>
                </div>

                <p v-if="inputForm.recentlySuccessful" class="flash flash-success">Movimiento agregado. Podés seguir con el siguiente.</p>
            </form>

            <template #actions>
                <button type="button" class="btn btn-ghost" @click="addingInput = false">Cerrar</button>
                <button type="submit" form="period-input-form" class="btn btn-primary" :disabled="inputForm.processing">Agregar</button>
            </template>
        </DetailModal>

        <DetailModal :open="!!undoing" :title="undoing === 'void' ? 'Anular la planilla' : 'Reabrir la planilla'" @close="undoing = null">
            <form id="period-undo-form" @submit.prevent="submitUndo">
                <template v-if="undoing === 'void'">
                    <p class="hint small">
                        Esta planilla ya está contabilizada. Anularla <strong>no la borra</strong>: se contabiliza
                        un asiento de reversión que cancela exactamente al original, se devuelven los saldos de
                        préstamo que se rebajaron y se quitan las vacaciones acreditadas. El período vuelve a
                        estar abierto para rehacerlo con sus mismas fechas.
                    </p>
                    <p class="flash flash-warning">
                        Las boletas actuales se van a reemplazar al recalcular. Si necesitás conservarlas,
                        exportá el XLSX antes de anular.
                    </p>
                </template>

                <p v-else class="hint small">
                    Vuelve a <strong>calculada</strong> para corregirla. No hay asiento que revertir, porque
                    todavía no se contabilizó. La aprobación anterior se limpia: la planilla corregida la tiene
                    que volver a aprobar alguien.
                </p>

                <div class="field">
                    <label for="undo-reason">Motivo</label>
                    <textarea id="undo-reason" v-model="undoForm.reason" rows="3" required
                        placeholder="Por qué hay que deshacer esta planilla"></textarea>
                    <span class="muted small">Queda en la bitácora del período.</span>
                    <span v-if="undoForm.errors.reason" class="error">{{ undoForm.errors.reason }}</span>
                </div>

                <div v-if="undoing === 'void'" class="field">
                    <label for="undo-date">Fecha del asiento de reversión</label>
                    <input id="undo-date" v-model="undoForm.posting_date" type="date">
                    <span class="muted small">
                        Vacío usa la fecha del asiento original, que es lo que deja los saldos como si la
                        planilla nunca se hubiera contabilizado.
                    </span>
                </div>

            </form>

            <template #actions>
                <button type="button" class="btn btn-ghost" @click="undoing = null">Cancelar</button>
                <button type="submit" form="period-undo-form" class="btn" :class="undoing === 'void' ? 'btn-danger' : 'btn-primary'" :disabled="undoForm.processing">
                    {{ undoing === 'void' ? 'Anular' : 'Reabrir' }}
                </button>
            </template>
        </DetailModal>

        <DetailModal :open="posting" title="Contabilizar la planilla" @close="posting = false">
            <form id="period-post-form" @submit.prevent="post">
                <p class="hint small">
                    El asiento carga al gasto el <strong>salario bruto</strong> —no el neto—, abona las
                    retenciones a su pasivo y deja el neto en «planilla por pagar». El banco se toca en el
                    asiento de pago, que es otro hecho.
                </p>

                <div class="field">
                    <label for="post-date">Fecha de contabilización</label>
                    <input id="post-date" v-model="postingDate" type="date">
                    <span class="muted small">Vacío usa la fecha de pago del período ({{ period.payment_date }}).</span>
                </div>
            </form>

            <template #actions>
                <button type="button" class="btn btn-ghost" @click="posting = false">Cancelar</button>
                <button type="submit" form="period-post-form" class="btn btn-primary">Contabilizar</button>
            </template>
        </DetailModal>
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

.input-card { margin-bottom: 1rem; }
.input-card h3 { margin: 0; font-size: 0.9rem; }
.input-card .hint { padding: 0 1.1rem; }

table { font-size: 0.85rem; }
.code { font-variant-numeric: tabular-nums; }
.block { display: block; }

.stat-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 11rem), 1fr));
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

.card-header h3 { margin: 0; font-size: 0.9rem; display: flex; align-items: center; flex-wrap: wrap; gap: 0.5rem; }

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

.finding-list li.warning { border-left-color: var(--color-warning); }

.finding-mark {
    flex-shrink: 0;
    display: inline-flex;
    justify-content: center;
    width: 1.1rem;
    padding-top: 0.1rem;
}

.finding-list li.error .finding-mark { color: var(--color-danger); }
.finding-list li.warning .finding-mark { color: var(--color-warning); }

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

/* ── Carga masiva ───────────────────────────────────────────────────── */

.bulk-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
    margin: 0 1.1rem 1rem;
    padding: 0.75rem 0.9rem;
    border-radius: var(--radius-sm);
    background: var(--color-surface-alt);
}

.bulk-text { display: flex; flex-direction: column; gap: 0.15rem; flex: 1 1 20rem; min-width: 0; }
.bulk-actions { display: flex; flex-wrap: wrap; gap: 0.5rem; }

.file-btn { position: relative; overflow: hidden; cursor: pointer; }
.file-btn.disabled { opacity: 0.6; cursor: default; }
.file-input { position: absolute; inset: 0; opacity: 0; width: 100%; cursor: pointer; }

.import-errors {
    margin: 0 1.1rem 1rem;
    padding: 0.75rem 0.9rem;
    border-radius: var(--radius-sm);
    background: var(--color-danger-soft);
    color: var(--color-danger);
    font-size: 0.82rem;
}

.import-errors-title { font-weight: 700; margin: 0 0 0.4rem; }
.import-errors ul { margin: 0 0 0.4rem; padding-left: 1.1rem; display: flex; flex-direction: column; gap: 0.2rem; }
</style>
