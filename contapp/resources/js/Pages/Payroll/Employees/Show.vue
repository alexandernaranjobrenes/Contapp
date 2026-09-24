<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import { formatMoney } from '../../../Utils/money';

const props = defineProps({
    employee: { type: Object, required: true },
    vacationBalance: { type: String, default: '0.0000' },
    vacationMovements: { type: Array, default: () => [] },
    deductions: { type: Array, default: () => [] },
    personnelActions: { type: Array, default: () => [] },
    options: { type: Object, required: true },
    costCenters: { type: Array, default: () => [] },
});

const page = usePage();

const photoForm = useForm({ photo: null });
const photoInput = ref(null);

function pickPhoto(event) {
    const file = event.target.files?.[0];
    if (! file) return;

    photoForm.photo = file;
    photoForm.post(route('employees.photo', props.employee.id), {
        preserveScroll: true,
        forceFormData: true,
        onFinish: () => { if (photoInput.value) photoInput.value.value = ''; },
    });
}

const balance = computed(() => parseFloat(props.vacationBalance) || 0);

// Lo que valdrían hoy los días acumulados si hubiera que pagarlos. El saldo
// en días es una cifra de recursos humanos; esta es la de contabilidad, y es
// la que hace visible que las vacaciones sin disfrutar son un pasivo.
const vacationLiability = computed(
    () => balance.value * (parseFloat(props.employee.daily_rate) || 0)
);

const liveDeductions = computed(() => props.deductions.filter((d) => d.status === 'active'));
</script>

