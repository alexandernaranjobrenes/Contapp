<script setup>
import { Head } from '@inertiajs/vue3';
import { formatMoney } from '../../Utils/money';

// Pantalla de "Presentar documento": deliberadamente SIN AppLayout (sin menú
// lateral ni barra superior) — es la superficie pensada para mostrarse tal
// cual, exportarse o imprimirse, no para navegar/editar (eso vive en
// Show.vue/Create.vue, que sí tienen todo el resto de acciones). Se abre en
// una pestaña aparte para no perder el contexto de la pantalla de origen.
const props = defineProps({
    entry: { type: Object, required: true },
    header: { type: Object, required: true },
});

const statusLabels = { draft: 'Preliminar', posted: 'Contabilizado', voided: 'Anulado' };

const totalDebit = props.entry.lines.reduce((sum, l) => sum + (parseFloat(l.debit) || 0), 0).toFixed(2);
const totalCredit = props.entry.lines.reduce((sum, l) => sum + (parseFloat(l.credit) || 0), 0).toFixed(2);

function printNow() {
    window.print();
}
</script>

<template>
    <Head :title="`Presentación — ${entry.label}`" />

    <div class="page">
        <div class="toolbar no-print">
            <a href="javascript:history.back()" class="btn btn-ghost">← Volver</a>
            <span class="spacer" />
            <a :href="route('journal-entries.export', entry.id)" class="btn btn-ghost">⤓ Exportar XLSX</a>
            <a :href="route('journal-entries.export-pdf', entry.id)" class="btn btn-ghost">⤓ Exportar PDF</a>
            <button type="button" class="btn btn-primary" @click="printNow">🖶 Imprimir</button>
        </div>

        <div class="voucher">
            <header class="voucher-header">
                <div class="voucher-header-top">
                    <div class="company-block">
                        <img v-if="header.logo_url" :src="header.logo_url" class="logo" alt="">
                        <div>
                            <div class="company-name">{{ header.company_name }}</div>
                            <div class="company-meta" v-if="header.tax_id">Cédula jurídica: {{ header.tax_id }}</div>
                            <div class="company-meta" v-if="header.address">{{ header.address }}</div>
                        </div>
                    </div>
                    <div class="doc-block">
                        <div class="doc-label">{{ entry.label }}</div>
                        <span class="badge" :class="{ 'badge-warning': entry.status === 'draft', 'badge-success': entry.status === 'posted', 'badge-neutral': entry.status === 'voided' }">
                            {{ statusLabels[entry.status] ?? entry.status }}
                        </span>
                    </div>
                </div>

                <div v-if="entry.description" class="description-block">
                    <span class="description-label">Detalle del registro</span>
                    <p class="description">{{ entry.description }}</p>
                </div>
            </header>

            <dl class="meta-grid">
                <dt>Fecha de contabilización</dt><dd>{{ entry.posting_date }}</dd>
                <dt>Fecha de documento</dt><dd>{{ entry.document_date }}</dd>
            </dl>

            <table class="lines-table">
                <thead>
                    <tr>
                        <th>Cuenta / socio</th>
                        <th>Centro de costo</th>
                        <th>Moneda</th>
                        <th class="num">Débito</th>
                        <th class="num">Crédito</th>
                        <th>Descripción</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(line, i) in entry.lines" :key="i">
                        <td>{{ line.owner }}</td>
                        <td class="muted">{{ line.cost_center ?? '—' }}</td>
                        <td>{{ line.currency_code }}</td>
                        <td class="num">{{ formatMoney(line.debit) }}</td>
                        <td class="num">{{ formatMoney(line.credit) }}</td>
                        <td class="muted">{{ line.description }}</td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="3">Total</td>
                        <td class="num">{{ formatMoney(totalDebit) }}</td>
                        <td class="num">{{ formatMoney(totalCredit) }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>

            <footer class="voucher-footer">
                Generado por {{ header.generated_by_name }} el {{ header.generated_at }} — CONTAPP
            </footer>
        </div>
    </div>
</template>

<style scoped>
.page {
    min-height: 100vh;
    background: var(--color-bg);
    padding: 1.5rem 1rem 3rem;
}

.toolbar {
    max-width: 900px;
    margin: 0 auto 1.25rem;
    display: flex;
    align-items: center;
    gap: 0.6rem;
}

.spacer {
    flex: 1;
}

.voucher {
    max-width: 900px;
    margin: 0 auto;
    background: var(--color-surface);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-md);
    padding: 2.25rem 2.5rem;
}

.voucher-header {
    padding-bottom: 1.25rem;
    margin-bottom: 1.25rem;
    border-bottom: 3px solid var(--color-primary);
}

.voucher-header-top {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1.5rem;
}

.description-block {
    margin-top: 1.1rem;
    padding: 0.75rem 0.9rem;
    background: var(--color-surface-alt);
    border-left: 3px solid var(--color-primary);
    border-radius: 0 var(--radius-sm) var(--radius-sm) 0;
}

.description-label {
    display: block;
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--color-text-muted);
    margin-bottom: 0.2rem;
}

.company-block {
    display: flex;
    align-items: flex-start;
    gap: 0.85rem;
}

.logo {
    width: 52px;
    height: 52px;
    object-fit: contain;
    border-radius: var(--radius-sm);
}

.company-name {
    font-size: 1.15rem;
    font-weight: 800;
    color: var(--color-primary);
    letter-spacing: -0.01em;
}

.company-meta {
    font-size: 0.78rem;
    color: var(--color-text-muted);
}

.doc-block {
    text-align: right;
    flex-shrink: 0;
}

.doc-label {
    font-size: 1.3rem;
    font-weight: 800;
    font-variant-numeric: tabular-nums;
    color: var(--color-text);
    margin-bottom: 0.35rem;
}

.description {
    font-size: 0.92rem;
    font-weight: 600;
    color: var(--color-text);
    margin: 0;
}

.meta-grid {
    display: grid;
    grid-template-columns: repeat(2, auto 1fr);
    gap: 0.3rem 1.5rem;
    font-size: 0.85rem;
    margin-bottom: 1.5rem;
}

.meta-grid dt {
    color: var(--color-text-muted);
    font-weight: 600;
}

.meta-grid dd {
    margin: 0;
}

.lines-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.85rem;
}

.lines-table thead th {
    text-align: left;
    background: var(--color-primary);
    color: var(--color-on-primary);
    padding: 0.55rem 0.7rem;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.03em;
    text-transform: uppercase;
}

.lines-table thead th:first-child { border-radius: var(--radius-sm) 0 0 0; }
.lines-table thead th:last-child { border-radius: 0 var(--radius-sm) 0 0; }

.lines-table td {
    padding: 0.55rem 0.7rem;
    border-bottom: 1px solid var(--color-border);
}

.lines-table tfoot td {
    font-weight: 800;
    border-top: 2px solid var(--color-primary);
    border-bottom: none;
    padding-top: 0.7rem;
}

.num {
    text-align: right;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}

.muted {
    color: var(--color-text-muted);
}

.voucher-footer {
    margin-top: 1.75rem;
    padding-top: 0.75rem;
    border-top: 1px solid var(--color-border);
    font-size: 0.72rem;
    color: var(--color-text-muted);
    text-align: center;
}

@media print {
    .no-print {
        display: none !important;
    }

    .page {
        padding: 0;
        background: #fff;
    }

    .voucher {
        box-shadow: none;
        border-radius: 0;
        max-width: 100%;
        padding: 0;
    }
}
</style>
