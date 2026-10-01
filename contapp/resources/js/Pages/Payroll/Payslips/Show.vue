<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import { confirmAction } from '../../../Utils/confirm';
import { formatMoney } from '../../../Utils/money';
import { ArrowLeftIcon, DownloadIcon, MailIcon, PrinterIcon } from '@lucide/vue';

const props = defineProps({
    company: { type: Object, required: true },
    employee: { type: Object, required: true },
    period: { type: Object, required: true },
    entry: { type: Object, required: true },
    earnings: { type: Array, default: () => [] },
    deductions: { type: Array, default: () => [] },
    employerLines: { type: Array, default: () => [] },
});

const emailForm = useForm({});

// Se confirma antes de mandar: el comprobante lleva el salario de la persona,
// y un envío que se dispara por error no se puede deshacer.
function sendEmail() {
    confirmAction({
        title: 'Enviar comprobante',
        message: `El comprobante de pago se envía a ${props.employee.email}.`,
        confirmLabel: 'Enviar',
        onConfirm: () => emailForm.post(route('payslips.email', props.entry.id), { preserveScroll: true }),
    });
}
</script>

<template>
    <Head :title="`Comprobante — ${employee.name}`" />

    <AppLayout :title="`Comprobante — ${employee.name}`">
        <div class="view-toolbar">
            <Link :href="route('payroll-periods.show', period.id)" class="btn btn-ghost"><ArrowLeftIcon /> Planilla</Link>
            <div class="view-actions">
                <a :href="route('payslips.pdf', entry.id)" class="btn btn-ghost"><DownloadIcon /> Descargar PDF</a>
                <!--
                    Sin correo en la ficha el botón queda deshabilitado con el
                    motivo a la vista: ofrecerlo y que falle al presionarlo obliga a
                    adivinar qué pasó.
                -->
                <button
                    type="button"
                    class="btn btn-ghost"
                    :disabled="! employee.email || emailForm.processing"
                    :title="employee.email ? `Enviar a ${employee.email}` : 'La ficha no tiene correo electrónico'"
                    @click="sendEmail"
                >
                    <MailIcon /> Enviar por correo
                </button>
                <a :href="route('payslips.print', entry.id)" target="_blank" class="btn btn-primary"><PrinterIcon /> Ver e imprimir</a>
            </div>
        </div>

        <p class="hint">
            El comprobante muestra la <strong>base</strong> y la <strong>tasa</strong> de cada rebajo, congeladas
            al calcular. Es lo que permite que el trabajador verifique su propio rebajo y que una planilla vieja
            se pueda reimprimir idéntica años después, aunque las tasas hayan cambiado.
        </p>

        <div class="grid">
            <section class="card">
                <div class="card-header"><h3>Ingresos</h3></div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr><th>Concepto</th><th class="num">Cantidad</th><th class="num">Monto</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="(l, i) in earnings" :key="i">
                                <td>{{ l.name }}</td>
                                <td data-label="Cantidad" class="num small">{{ l.quantity ?? '—' }}</td>
                                <td data-label="Monto" class="num">{{ formatMoney(l.amount) }}</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="2">Total devengado</td>
                                <td data-label="Total devengado" class="num">{{ formatMoney(entry.total_earnings) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </section>

            <section class="card">
                <div class="card-header"><h3>Deducciones</h3></div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr><th>Concepto</th><th class="num">Base</th><th class="num">Tasa %</th><th class="num">Monto</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="(l, i) in deductions" :key="i">
                                <td>{{ l.name }}</td>
                                <td data-label="Base" class="num small muted">{{ l.base_amount ? formatMoney(l.base_amount) : '—' }}</td>
                                <td data-label="Tasa %" class="num small muted">{{ l.rate ?? '—' }}</td>
                                <td data-label="Monto" class="num">{{ formatMoney(l.amount) }}</td>
                            </tr>
                            <tr v-if="!deductions.length">
                                <td colspan="4" class="muted empty-row">Sin deducciones.</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="3">Total deducciones</td>
                                <td data-label="Total deducciones" class="num">{{ formatMoney(entry.total_deductions) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </section>
        </div>

        <div class="net card">
            <div>
                <span class="net-label">Neto a pagar</span>
                <span class="muted small">
                    Base de cargas {{ formatMoney(entry.ccss_base) }} · base del impuesto {{ formatMoney(entry.income_tax_base) }}
                </span>
            </div>
            <span class="net-value">{{ formatMoney(entry.net_pay) }}</span>
        </div>

        <section v-if="employerLines.length" class="card">
            <div class="card-header"><h3>Aportes y provisiones del patrono</h3></div>

            <p class="hint small">
                No se le rebajan al trabajador: los paga la empresa <em>además</em> del salario. Sin este bloque,
                un puesto parece costar mucho menos de lo que cuesta.
            </p>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr><th>Concepto</th><th class="num">Base</th><th class="num">Tasa %</th><th class="num">Monto</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="(l, i) in employerLines" :key="i">
                            <td>{{ l.name }} <span class="muted small">({{ l.kind_label }})</span></td>
                            <td data-label="Base" class="num small muted">{{ l.base_amount ? formatMoney(l.base_amount) : '—' }}</td>
                            <td data-label="Tasa %" class="num small muted">{{ l.rate ?? '—' }}</td>
                            <td data-label="Monto" class="num">{{ formatMoney(l.amount) }}</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3">Costo total del puesto en el período</td>
                            <td data-label="Costo total" class="num">{{ formatMoney(entry.employer_cost) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>
    </AppLayout>
</template>

<style scoped>
.grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 24rem), 1fr));
    gap: 1rem;
    margin-bottom: 1rem;
}

.card-header h3 { margin: 0; font-size: 0.9rem; }

tfoot td { font-weight: 600; border-top: 2px solid var(--color-border); }

.net {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 1rem 1.25rem;
    margin-bottom: 1rem;
}

.net-label {
    display: block;
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--color-text-muted);
}

.net-value { font-size: 1.65rem; font-weight: 700; font-variant-numeric: tabular-nums; }

.card > .hint { padding: 0 1.1rem; }
</style>
