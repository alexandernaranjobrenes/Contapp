<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import { formatMoney } from '../../../Utils/money';

const props = defineProps({
    company: { type: Object, required: true },
    employee: { type: Object, required: true },
    period: { type: Object, required: true },
    entry: { type: Object, required: true },
    earnings: { type: Array, default: () => [] },
    deductions: { type: Array, default: () => [] },
    employerLines: { type: Array, default: () => [] },
});
</script>

<template>
    <Head :title="`Comprobante — ${employee.name}`" />

    <AppLayout :title="`Comprobante — ${employee.name}`">
        <template #actions>
            <Link :href="route('payroll-periods.show', period.id)" class="btn btn-ghost">← Planilla</Link>
            <a :href="route('payslips.print', entry.id)" target="_blank" class="btn btn-primary">🖶 Ver e imprimir</a>
        </template>

        <p class="hint">
            El comprobante muestra la <strong>base</strong> y la <strong>tasa</strong> de cada rebajo, congeladas
            al calcular. Es lo que permite que el trabajador verifique su propio rebajo y que una planilla vieja
            se pueda reimprimir idéntica años después, aunque las tasas hayan cambiado.
        </p>

        <div class="grid">
            <section class="card">
                <div class="card-header"><h3>Ingresos</h3></div>
                <div class="table-scroll compact">
                    <table>
                        <thead>
                            <tr><th>Concepto</th><th class="right">Cantidad</th><th class="right">Monto</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="(l, i) in earnings" :key="i">
                                <td>{{ l.name }}</td>
                                <td class="right num small">{{ l.quantity ?? '—' }}</td>
                                <td class="right num">{{ formatMoney(l.amount) }}</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="2">Total devengado</td>
                                <td class="right num">{{ formatMoney(entry.total_earnings) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </section>

            <section class="card">
                <div class="card-header"><h3>Deducciones</h3></div>
                <div class="table-scroll compact">
                    <table>
                        <thead>
                            <tr><th>Concepto</th><th class="right">Base</th><th class="right">Tasa %</th><th class="right">Monto</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="(l, i) in deductions" :key="i">
                                <td>{{ l.name }}</td>
                                <td class="right num small muted">{{ l.base_amount ? formatMoney(l.base_amount) : '—' }}</td>
                                <td class="right num small muted">{{ l.rate ?? '—' }}</td>
                                <td class="right num">{{ formatMoney(l.amount) }}</td>
                            </tr>
                            <tr v-if="!deductions.length">
                                <td colspan="4" class="muted empty-row">Sin deducciones.</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="3">Total deducciones</td>
                                <td class="right num">{{ formatMoney(entry.total_deductions) }}</td>
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

            <div class="table-scroll compact">
                <table>
                    <thead>
                        <tr><th>Concepto</th><th class="right">Base</th><th class="right">Tasa %</th><th class="right">Monto</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="(l, i) in employerLines" :key="i">
                            <td>{{ l.name }} <span class="muted small">({{ l.kind_label }})</span></td>
                            <td class="right num small muted">{{ l.base_amount ? formatMoney(l.base_amount) : '—' }}</td>
                            <td class="right num small muted">{{ l.rate ?? '—' }}</td>
                            <td class="right num">{{ formatMoney(l.amount) }}</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3">Costo total del puesto en el período</td>
                            <td class="right num">{{ formatMoney(entry.employer_cost) }}</td>
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
    grid-template-columns: repeat(auto-fit, minmax(24rem, 1fr));
    gap: 1rem;
    margin-bottom: 1rem;
}

.card-header h3 { margin: 0; font-size: 0.9rem; }
.table-scroll.compact { max-height: none; }

tfoot td { font-weight: 600; border-top: 2px solid var(--color-border); }

.net {
    display: flex;
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