<template>
    <Head :title="`${employee.code} — ${employee.full_name}`" />

    <AppLayout :title="employee.full_name">
        <template #actions>
            <Link :href="route('employees.index')" class="btn btn-ghost">← Empleados</Link>
            <Link :href="route('personnel-actions.index')" class="btn btn-ghost">Acciones de personal</Link>
        </template>

        <div v-if="page.props.errors?.employee" class="flash flash-error">{{ page.props.errors.employee }}</div>

        <div class="profile card">
            <div class="photo-block">
                <img v-if="employee.photo_url" :src="employee.photo_url" class="photo" alt="">
                <div v-else class="photo photo-empty">{{ employee.first_name?.[0] }}{{ employee.last_name1?.[0] }}</div>

                <label class="btn btn-ghost btn-sm file-btn">
                    {{ employee.photo_url ? 'Cambiar foto' : 'Subir foto' }}
                    <input ref="photoInput" type="file" accept="image/*" class="file-input" @change="pickPhoto">
                </label>
                <span v-if="photoForm.errors.photo" class="error">{{ photoForm.errors.photo }}</span>
            </div>

            <div class="profile-data">
                <h2>{{ employee.full_name }}</h2>
                <p class="muted">
                    {{ employee.code }} · {{ employee.position ?? 'Sin puesto' }}
                    <span v-if="employee.department"> · {{ employee.department }}</span>
                </p>

                <dl class="facts">
                    <div><dt>Identificación</dt><dd>{{ employee.identification_number }}</dd></div>
                    <div><dt>Asegurado CCSS</dt><dd>{{ employee.ccss_number ?? '—' }}</dd></div>
                    <div><dt>Ingreso</dt><dd>{{ employee.hire_date }}</dd></div>
                    <div><dt>Antigüedad</dt><dd>{{ employee.years_of_service }} año(s)</dd></div>
                    <div><dt>Centro de costo</dt><dd>{{ employee.cost_center ?? '—' }}</dd></div>
                    <div><dt>Jornada</dt><dd>{{ options.journeyTypes[employee.journey_type] }}</dd></div>
                    <div><dt>Estado</dt><dd>{{ options.statuses[employee.status] }}</dd></div>
                    <div v-if="employee.termination_date"><dt>Salida</dt><dd>{{ employee.termination_date }}</dd></div>
                </dl>
            </div>

            <div class="pay-block">
                <div class="pay-row">
                    <span class="pay-label">Salario base ({{ options.salaryTypes[employee.salary_type] }})</span>
                    <span class="pay-value">{{ formatMoney(employee.base_salary) }}</span>
                </div>
                <div class="pay-row">
                    <span class="pay-label">Equivalente mensual</span>
                    <span class="pay-value">{{ formatMoney(employee.monthly_salary) }}</span>
                </div>
                <div class="pay-row">
                    <span class="pay-label">Valor del día</span>
                    <span class="pay-value">{{ formatMoney(employee.daily_rate) }}</span>
                </div>
                <div class="pay-row">
                    <span class="pay-label">Valor de la hora ordinaria</span>
                    <span class="pay-value">{{ formatMoney(employee.hourly_rate) }}</span>
                </div>
                <p class="hint small">
                    El valor de la hora sale del salario mensual y de la jornada declarada; es la base con la que
                    se pagan las horas extra.
                </p>
            </div>
        </div>

        <div class="grid-2">
            <section class="card">
                <div class="card-header">
                    <h3>Vacaciones</h3>
                    <Link :href="route('vacations.index')" class="btn btn-ghost btn-sm">Registrar movimiento</Link>
                </div>

                <div class="balance-block">
                    <div>
                        <span class="balance-value">{{ balance.toFixed(2) }}</span>
                        <span class="balance-label">día(s) acumulado(s)</span>
                    </div>
                    <div class="balance-money">
                        <span class="muted small">Valor si se pagaran hoy</span>
                        <strong>{{ formatMoney(vacationLiability) }}</strong>
                    </div>
                </div>

                <p class="hint small">
                    El saldo es la suma de los movimientos, no un campo guardado: cada día acumulado se puede
                    rastrear hasta el período que lo acreditó.
                </p>

                <div class="table-scroll compact">
                    <table>
                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th>Tipo</th>
                                <th class="right">Días</th>
                                <th>Detalle</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="m in vacationMovements" :key="m.id">
                                <td class="num small">{{ m.movement_date }}</td>
                                <td class="small">{{ m.type_label }}</td>
                                <td class="right num" :class="{ negative: m.days < 0 }">{{ m.days.toFixed(4) }}</td>
                                <td class="muted small">
                                    <template v-if="m.from_date">{{ m.from_date }} a {{ m.to_date }}</template>
                                    <template v-else>{{ m.notes ?? '—' }}</template>
                                </td>
                            </tr>
                            <tr v-if="!vacationMovements.length">
                                <td colspan="4" class="muted empty-row">
                                    Sin movimientos. Las acreditaciones aparecen solas al calcular cada planilla.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="card">
                <div class="card-header">
                    <h3>Deducciones y préstamos</h3>
                    <Link :href="route('employee-deductions.index')" class="btn btn-ghost btn-sm">Administrar</Link>
                </div>

                <p v-if="liveDeductions.length" class="hint small">
                    Se rebajan solas en cada planilla, en orden de prioridad, sin dejar el neto en negativo.
                </p>

                <div class="table-scroll compact">
                    <table>
                        <thead>
                            <tr>
                                <th>Tipo</th>
                                <th>Descripción</th>
                                <th class="right">Cuota</th>
                                <th class="right">Saldo</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="d in deductions" :key="d.id">
                                <td class="small">{{ d.type_label }}</td>
                                <td class="small">{{ d.description }}</td>
                                <td class="right num small">
                                    <template v-if="d.calculation === 'percentage'">{{ d.installment_percentage }}%</template>
                                    <template v-else>{{ formatMoney(d.installment_amount) }}</template>
                                </td>
                                <td class="right num">
                                    <template v-if="d.balance !== null">{{ formatMoney(d.balance) }}</template>
                                    <span v-else class="muted small">indefinida</span>
                                </td>
                                <td class="small">{{ d.status }}</td>
                            </tr>
                            <tr v-if="!deductions.length">
                                <td colspan="5" class="muted empty-row">Sin obligaciones registradas.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <section class="card">
            <div class="card-header">
                <h3>Historial laboral</h3>
                <Link :href="route('personnel-actions.index')" class="btn btn-ghost btn-sm">Nueva acción</Link>
            </div>

            <p class="hint small">
                Cada cambio de la ficha queda acá con su vigencia, su motivo y quién lo aprobó. Es lo que permite
                explicar, meses después, desde cuándo rige un aumento y quién lo autorizó.
            </p>

            <div class="table-scroll compact">
                <table>
                    <thead>
                        <tr>
                            <th>Vigencia</th>
                            <th>Acción</th>
                            <th>Antes</th>
                            <th>Después</th>
                            <th>Motivo</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="a in personnelActions" :key="a.id">
                            <td class="num small">{{ a.effective_date }}</td>
                            <td class="small">{{ a.action_label }}</td>
                            <td class="num small muted">{{ a.previous_value ?? '—' }}</td>
                            <td class="num small">{{ a.new_value ?? '—' }}</td>
                            <td class="muted small">{{ a.reason ?? '—' }}</td>
                            <td class="small">{{ a.status_label }}</td>
                        </tr>
                        <tr v-if="!personnelActions.length">
                            <td colspan="6" class="muted empty-row">Sin acciones registradas.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </AppLayout>
