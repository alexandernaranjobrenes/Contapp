<script setup>
import { Head, Link, useForm, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import { formatMoney } from '../../../Utils/money';

const props = defineProps({
    settings: { type: Object, default: null },
    contributions: { type: Array, default: () => [] },
    brackets: { type: Array, default: () => [] },
    credits: { type: Array, default: () => [] },
    provisions: { type: Array, default: () => [] },
    concepts: { type: Array, default: () => [] },
    accounts: { type: Array, default: () => [] },
    documentTypes: { type: Array, default: () => [] },
    payers: { type: Object, required: true },
    institutions: { type: Object, required: true },
    hasConfiguration: { type: Boolean, default: false },
});

const page = usePage();

const tab = ref('determinacion');

const tabs = [
    ['determinacion', 'Determinación de cuentas'],
    ['cuentas', 'Parámetros'],
    ['cargas', 'Cargas sociales'],
    ['impuesto', 'Impuesto al salario'],
    ['provisiones', 'Provisiones'],
    ['conceptos', 'Conceptos'],
];

// ── Determinación de cuentas ────────────────────────────────────────────
//
// Todas las cuentas de la planilla en una tabla y un solo guardado. El modelo
// las guarda repartidas —cada cuenta pertenece a lo que la usa— y eso está
// bien; lo que no sirve es obligar a abrir sesenta modales para contestar
// «¿a qué cuentas va a caer mi planilla?».

const accountsForm = useForm({
    settings: {
        salary_expense_account_id: props.settings?.salary_expense_account_id ?? '',
        net_payable_account_id: props.settings?.net_payable_account_id ?? '',
        income_tax_payable_account_id: props.settings?.income_tax_payable_account_id ?? '',
    },
    contributions: props.contributions.map((c) => ({
        id: c.id,
        expense_account_id: c.expense_account_id ?? '',
        liability_account_id: c.liability_account_id ?? '',
    })),
    provisions: props.provisions.map((p) => ({
        id: p.id,
        expense_account_id: p.expense_account_id ?? '',
        liability_account_id: p.liability_account_id ?? '',
    })),
    concepts: props.concepts.map((c) => ({
        id: c.id,
        account_id: c.account_id ?? '',
    })),
});

// Para leer y escribir la fila del formulario que corresponde a cada
// registro sin buscarla en cada render.
const contributionRow = (id) => accountsForm.contributions.find((r) => r.id === id);
const provisionRow = (id) => accountsForm.provisions.find((r) => r.id === id);
const conceptRow = (id) => accountsForm.concepts.find((r) => r.id === id);

const activeContributions = computed(() => props.contributions.filter((c) => c.status === 'active'));
const activeProvisions = computed(() => props.provisions.filter((p) => p.status === 'active'));
const activeConcepts = computed(() => props.concepts.filter((c) => c.status === 'active'));

/*
 * Qué cuenta es obligatoria y cuál no depende de cómo se contabiliza cada
 * cosa, no de una regla uniforme:
 *
 *  · carga OBRERA: se le retiene al trabajador, así que solo genera un
 *    pasivo. No tiene cuenta de gasto porque no es gasto de la empresa.
 *  · carga PATRONAL: es gasto de la empresa y a la vez un pasivo con la
 *    institución. Necesita las dos.
 *  · provisión: igual que la patronal, pero solo si su porcentaje es mayor
 *    que cero — una provisión en cero no genera línea de asiento.
 *  · concepto: la cuenta es opcional. Sin ella, el rebajo cae en «planilla
 *    por pagar», que cuadra pero mezcla el rebajo con el neto.
 */
const missing = computed(() => {
    const gaps = [];

    if (! accountsForm.settings.salary_expense_account_id) {
        gaps.push('Gasto de salarios (bloquea la contabilización)');
    }

    if (! accountsForm.settings.net_payable_account_id) {
        gaps.push('Planilla por pagar (bloquea la contabilización)');
    }

    activeContributions.value.forEach((c) => {
        const row = contributionRow(c.id);
        if (! row) return;

        if (! row.liability_account_id) gaps.push(`${c.code} · cuenta de pasivo`);
        if (c.payer === 'employer' && ! row.expense_account_id) gaps.push(`${c.code} · cuenta de gasto`);
    });

    activeProvisions.value.forEach((p) => {
        if (p.percentage <= 0) return;

        const row = provisionRow(p.id);
        if (! row) return;

        if (! row.expense_account_id) gaps.push(`${p.code} · cuenta de gasto`);
        if (! row.liability_account_id) gaps.push(`${p.code} · cuenta de pasivo`);
    });

    return gaps;
});

function normalizeAccounts(data) {
    const blank = (v) => (v === '' ? null : v);

    return {
        settings: {
            salary_expense_account_id: blank(data.settings.salary_expense_account_id),
            net_payable_account_id: blank(data.settings.net_payable_account_id),
            income_tax_payable_account_id: blank(data.settings.income_tax_payable_account_id),
        },
        contributions: data.contributions.map((r) => ({
            id: r.id,
            expense_account_id: blank(r.expense_account_id),
            liability_account_id: blank(r.liability_account_id),
        })),
        provisions: data.provisions.map((r) => ({
            id: r.id,
            expense_account_id: blank(r.expense_account_id),
            liability_account_id: blank(r.liability_account_id),
        })),
        concepts: data.concepts.map((r) => ({
            id: r.id,
            account_id: blank(r.account_id),
        })),
    };
}

function saveAccounts() {
    accountsForm.transform(normalizeAccounts).put(route('payroll-settings.accounts.update'), {
        preserveScroll: true,
    });
}

// ── Cuentas y parámetros ────────────────────────────────────────────────

// Solo parámetros del cálculo. Las cuentas van por la pantalla de
// determinación: tenerlas en dos formularios haría que guardar uno pisara
// silenciosamente lo del otro.
const settingsForm = useForm({
    document_type_id: props.settings?.document_type_id ?? '',
    vacation_days_per_month: props.settings?.vacation_days_per_month ?? 1,
    max_deduction_percentage: props.settings?.max_deduction_percentage ?? 0,
});

function saveSettings() {
    settingsForm.transform((data) => ({
        ...data,
        document_type_id: data.document_type_id === '' ? null : data.document_type_id,
    })).put(route('payroll-settings.update'), { preserveScroll: true });
}

// ── Carga de la plantilla de Costa Rica ─────────────────────────────────

const loadingDefaults = ref(false);
const defaultsForm = useForm({ valid_from: `${new Date().getFullYear()}-01-01` });

function loadDefaults() {
    if (! confirm(
        'Se va a cargar una plantilla de arranque con los componentes de carga social, la escala del impuesto, '
        + 'los créditos familiares, las provisiones y los conceptos de uso corriente en Costa Rica.\n\n'
        + 'Los porcentajes y montos NO son una fuente autorizada: hay que verificarlos contra el decreto '
        + 'vigente y corregirlos aquí antes de correr la primera planilla en serio.\n\n¿Continuar?'
    )) return;

    defaultsForm.post(route('payroll-settings.load-defaults'), {
        preserveScroll: true,
        onSuccess: () => (loadingDefaults.value = false),
    });
}

// ── Editores genéricos ──────────────────────────────────────────────────
//
// Las cinco tablas se editan con el mismo patrón: un formulario en modal que
// sirve para crear y para editar, y un borrado que el servidor rechaza si la
// fila ya se usó en una planilla.

const editor = ref(null);

const blanks = {
    contribution: {
        code: '', name: '', payer: 'employee', institution: 'ccss', percentage: '',
        base: 'ccss', ceiling_amount: '', expense_account_id: '', liability_account_id: '',
        valid_from: '', valid_to: '', status: 'active', legal_basis: '',
    },
    bracket: {
        bracket_number: 1, from_amount: '', to_amount: '', percentage: '',
        valid_from: '', valid_to: '',
    },
    credit: {
        code: 'child', name: '', monthly_amount: '', valid_from: '', valid_to: '',
    },
    provision: {
        code: 'aguinaldo', name: '', percentage: '',
        expense_account_id: '', liability_account_id: '',
        valid_from: '', valid_to: '', status: 'active', legal_basis: '',
    },
    concept: {
        code: '', name: '', type: 'earning',
        affects_ccss: true, affects_income_tax: true, affects_provisions: true,
        calculation: 'amount', factor: '', account_id: '', is_recurring: false,
        status: 'active', legal_basis: '',
    },
};

const routes = {
    contribution: 'payroll-settings.contributions',
    bracket: 'payroll-settings.brackets',
    credit: 'payroll-settings.credits',
    provision: 'payroll-settings.provisions',
    concept: 'payroll-settings.concepts',
};

const nullableFields = [
    'to_amount', 'valid_to', 'ceiling_amount', 'expense_account_id',
    'liability_account_id', 'account_id', 'factor', 'legal_basis',
];

const rowForm = useForm({ ...blanks.contribution });

function openEditor(kind, row = null) {
    const blank = blanks[kind];

    rowForm.clearErrors();
    rowForm.defaults({ ...blank });
    rowForm.reset();

    Object.keys(blank).forEach((key) => {
        rowForm[key] = row ? (row[key] ?? blank[key]) : blank[key];
    });

    editor.value = { kind, row };
}

function submitRow() {
    const { kind, row } = editor.value;

    const transform = (data) => {
        const out = {};
        Object.keys(blanks[kind]).forEach((key) => { out[key] = data[key]; });
        nullableFields.forEach((key) => { if (out[key] === '') out[key] = null; });

        return out;
    };

    const done = { onSuccess: () => (editor.value = null), preserveScroll: true };

    if (row) {
        rowForm.transform(transform).put(route(`${routes[kind]}.update`, row.id), done);
    } else {
        rowForm.transform(transform).post(route(`${routes[kind]}.store`), done);
    }
}

function destroyRow(kind, row) {
    if (! confirm('¿Eliminar esta fila de la configuración?')) return;

    router.delete(route(`${routes[kind]}.destroy`, row.id), { preserveScroll: true });
}

// ── Lo que hace visible un error de configuración ───────────────────────

const employeeTotal = computed(() => props.contributions
    .filter((c) => c.payer === 'employee' && c.status === 'active')
    .reduce((sum, c) => sum + c.percentage, 0));

const employerTotal = computed(() => props.contributions
    .filter((c) => c.payer === 'employer' && c.status === 'active')
    .reduce((sum, c) => sum + c.percentage, 0));

// Una carga o provisión sin sus dos cuentas no se puede contabilizar, y eso
// no aparece hasta que alguien intenta contabilizar la planilla.
const unmapped = computed(() => [
    ...props.contributions.filter((c) => c.status === 'active' && (! c.expense_account_id || ! c.liability_account_id))
        .map((c) => `${c.code} (carga)`),
    ...props.provisions.filter((p) => p.status === 'active' && p.percentage > 0 && (! p.expense_account_id || ! p.liability_account_id))
        .map((p) => `${p.code} (provisión)`),
]);

// Un hueco o un traslape entre tramos deja parte del salario sin gravar o lo
// grava dos veces. Se revisa por vigencia porque hay una escala por año.
const bracketWarnings = computed(() => {
    const byVigencia = {};
    props.brackets.forEach((b) => {
        byVigencia[b.valid_from] = byVigencia[b.valid_from] ?? [];
        byVigencia[b.valid_from].push(b);
    });

    const problems = [];

    Object.entries(byVigencia).forEach(([validFrom, rows]) => {
        const sorted = [...rows].sort((a, b) => a.bracket_number - b.bracket_number);

        if (parseFloat(sorted[0]?.from_amount ?? 0) !== 0) {
            problems.push(`La escala del ${validFrom} no arranca en cero: el primer tramo empieza en ${sorted[0]?.from_amount}.`);
        }

        if (sorted[sorted.length - 1]?.to_amount !== null) {
            problems.push(`La escala del ${validFrom} no tiene un tramo final sin techo: los salarios por encima del último tramo no tributarían.`);
        }

        for (let i = 0; i < sorted.length - 1; i += 1) {
            const top = sorted[i].to_amount;
            const next = sorted[i + 1].from_amount;

            if (top === null) continue;

            if (parseFloat(top) !== parseFloat(next)) {
                problems.push(
                    `En la escala del ${validFrom}, el tramo ${sorted[i].bracket_number} termina en ${top} `
                    + `y el ${sorted[i + 1].bracket_number} empieza en ${next}: hay un `
                    + `${parseFloat(next) > parseFloat(top) ? 'hueco sin gravar' : 'traslape que grava dos veces'}.`
                );
            }
        }
    });

    return problems;
});

const accountLabel = (id) => {
    const found = props.accounts.find((a) => a.id === id);

    return found ? `${found.code}` : '—';
};
</script>

<template>
    <Head title="Configuración de planilla" />

    <AppLayout title="Configuración de planilla">
        <template #actions>
            <Link :href="route('payroll-periods.index')" class="btn btn-ghost">Períodos de planilla</Link>
        </template>

        <div v-for="(message, key) in page.props.errors" :key="key" class="flash flash-error">{{ message }}</div>

        <div class="warning-box">
            <h2>Las tasas viven acá, no en el código</h2>
            <p>
                Ninguna tasa está escrita dentro del cálculo. Todas viven en estas tablas
                <strong>con fecha de vigencia</strong>, y eso es lo que hace que una planilla de marzo se
                reproduzca años después con las tasas que regían en marzo — aunque la Caja las haya movido
                desde entonces.
            </p>
            <p>
                Cambiar una tasa <strong>no reescribe el pasado</strong>: se le pone fecha final a la que
                estaba y se crea una nueva. Editar la vigente en su lugar cambiaría planillas ya pagadas.
            </p>
        </div>

        <nav class="tabs">
            <button
                v-for="([value, label]) in tabs" :key="value"
                type="button" class="tab" :class="{ active: tab === value }"
                @click="tab = value"
            >{{ label }}</button>
        </nav>

        <!-- ── Determinación de cuentas ──────────────────────────────── -->
        <template v-if="tab === 'determinacion'">
            <div v-if="!hasConfiguration" class="card load-card">
                <h3>Primero hay que cargar la configuración</h3>
                <p class="hint">
                    Las cuentas de cada carga social, provisión y concepto son campos <em>de</em> esos registros.
                    Mientras no exista ninguno, no hay nada a qué vincular una cuenta — y por eso esta pantalla
                    aparece vacía.
                </p>
                <p class="hint danger-hint">
                    <strong>Los porcentajes de la plantilla no son una fuente autorizada.</strong> Verificá cada
                    uno contra el decreto vigente antes de correr la primera planilla en serio.
                </p>

                <div class="load-row">
                    <div class="field">
                        <label>Vigentes desde</label>
                        <input v-model="defaultsForm.valid_from" type="date" required>
                    </div>
                    <button type="button" class="btn btn-primary" :disabled="defaultsForm.processing" @click="loadDefaults">
                        Cargar plantilla de Costa Rica
                    </button>
                </div>
            </div>

            <template v-else>
                <p class="hint">
                    Todas las cuentas de la planilla en un solo lugar. El asiento tiene tres bloques y esta tabla
                    sigue ese orden: el bruto y sus retenciones, lo que el patrono paga encima, y lo que se
                    provisiona.
                </p>

                <div v-if="missing.length" class="flash flash-warning">
                    <strong>{{ missing.length }} cuenta(s) sin asignar.</strong>
                    Una carga o provisión sin sus cuentas no se puede contabilizar, y eso no aparece hasta que
                    alguien intenta contabilizar la planilla:
                    <ul class="problem-list">
                        <li v-for="(gap, i) in missing.slice(0, 8)" :key="i">{{ gap }}</li>
                        <li v-if="missing.length > 8" class="muted">…y {{ missing.length - 8 }} más.</li>
                    </ul>
                </div>

                <p v-else class="flash flash-success">
                    Todas las cuentas necesarias están asignadas.
                </p>

                <form @submit.prevent="saveAccounts">
                    <!-- Bloque 1 -->
                    <section class="card">
                        <div class="card-header">
                            <h3>1 · El salario bruto y sus retenciones</h3>
                        </div>

                        <p class="hint small">
                            El gasto es el <strong>bruto</strong>, no el neto: las retenciones son dinero del
                            trabajador que la empresa custodia hasta enterarlo, no gasto propio.
                        </p>

                        <div class="determination-grid">
                            <div class="field">
                                <label>Gasto de salarios <span class="req">obligatoria</span></label>
                                <select v-model="accountsForm.settings.salary_expense_account_id">
                                    <option value="">Sin asignar</option>
                                    <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.description_es }}</option>
                                </select>
                                <span class="hint small">
                                    Valor por defecto. La ficha de cada empleado puede sobreescribirla, para
                                    separar mano de obra directa de gasto administrativo.
                                </span>
                            </div>

                            <div class="field">
                                <label>Planilla por pagar <span class="req">obligatoria</span></label>
                                <select v-model="accountsForm.settings.net_payable_account_id">
                                    <option value="">Sin asignar</option>
                                    <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.description_es }}</option>
                                </select>
                                <span class="hint small">
                                    El pasivo con el trabajador. El banco se toca en el asiento de pago, que es
                                    otro hecho.
                                </span>
                            </div>

                            <div class="field">
                                <label>Impuesto al salario por pagar</label>
                                <select v-model="accountsForm.settings.income_tax_payable_account_id">
                                    <option value="">Sin asignar</option>
                                    <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.description_es }}</option>
                                </select>
                                <span class="hint small">
                                    Sin esta cuenta la retención cae en «planilla por pagar»: cuadra, pero mezcla
                                    lo que se le debe a Tributación con lo que se le debe al trabajador.
                                </span>
                            </div>
                        </div>
                    </section>

                    <!-- Bloque 2 -->
                    <section class="card">
                        <div class="card-header">
                            <h3>2 · Cargas sociales</h3>
                            <span class="muted small">{{ activeContributions.length }} componente(s) vigente(s)</span>
                        </div>

                        <p class="hint small">
                            La carga <strong>obrera</strong> se le retiene al trabajador: solo genera pasivo, no
                            es gasto de la empresa. La <strong>patronal</strong> es gasto y pasivo a la vez, y
                            necesita las dos cuentas.
                        </p>

                        <div class="table-scroll">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Código</th>
                                        <th>Componente</th>
                                        <th>Paga</th>
                                        <th class="right">%</th>
                                        <th>Cuenta de gasto</th>
                                        <th>Cuenta de pasivo</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="c in activeContributions" :key="c.id">
                                        <td class="num">{{ c.code }}</td>
                                        <td class="small">{{ c.name }}</td>
                                        <td class="small">{{ c.payer === 'employee' ? 'Obrero' : 'Patronal' }}</td>
                                        <td class="right num">{{ c.percentage.toFixed(2) }}</td>
                                        <td>
                                            <select
                                                v-if="c.payer === 'employer'"
                                                v-model="contributionRow(c.id).expense_account_id"
                                                class="inline-select"
                                                :class="{ unset: !contributionRow(c.id).expense_account_id }"
                                            >
                                                <option value="">Sin asignar</option>
                                                <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.description_es }}</option>
                                            </select>
                                            <span v-else class="muted small">no aplica</span>
                                        </td>
                                        <td>
                                            <select
                                                v-model="contributionRow(c.id).liability_account_id"
                                                class="inline-select"
                                                :class="{ unset: !contributionRow(c.id).liability_account_id }"
                                            >
                                                <option value="">Sin asignar</option>
                                                <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.description_es }}</option>
                                            </select>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <!-- Bloque 3 -->
                    <section class="card">
                        <div class="card-header">
                            <h3>3 · Provisiones</h3>
                        </div>

                        <p class="hint small">
                            El aguinaldo se paga en diciembre pero se gana todo el año. Una provisión en cero no
                            genera línea de asiento y por eso no exige cuentas.
                        </p>

                        <div class="table-scroll">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Código</th>
                                        <th>Provisión</th>
                                        <th class="right">%</th>
                                        <th>Cuenta de gasto</th>
                                        <th>Cuenta de pasivo</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="p in activeProvisions" :key="p.id" :class="{ dim: p.percentage <= 0 }">
                                        <td class="num">{{ p.code }}</td>
                                        <td class="small">{{ p.name }}</td>
                                        <td class="right num">{{ p.percentage.toFixed(4) }}</td>
                                        <td>
                                            <select
                                                v-model="provisionRow(p.id).expense_account_id"
                                                class="inline-select"
                                                :class="{ unset: p.percentage > 0 && !provisionRow(p.id).expense_account_id }"
                                            >
                                                <option value="">Sin asignar</option>
                                                <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.description_es }}</option>
                                            </select>
                                        </td>
                                        <td>
                                            <select
                                                v-model="provisionRow(p.id).liability_account_id"
                                                class="inline-select"
                                                :class="{ unset: p.percentage > 0 && !provisionRow(p.id).liability_account_id }"
                                            >
                                                <option value="">Sin asignar</option>
                                                <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.description_es }}</option>
                                            </select>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <!-- Conceptos -->
                    <section class="card">
                        <div class="card-header">
                            <h3>Conceptos de ingreso y deducción</h3>
                            <span class="muted small">opcionales</span>
                        </div>

                        <p class="hint small">
                            Un ingreso sin cuenta propia va al gasto de salarios; una deducción sin cuenta cae en
                            «planilla por pagar». Las dos cuadran, pero pierden el detalle: conviene asignarle
                            cuenta a los rebajos de préstamo y a la cuota solidarista, que son pasivos con
                            terceros y no con el trabajador.
                        </p>

                        <div class="table-scroll">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Código</th>
                                        <th>Concepto</th>
                                        <th>Tipo</th>
                                        <th>Cuenta</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="c in activeConcepts" :key="c.id">
                                        <td class="num">{{ c.code }}</td>
                                        <td class="small">{{ c.name }}</td>
                                        <td class="small">{{ c.type === 'earning' ? 'Ingreso' : 'Deducción' }}</td>
                                        <td>
                                            <select v-model="conceptRow(c.id).account_id" class="inline-select">
                                                <option value="">Heredar</option>
                                                <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.description_es }}</option>
                                            </select>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <div class="save-bar card">
                        <span class="muted small">
                            Se guarda todo junto: dejar la mitad asignada produce una planilla que falla a mitad
                            de contabilizar.
                        </span>
                        <button type="submit" class="btn btn-primary" :disabled="accountsForm.processing">
                            {{ accountsForm.processing ? 'Guardando…' : 'Guardar determinación de cuentas' }}
                        </button>
                    </div>
                </form>
            </template>
        </template>

        <!-- ── Cuentas y parámetros ────────────────────────────────── -->
        <template v-if="tab === 'cuentas'">
            <div v-if="!hasConfiguration" class="card load-card">
                <h3>Todavía no hay configuración de tasas</h3>
                <p class="hint">
                    Se puede cargar una plantilla de arranque con los componentes de carga social, la escala del
                    impuesto, los créditos familiares, las provisiones y los conceptos de uso corriente en
                    Costa Rica.
                </p>
                <p class="hint danger-hint">
                    <strong>Los porcentajes de la plantilla no son una fuente autorizada.</strong> Cambian por
                    acuerdo de la Caja, por decreto anual en el caso del impuesto, y la póliza de riesgos del
                    INS depende de la actividad de cada empresa. Verificá cada número contra el decreto vigente
                    antes de correr la primera planilla en serio.
                </p>

                <div class="load-row">
                    <div class="field">
                        <label>Vigentes desde</label>
                        <input v-model="defaultsForm.valid_from" type="date" required>
                    </div>
                    <button type="button" class="btn btn-primary" :disabled="defaultsForm.processing" @click="loadDefaults">
                        Cargar plantilla de Costa Rica
                    </button>
                </div>
            </div>

            <form class="card settings-card" @submit.prevent="saveSettings">
                <h3>Parámetros del cálculo</h3>

                <p class="hint">
                    Las cuentas contables no se configuran acá sino en
                    <button type="button" class="link-btn" @click="tab = 'determinacion'">Determinación de cuentas</button>,
                    que las muestra todas juntas. Están separadas a propósito: una tasa la fija un decreto y una
                    cuenta la fija el contador.
                </p>

                <div class="field">
                    <label>Tipo de documento del asiento</label>
                    <select v-model="settingsForm.document_type_id">
                        <option value="">Sin definir</option>
                        <option v-for="d in documentTypes" :key="d.id" :value="d.id">{{ d.code }} — {{ d.name }}</option>
                    </select>
                    <span class="hint small">Con el que se contabiliza la planilla.</span>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label>Días de vacaciones por mes trabajado</label>
                        <input v-model="settingsForm.vacation_days_per_month" type="number" step="0.0001" min="0" max="10" required>
                        <span class="hint small">
                            La ley fija un mínimo; una empresa puede conceder más por convenio, y entonces sube acá.
                        </span>
                    </div>
                    <div class="field">
                        <label>Tope de deducciones (% del disponible)</label>
                        <input v-model="settingsForm.max_deduction_percentage" type="number" step="0.01" min="0" max="100" required>
                        <span class="hint small">
                            Cero = sin tope. Aun sin tope, el motor nunca deja el neto en negativo: lo que no
                            cabe queda como saldo para el período siguiente.
                        </span>
                    </div>
                </div>

                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary" :disabled="settingsForm.processing">Guardar</button>
                </div>
            </form>
        </template>

        <!-- ── Cargas sociales ─────────────────────────────────────── -->
        <template v-if="tab === 'cargas'">
            <p class="hint">
                Cada componente va por separado y no como un solo porcentaje, porque la planilla de la Caja se
                concilia componente por componente: cuando un número no cuadra, hay que poder decir cuál.
            </p>

            <p v-if="unmapped.length" class="flash flash-warning">
                Sin cuenta de gasto o de pasivo, y por eso la planilla no se va a poder contabilizar:
                {{ unmapped.join(', ') }}.
            </p>

            <div class="stat-row">
                <div class="stat">
                    <span class="stat-label">Total obrero</span>
                    <span class="stat-value">{{ employeeTotal.toFixed(2) }}%</span>
                    <span class="stat-note">se le rebaja al trabajador</span>
                </div>
                <div class="stat">
                    <span class="stat-label">Total patronal</span>
                    <span class="stat-value">{{ employerTotal.toFixed(2) }}%</span>
                    <span class="stat-note">lo paga la empresa encima del salario</span>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <span class="muted">{{ contributions.length }} componente(s)</span>
                    <button type="button" class="btn btn-primary" @click="openEditor('contribution')">+ Nueva carga</button>
                </div>

                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Nombre</th>
                                <th>Paga</th>
                                <th>Institución</th>
                                <th class="right">%</th>
                                <th>Base</th>
                                <th class="right">Tope</th>
                                <th>Gasto</th>
                                <th>Pasivo</th>
                                <th>Vigencia</th>
                                <th>Estado</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="c in contributions" :key="c.id" :class="{ dim: c.status !== 'active' }">
                                <td class="num">{{ c.code }}</td>
                                <td>
                                    {{ c.name }}
                                    <span v-if="c.legal_basis?.includes('VERIFICAR')" class="verify" :title="c.legal_basis">sin verificar</span>
                                </td>
                                <td class="small">{{ c.payer === 'employee' ? 'Obrero' : 'Patronal' }}</td>
                                <td class="muted small">{{ institutions[c.institution] ?? c.institution }}</td>
                                <td class="right num strong">{{ c.percentage.toFixed(4) }}</td>
                                <td class="muted small">{{ c.base === 'gross' ? 'Bruto' : 'Salarial' }}</td>
                                <td class="right num small">{{ c.ceiling_amount ? formatMoney(c.ceiling_amount) : '—' }}</td>
                                <td class="num small muted">{{ accountLabel(c.expense_account_id) }}</td>
                                <td class="num small muted">{{ accountLabel(c.liability_account_id) }}</td>
                                <td class="num small">{{ c.valid_from }} → {{ c.valid_to ?? '∞' }}</td>
                                <td class="small">{{ c.status === 'active' ? 'Activa' : 'Inactiva' }}</td>
                                <td class="row-actions">
                                    <button type="button" class="btn btn-ghost btn-sm" @click="openEditor('contribution', c)">Editar</button>
                                    <button type="button" class="btn btn-ghost btn-sm" @click="destroyRow('contribution', c)">Eliminar</button>
                                </td>
                            </tr>
                            <tr v-if="!contributions.length">
                                <td colspan="12" class="muted empty-row">
                                    Sin cargas configuradas. Sin ellas la planilla calcula el bruto y nada más.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>

        <!-- ── Impuesto al salario ─────────────────────────────────── -->
        <template v-if="tab === 'impuesto'">
            <p class="hint">
                La escala es <strong>mensual y progresiva</strong>: cada tramo grava solo la porción del salario
                que cae dentro de él. La base es el bruto gravable <strong>menos las cargas obreras</strong>, y
                los créditos familiares se restan <strong>del impuesto</strong>, no de la base.
            </p>

            <div v-if="bracketWarnings.length" class="flash flash-error">
                <ul class="problem-list">
                    <li v-for="(problem, i) in bracketWarnings" :key="i">{{ problem }}</li>
                </ul>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3>Tramos</h3>
                    <button type="button" class="btn btn-primary" @click="openEditor('bracket')">+ Nuevo tramo</button>
                </div>

                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Vigencia</th>
                                <th class="right">Tramo</th>
                                <th class="right">Desde</th>
                                <th class="right">Hasta</th>
                                <th class="right">Tasa %</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="b in brackets" :key="b.id">
                                <td class="num small">{{ b.valid_from }} → {{ b.valid_to ?? '∞' }}</td>
                                <td class="right num">{{ b.bracket_number }}</td>
                                <td class="right num">{{ formatMoney(b.from_amount) }}</td>
                                <td class="right num">
                                    <template v-if="b.to_amount">{{ formatMoney(b.to_amount) }}</template>
                                    <span v-else class="muted small">sin techo</span>
                                </td>
                                <td class="right num strong">{{ b.percentage.toFixed(2) }}</td>
                                <td class="row-actions">
                                    <button type="button" class="btn btn-ghost btn-sm" @click="openEditor('bracket', b)">Editar</button>
                                    <button type="button" class="btn btn-ghost btn-sm" @click="destroyRow('bracket', b)">Eliminar</button>
                                </td>
                            </tr>
                            <tr v-if="!brackets.length">
                                <td colspan="6" class="muted empty-row">
                                    Sin escala configurada: no se va a rebajar impuesto a nadie.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3>Créditos familiares</h3>
                    <button type="button" class="btn btn-primary" @click="openEditor('credit')">+ Nuevo crédito</button>
                </div>

                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Vigencia</th>
                                <th>Código</th>
                                <th>Nombre</th>
                                <th class="right">Monto mensual</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="c in credits" :key="c.id">
                                <td class="num small">{{ c.valid_from }} → {{ c.valid_to ?? '∞' }}</td>
                                <td class="num">{{ c.code === 'spouse' ? 'cónyuge' : 'hijo' }}</td>
                                <td>{{ c.name }}</td>
                                <td class="right num strong">{{ formatMoney(c.monthly_amount) }}</td>
                                <td class="row-actions">
                                    <button type="button" class="btn btn-ghost btn-sm" @click="openEditor('credit', c)">Editar</button>
                                    <button type="button" class="btn btn-ghost btn-sm" @click="destroyRow('credit', c)">Eliminar</button>
                                </td>
                            </tr>
                            <tr v-if="!credits.length">
                                <td colspan="5" class="muted empty-row">Sin créditos configurados.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>

        <!-- ── Provisiones ─────────────────────────────────────────── -->
        <template v-if="tab === 'provisiones'">
            <p class="hint">
                El aguinaldo se paga en diciembre pero se <strong>gana todo el año</strong>. Cargarlo entero a
                diciembre arruina la comparación entre meses y esconde un pasivo que ya existe. Lo mismo con
                vacaciones y cesantía.
            </p>

            <div class="card">
                <div class="card-header">
                    <span class="muted">{{ provisions.length }} provisión(es)</span>
                    <button type="button" class="btn btn-primary" @click="openEditor('provision')">+ Nueva provisión</button>
                </div>

                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Nombre</th>
                                <th class="right">%</th>
                                <th>Gasto</th>
                                <th>Pasivo</th>
                                <th>Vigencia</th>
                                <th>Fundamento</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="p in provisions" :key="p.id" :class="{ dim: p.status !== 'active' || p.percentage === 0 }">
                                <td class="num">{{ p.code }}</td>
                                <td>{{ p.name }}</td>
                                <td class="right num strong">{{ p.percentage.toFixed(4) }}</td>
                                <td class="num small muted">{{ accountLabel(p.expense_account_id) }}</td>
                                <td class="num small muted">{{ accountLabel(p.liability_account_id) }}</td>
                                <td class="num small">{{ p.valid_from }} → {{ p.valid_to ?? '∞' }}</td>
                                <td class="muted small">{{ p.legal_basis ?? '—' }}</td>
                                <td class="row-actions">
                                    <button type="button" class="btn btn-ghost btn-sm" @click="openEditor('provision', p)">Editar</button>
                                    <button type="button" class="btn btn-ghost btn-sm" @click="destroyRow('provision', p)">Eliminar</button>
                                </td>
                            </tr>
                            <tr v-if="!provisions.length">
                                <td colspan="8" class="muted empty-row">Sin provisiones configuradas.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>

        <!-- ── Conceptos ───────────────────────────────────────────── -->
        <template v-if="tab === 'conceptos'">
            <div class="warning-box subtle">
                <h2>Las tres banderas son lo más consecuente del módulo</h2>
                <p>
                    <strong>Salarial</strong>, <strong>gravable</strong> y <strong>provisiona</strong> deciden
                    qué ingresos son salario y cuáles no, y de ahí sale todo lo demás. Marcarlas mal no produce
                    un error visible: produce una planilla que cuadra consigo misma y no cuadra con la Caja.
                </p>
                <p class="small">
                    Los viáticos y los reembolsos <em>no</em> son salario: son reintegro de un gasto hecho por
                    la empresa. El subsidio por incapacidad lo paga la CCSS o el INS, no el patrono — pero el
                    complemento que la empresa pague encima sí es salario, y por eso son dos conceptos.
                </p>
            </div>

            <div class="card">
                <div class="card-header">
                    <span class="muted">{{ concepts.length }} concepto(s)</span>
                    <button type="button" class="btn btn-primary" @click="openEditor('concept')">+ Nuevo concepto</button>
                </div>

                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Nombre</th>
                                <th>Tipo</th>
                                <th>Cálculo</th>
                                <th class="right">Factor</th>
                                <th class="center">Salarial</th>
                                <th class="center">Gravable</th>
                                <th class="center">Provisiona</th>
                                <th>Cuenta</th>
                                <th>Estado</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="c in concepts" :key="c.id" :class="{ dim: c.status !== 'active' }">
                                <td class="num">{{ c.code }}</td>
                                <td>{{ c.name }}</td>
                                <td class="small">{{ c.type === 'earning' ? 'Ingreso' : 'Deducción' }}</td>
                                <td class="muted small">
                                    {{ { amount: 'Monto', percentage: 'Porcentaje', hours: 'Horas' }[c.calculation] }}
                                </td>
                                <td class="right num small">{{ c.factor ?? '—' }}</td>
                                <td class="center">{{ c.affects_ccss ? '✓' : '·' }}</td>
                                <td class="center">{{ c.affects_income_tax ? '✓' : '·' }}</td>
                                <td class="center">{{ c.affects_provisions ? '✓' : '·' }}</td>
                                <td class="num small muted">{{ accountLabel(c.account_id) }}</td>
                                <td class="small">{{ c.status === 'active' ? 'Activo' : 'Inactivo' }}</td>
                                <td class="row-actions">
                                    <button type="button" class="btn btn-ghost btn-sm" @click="openEditor('concept', c)">Editar</button>
                                    <button type="button" class="btn btn-ghost btn-sm" @click="destroyRow('concept', c)">Eliminar</button>
                                </td>
                            </tr>
                            <tr v-if="!concepts.length">
                                <td colspan="11" class="muted empty-row">Sin conceptos configurados.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>

        <!-- ── El editor ───────────────────────────────────────────── -->
        <div v-if="editor" class="modal-backdrop" @click.self="editor = null">
            <form class="modal card" @submit.prevent="submitRow">
                <h2>{{ editor.row ? 'Editar' : 'Nuevo registro' }}</h2>

                <template v-if="editor.kind === 'contribution'">
                    <div class="field-row">
                        <div class="field">
                            <label>Código</label>
                            <input v-model="rowForm.code" type="text" maxlength="30" required>
                            <span v-if="rowForm.errors.code" class="error">{{ rowForm.errors.code }}</span>
                        </div>
                        <div class="field">
                            <label>Porcentaje</label>
                            <input v-model="rowForm.percentage" type="number" step="0.0001" min="0" max="100" required>
                        </div>
                    </div>

                    <div class="field">
                        <label>Nombre</label>
                        <input v-model="rowForm.name" type="text" required>
                    </div>

                    <div class="field-row">
                        <div class="field">
                            <label>Quién lo paga</label>
                            <select v-model="rowForm.payer" required>
                                <option v-for="(label, value) in payers" :key="value" :value="value">{{ label }}</option>
                            </select>
                        </div>
                        <div class="field">
                            <label>Institución</label>
                            <select v-model="rowForm.institution" required>
                                <option v-for="(label, value) in institutions" :key="value" :value="value">{{ label }}</option>
                            </select>
                        </div>
                    </div>

                    <div class="field-row">
                        <div class="field">
                            <label>Base</label>
                            <select v-model="rowForm.base" required>
                                <option value="ccss">Solo los ingresos que forman salario</option>
                                <option value="gross">El bruto completo</option>
                            </select>
                        </div>
                        <div class="field">
                            <label>Tope salarial</label>
                            <input v-model="rowForm.ceiling_amount" type="number" step="0.01" min="0">
                            <span class="hint small">Vacío = sin tope. Acota la base, no el resultado.</span>
                        </div>
                    </div>

                    <div class="field-row">
                        <div class="field">
                            <label>Cuenta de gasto</label>
                            <select v-model="rowForm.expense_account_id">
                                <option value="">Sin definir</option>
                                <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.description_es }}</option>
                            </select>
                        </div>
                        <div class="field">
                            <label>Cuenta de pasivo</label>
                            <select v-model="rowForm.liability_account_id">
                                <option value="">Sin definir</option>
                                <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.description_es }}</option>
                            </select>
                        </div>
                    </div>

                    <div class="field">
                        <label>Fundamento legal</label>
                        <input v-model="rowForm.legal_basis" type="text" maxlength="255">
                        <span class="hint small">
                            Dejá anotado de dónde sale la tasa: es lo que permite verificarla dentro de un año.
                        </span>
                    </div>

                    <div class="field">
                        <label>Estado</label>
                        <select v-model="rowForm.status" required>
                            <option value="active">Activa</option>
                            <option value="inactive">Inactiva</option>
                        </select>
                    </div>
                </template>

                <template v-if="editor.kind === 'bracket'">
                    <div class="field-row">
                        <div class="field">
                            <label>Número de tramo</label>
                            <input v-model="rowForm.bracket_number" type="number" min="1" max="20" required>
                        </div>
                        <div class="field">
                            <label>Tasa %</label>
                            <input v-model="rowForm.percentage" type="number" step="0.01" min="0" max="100" required>
                        </div>
                    </div>

                    <div class="field-row">
                        <div class="field">
                            <label>Desde</label>
                            <input v-model="rowForm.from_amount" type="number" step="0.01" min="0" required>
                        </div>
                        <div class="field">
                            <label>Hasta</label>
                            <input v-model="rowForm.to_amount" type="number" step="0.01" min="0">
                            <span class="hint small">Vacío = tramo final, sin techo.</span>
                            <span v-if="rowForm.errors.to_amount" class="error">{{ rowForm.errors.to_amount }}</span>
                        </div>
                    </div>
                </template>

                <template v-if="editor.kind === 'credit'">
                    <div class="field">
                        <label>Tipo</label>
                        <select v-model="rowForm.code" required>
                            <option value="spouse">Cónyuge</option>
                            <option value="child">Hijo</option>
                        </select>
                    </div>

                    <div class="field">
                        <label>Nombre</label>
                        <input v-model="rowForm.name" type="text" required>
                    </div>

                    <div class="field">
                        <label>Monto mensual</label>
                        <input v-model="rowForm.monthly_amount" type="number" step="0.01" min="0" required>
                        <span class="hint small">Se resta del impuesto calculado, no de la base.</span>
                    </div>
                </template>

                <template v-if="editor.kind === 'provision'">
                    <div class="field-row">
                        <div class="field">
                            <label>Tipo</label>
                            <select v-model="rowForm.code" required>
                                <option value="aguinaldo">Aguinaldo</option>
                                <option value="vacaciones">Vacaciones</option>
                                <option value="cesantia">Cesantía</option>
                                <option value="preaviso">Preaviso</option>
                            </select>
                        </div>
                        <div class="field">
                            <label>Porcentaje</label>
                            <input v-model="rowForm.percentage" type="number" step="0.0001" min="0" max="100" required>
                            <span class="hint small">Cero = no se provisiona.</span>
                        </div>
                    </div>

                    <div class="field">
                        <label>Nombre</label>
                        <input v-model="rowForm.name" type="text" required>
                    </div>

                    <div class="field-row">
                        <div class="field">
                            <label>Cuenta de gasto</label>
                            <select v-model="rowForm.expense_account_id">
                                <option value="">Sin definir</option>
                                <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.description_es }}</option>
                            </select>
                        </div>
                        <div class="field">
                            <label>Cuenta de pasivo</label>
                            <select v-model="rowForm.liability_account_id">
                                <option value="">Sin definir</option>
                                <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.description_es }}</option>
                            </select>
                        </div>
                    </div>

                    <div class="field">
                        <label>Fundamento legal</label>
                        <input v-model="rowForm.legal_basis" type="text" maxlength="255">
                    </div>

                    <div class="field">
                        <label>Estado</label>
                        <select v-model="rowForm.status" required>
                            <option value="active">Activa</option>
                            <option value="inactive">Inactiva</option>
                        </select>
                    </div>
                </template>

                <template v-if="editor.kind === 'concept'">
                    <div class="field-row">
                        <div class="field">
                            <label>Código</label>
                            <input v-model="rowForm.code" type="text" maxlength="30" required>
                            <span v-if="rowForm.errors.code" class="error">{{ rowForm.errors.code }}</span>
                        </div>
                        <div class="field">
                            <label>Tipo</label>
                            <select v-model="rowForm.type" required>
                                <option value="earning">Ingreso</option>
                                <option value="deduction">Deducción</option>
                            </select>
                        </div>
                    </div>

                    <div class="field">
                        <label>Nombre</label>
                        <input v-model="rowForm.name" type="text" required>
                    </div>

                    <div class="field-row">
                        <div class="field">
                            <label>Cómo se calcula</label>
                            <select v-model="rowForm.calculation" required>
                                <option value="amount">Se digita el monto</option>
                                <option value="hours">Por horas × factor</option>
                                <option value="percentage">Porcentaje del salario</option>
                            </select>
                        </div>
                        <div class="field">
                            <label>Factor</label>
                            <input v-model="rowForm.factor" type="number" step="0.0001" min="0">
                            <span class="hint small">
                                Para horas: 1,5 extra simple, 2 doble. Para porcentaje: el porcentaje mismo.
                            </span>
                        </div>
                    </div>

                    <p class="hint small">
                        Las tres banderas deciden si este concepto es salario. Marcarlas mal produce una planilla
                        que cuadra consigo misma y no cuadra con la Caja.
                    </p>

                    <label class="check">
                        <input v-model="rowForm.affects_ccss" type="checkbox">
                        Forma salario para cargas sociales
                    </label>
                    <label class="check">
                        <input v-model="rowForm.affects_income_tax" type="checkbox">
                        Está sujeto al impuesto al salario
                    </label>
                    <label class="check">
                        <input v-model="rowForm.affects_provisions" type="checkbox">
                        Entra a la base de aguinaldo, vacaciones y cesantía
                    </label>

                    <div class="field">
                        <label>Cuenta contable</label>
                        <select v-model="rowForm.account_id">
                            <option value="">Sin definir</option>
                            <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.description_es }}</option>
                        </select>
                    </div>

                    <div class="field">
                        <label>Estado</label>
                        <select v-model="rowForm.status" required>
                            <option value="active">Activo</option>
                            <option value="inactive">Inactivo</option>
                        </select>
                    </div>
                </template>

                <template v-if="editor.kind !== 'concept'">
                    <div class="field-row">
                        <div class="field">
                            <label>Vigente desde</label>
                            <input v-model="rowForm.valid_from" type="date" required>
                        </div>
                        <div class="field">
                            <label>Vigente hasta</label>
                            <input v-model="rowForm.valid_to" type="date">
                            <span class="hint small">Vacío = sigue vigente.</span>
                            <span v-if="rowForm.errors.valid_to" class="error">{{ rowForm.errors.valid_to }}</span>
                        </div>
                    </div>
                </template>

                <div class="modal-actions">
                    <button type="button" class="btn btn-ghost" @click="editor = null">Cancelar</button>
                    <button type="submit" class="btn btn-primary" :disabled="rowForm.processing">Guardar</button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>

