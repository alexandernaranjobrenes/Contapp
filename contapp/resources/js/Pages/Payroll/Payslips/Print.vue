<script setup>
import { Head } from '@inertiajs/vue3';
import { formatMoney } from '../../../Utils/money';

// Comprobante de pago: deliberadamente SIN AppLayout, igual que la
// presentación de un asiento. Es la superficie pensada para imprimirse o
// entregarse, no para navegar.
defineProps({
    company: { type: Object, required: true },
    employee: { type: Object, required: true },
    period: { type: Object, required: true },
    entry: { type: Object, required: true },
    earnings: { type: Array, default: () => [] },
    deductions: { type: Array, default: () => [] },
    employerLines: { type: Array, default: () => [] },
});

function printNow() {
    window.print();
}
</script>

<template>
    <Head :title="`Comprobante — ${employee.name} — ${period.name}`" />

    <div class="page">
        <div class="toolbar no-print">
            <a href="javascript:history.back()" class="btn btn-ghost">← Volver</a>
            <span class="spacer" />
            <button type="button" class="btn btn-primary" @click="printNow">🖶 Imprimir</button>
        </div>

        <div class="slip">
            <header class="slip-header">
                <div class="company">
                    <img v-if="company.logo_url" :src="company.logo_url" class="logo" alt="">
                    <div>
                        <div class="company-name">{{ company.name }}</div>
                        <div class="meta" v-if="company.tax_id">Cédula jurídica: {{ company.tax_id }}</div>
                    </div>
                </div>

                <div class="doc-title">
                    <h1>Comprobante de pago</h1>
                    <div class="meta">{{ period.name }}</div>
                    <div class="meta">Del {{ period.start_date }} al {{ period.end_date }}</div>
                    <div class="meta">Fecha de pago: {{ period.payment_date }}</div>
                </div>
            </header>

            <section class="worker">
                <img v-if="employee.photo_url" :src="employee.photo_url" class="photo" alt="">

                <dl class="worker-facts">
                    <div><dt>Trabajador</dt><dd>{{ employee.name }}</dd></div>
                    <div><dt>Código</dt><dd>{{ employee.code }}</dd></div>
                    <div><dt>Identificación</dt><dd>{{ employee.identification }}</dd></div>
                    <div><dt>Asegurado CCSS</dt><dd>{{ employee.ccss_number ?? '—' }}</dd></div>
                    <div><dt>Puesto</dt><dd>{{ employee.position ?? '—' }}</dd></div>
                    <div><dt>Centro de costo</dt><dd>{{ employee.cost_center }}</dd></div>
                    <div><dt>Ingreso</dt><dd>{{ employee.hire_date }}</dd></div>
                    <div><dt>Días del período</dt><dd>{{ entry.days_worked }}</dd></div>
                </dl>
            </section>

            <div class="columns">
                <section class="col">
                    <h2>Ingresos</h2>
                    <table>
                        <thead>
                            <tr>
                                <th>Concepto</th>
                                <th class="right">Cant.</th>
                                <th class="right">Monto</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(l, i) in earnings" :key="i">
                                <td>{{ l.name }}</td>
                                <td class="right num">{{ l.quantity ?? '' }}</td>
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
                </section>

                <section class="col">
                    <h2>Deducciones</h2>
                    <table>
                        <thead>
                            <tr>
                                <th>Concepto</th>
                                <th class="right">Base</th>
                                <th class="right">%</th>
                                <th class="right">Monto</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- La base y la tasa van a la vista a propósito:
                                 sin ellas el trabajador no puede verificar
                                 su propio rebajo, que es para lo que existe
                                 este documento. -->
                            <tr v-for="(l, i) in deductions" :key="i">
                                <td>{{ l.name }}</td>
                                <td class="right num small">{{ l.base_amount ? formatMoney(l.base_amount) : '' }}</td>
                                <td class="right num small">{{ l.rate ?? '' }}</td>
                                <td class="right num">{{ formatMoney(l.amount) }}</td>
                            </tr>
                            <tr v-if="!deductions.length">
                                <td colspan="4" class="muted">Sin deducciones.</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="3">Total deducciones</td>
                                <td class="right num">{{ formatMoney(entry.total_deductions) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </section>
            </div>

            <section class="net">
                <div>
                    <span class="net-label">Neto a pagar</span>
                    <span class="net-meta">
                        {{ employee.payment_method === 'transferencia' ? `Transferencia · ${employee.bank_account ?? 'sin cuenta'}` : employee.payment_method }}
                    </span>
                </div>
                <span class="net-value">{{ formatMoney(entry.net_pay) }}</span>
            </section>

            <section v-if="employerLines.length" class="employer">
                <h2>Aportes y provisiones del patrono</h2>
                <p class="note">
                    Estos montos <strong>no se le rebajan al trabajador</strong>: los paga la empresa además del
                    salario. Se muestran para que el costo real del puesto sea visible.
                </p>

                <table>
                    <thead>
                        <tr>
                            <th>Concepto</th>
                            <th class="right">Base</th>
                            <th class="right">%</th>
                            <th class="right">Monto</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(l, i) in employerLines" :key="i">
                            <td>{{ l.name }}</td>
                            <td class="right num small">{{ l.base_amount ? formatMoney(l.base_amount) : '' }}</td>
                            <td class="right num small">{{ l.rate ?? '' }}</td>
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
            </section>

            <footer class="slip-footer">
                <div class="sign">
                    <div class="sign-line"></div>
                    <span>Recibí conforme — {{ employee.name }}</span>
                </div>
                <div class="sign">
                    <div class="sign-line"></div>
                    <span>Por la empresa</span>
                </div>
            </footer>
        </div>
    </div>
</template>

<style scoped>
.page {
    min-height: 100vh;
    background: var(--color-surface-alt, #f3f4f6);
    padding: 1.5rem;
}

.toolbar {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    max-width: 60rem;
    margin: 0 auto 1rem;
}

.spacer { flex: 1; }

.slip {
    max-width: 60rem;
    margin: 0 auto;
    background: #fff;
    color: #111;
    border-radius: 0.5rem;
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.12);
    padding: 2rem 2.25rem;
}

.slip-header {
    display: flex;
    justify-content: space-between;
    gap: 2rem;
    align-items: flex-start;
    border-bottom: 2px solid #111;
    padding-bottom: 0.9rem;
}

.company { display: flex; gap: 0.8rem; align-items: center; }
.logo { max-height: 3.2rem; max-width: 9rem; }
.company-name { font-weight: 700; font-size: 1.05rem; }
.meta { font-size: 0.74rem; color: #555; }

.doc-title { text-align: right; }
.doc-title h1 { margin: 0 0 0.2rem; font-size: 1.05rem; text-transform: uppercase; letter-spacing: 0.05em; }

.worker {
    display: flex;
    gap: 1.25rem;
    align-items: flex-start;
    padding: 1rem 0;
    border-bottom: 1px solid #ddd;
}

.photo { width: 4.5rem; height: 4.5rem; object-fit: cover; border-radius: 0.3rem; border: 1px solid #ddd; }

.worker-facts {
    flex: 1;
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 0.5rem 1rem;
    margin: 0;
}

.worker-facts dt { font-size: 0.64rem; text-transform: uppercase; letter-spacing: 0.04em; color: #666; }
.worker-facts dd { margin: 0; font-size: 0.82rem; }

.columns { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; padding: 1rem 0; }

.col h2, .employer h2 {
    margin: 0 0 0.4rem;
    font-size: 0.74rem;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: #444;
}

table { width: 100%; border-collapse: collapse; font-size: 0.8rem; }
th { text-align: left; font-size: 0.66rem; text-transform: uppercase; color: #666; border-bottom: 1px solid #ccc; padding: 0.25rem 0.3rem; }
td { padding: 0.25rem 0.3rem; border-bottom: 1px solid #f0f0f0; }
tfoot td { font-weight: 700; border-top: 1px solid #999; border-bottom: none; }

.right { text-align: right; }
.num { font-variant-numeric: tabular-nums; }
.small { font-size: 0.72rem; color: #555; }
.muted { color: #888; }

.net {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
    padding: 0.85rem 1.1rem;
    background: #111;
    color: #fff;
    border-radius: 0.35rem;
    margin: 0.5rem 0 1.25rem;
}

.net-label { display: block; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.06em; }
.net-meta { display: block; font-size: 0.72rem; opacity: 0.75; }
.net-value { font-size: 1.5rem; font-weight: 700; font-variant-numeric: tabular-nums; }

.employer { padding-top: 0.5rem; border-top: 1px dashed #ccc; }
.note { font-size: 0.72rem; color: #555; margin: 0 0 0.5rem; }

.slip-footer { display: flex; gap: 3rem; margin-top: 2.5rem; }
.sign { flex: 1; text-align: center; font-size: 0.72rem; color: #555; }
.sign-line { border-top: 1px solid #333; margin-bottom: 0.3rem; }

@media print {
    .no-print { display: none !important; }
    .page { padding: 0; background: #fff; }
    .slip { box-shadow: none; border-radius: 0; max-width: 100%; padding: 0; }
}

@media (max-width: 720px) {
    .columns { grid-template-columns: 1fr; }
    .worker-facts { grid-template-columns: repeat(2, 1fr); }
}
</style>