</template>

<style scoped>
.profile {
    display: flex;
    gap: 1.5rem;
    padding: 1.25rem;
    margin-bottom: 1rem;
    flex-wrap: wrap;
}

.photo-block {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.5rem;
}

.photo {
    width: 7.5rem;
    height: 7.5rem;
    object-fit: cover;
    border-radius: 0.5rem;
    border: 1px solid var(--color-border);
}

.photo-empty {
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2rem;
    font-weight: 600;
    color: var(--color-text-muted);
    background: var(--color-surface-alt);
}

.file-btn { position: relative; overflow: hidden; cursor: pointer; }
.file-input { position: absolute; inset: 0; opacity: 0; width: 100%; cursor: pointer; }

.profile-data { flex: 1; min-width: 18rem; }
.profile-data h2 { margin: 0; font-size: 1.15rem; }
.profile-data > p { margin: 0.15rem 0 0.9rem; font-size: 0.85rem; }

.facts {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(11rem, 1fr));
    gap: 0.6rem 1rem;
    margin: 0;
}

.facts dt {
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--color-text-muted);
}

.facts dd { margin: 0; font-size: 0.86rem; }

.pay-block {
    min-width: 17rem;
    padding: 0.9rem 1rem;
    border-radius: 0.5rem;
    background: var(--color-surface-alt);
}

.pay-row {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    padding: 0.25rem 0;
    font-size: 0.85rem;
}

.pay-label { color: var(--color-text-muted); }
.pay-value { font-variant-numeric: tabular-nums; font-weight: 600; }

.grid-2 {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(24rem, 1fr));
    gap: 1rem;
    margin-bottom: 1rem;
}

.card-header h3 { margin: 0; font-size: 0.9rem; }

.balance-block {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 1rem;
    padding: 0.9rem 1.1rem 0.2rem;
    flex-wrap: wrap;
}

.balance-value { font-size: 1.9rem; font-weight: 600; font-variant-numeric: tabular-nums; }
.balance-label { margin-left: 0.4rem; color: var(--color-text-muted); font-size: 0.82rem; }
.balance-money { display: flex; flex-direction: column; align-items: flex-end; }
.balance-money strong { font-variant-numeric: tabular-nums; }

.profile .hint, .card > .hint { padding: 0 1.1rem; }

.table-scroll.compact { max-height: 22rem; }

.negative { color: var(--color-danger); }

.error { color: var(--color-danger); font-size: 0.74rem; }
</style>