<style scoped>
.warning-box {
    padding: 0.9rem 1.1rem;
    margin-bottom: 1rem;
    border-radius: 0.5rem;
    border-left: 3px solid var(--color-primary);
    background: var(--color-surface-alt);
}

.warning-box.subtle { border-left-color: #b45309; }
.warning-box h2 { margin: 0 0 0.4rem; font-size: 0.88rem; }
.warning-box p { margin: 0 0 0.4rem; font-size: 0.82rem; color: var(--color-text-muted); }
.warning-box p:last-child { margin-bottom: 0; }
.warning-box .small { font-size: 0.76rem; }

.tabs {
    display: flex;
    gap: 0.25rem;
    margin-bottom: 1rem;
    border-bottom: 1px solid var(--color-border);
    flex-wrap: wrap;
}

.tab {
    padding: 0.5rem 0.9rem;
    border: none;
    background: none;
    color: var(--color-text-muted);
    font-size: 0.84rem;
    cursor: pointer;
    border-bottom: 2px solid transparent;
}

.tab.active { color: var(--color-text); border-bottom-color: var(--color-primary); font-weight: 600; }

.card { margin-bottom: 1rem; }
.card-header h3 { margin: 0; font-size: 0.9rem; }

.settings-card, .load-card { padding: 1.1rem 1.25rem; }
.settings-card h3, .load-card h3 { margin: 0 0 0.3rem; font-size: 0.9rem; }
.settings-card h3:not(:first-child) { margin-top: 1.25rem; }

.danger-hint { color: #a04000; }

.load-row { display: flex; gap: 0.8rem; align-items: flex-end; flex-wrap: wrap; }
.load-row .field { margin-bottom: 0; }

.stat-row { display: flex; gap: 0.75rem; margin-bottom: 1rem; flex-wrap: wrap; }

.stat {
    display: flex;
    flex-direction: column;
    padding: 0.7rem 1.1rem;
    border: 1px solid var(--color-border);
    border-radius: 0.5rem;
    background: var(--color-surface);
    min-width: 12rem;
}

.stat-label { font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--color-text-muted); }
.stat-value { font-size: 1.2rem; font-weight: 600; font-variant-numeric: tabular-nums; }
.stat-note { font-size: 0.68rem; color: var(--color-text-muted); }

.center { text-align: center; }
td.strong { font-weight: 600; }
.dim { opacity: 0.55; }

.verify {
    display: inline-block;
    margin-left: 0.4rem;
    font-size: 0.6rem;
    padding: 0.05rem 0.3rem;
    border-radius: 3px;
    background: #fdf0ea;
    color: #a04000;
    font-weight: 600;
    cursor: help;
}

.problem-list { margin: 0.4rem 0 0; padding-left: 1.1rem; display: flex; flex-direction: column; gap: 0.2rem; }

.error { color: var(--color-danger); font-size: 0.76rem; }

/* ── Determinación de cuentas ────────────────────────────────────────── */

.determination-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(20rem, 1fr));
    gap: 0.9rem;
    padding: 0 1.1rem 1.1rem;
}

.req {
    margin-left: 0.35rem;
    font-size: 0.6rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    padding: 0.05rem 0.3rem;
    border-radius: 3px;
    background: #fdf0ea;
    color: #a04000;
}

/* Los select dentro de la tabla: angostos por defecto para que la tabla no
   se desborde, y anchos al enfocarlos para poder leer el nombre completo
   de la cuenta. */
.inline-select {
    width: 100%;
    max-width: 22rem;
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.25rem 0.4rem;
    font-size: 0.78rem;
    color: var(--color-text);
}

.inline-select:focus {
    outline: 2px solid var(--color-primary);
    outline-offset: 1px;
}

/* Una cuenta que falta y que hace falta: se ve sin tener que leer la lista
   de arriba. */
.inline-select.unset {
    border-color: #d08a55;
    background: #fdf6f1;
}

.save-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
    padding: 0.9rem 1.1rem;
    position: sticky;
    bottom: 0.75rem;
}

/* Un enlace que cambia de pestaña, no de página: tiene que ser un <button>
   para ser accesible con teclado, y parecer un enlace. */
.link-btn {
    border: none;
    background: none;
    padding: 0;
    font: inherit;
    color: var(--color-primary);
    text-decoration: underline;
    cursor: pointer;
}
</style>
