<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeftIcon } from '@lucide/vue';
import AppLayout from '../../../Layouts/AppLayout.vue';

const props = defineProps({
    item: { type: Object, required: true },
    lot: { type: Object, required: true },
    movements: { type: Array, default: () => [] },
    balances: { type: Array, default: () => [] },
});

const OPERATIONS = {
    goods_receipt: 'Entrada de mercancía',
    purchase_receipt: 'Entrada por compra',
    purchase_receipt_void: 'Anulación de entrada',
    goods_issue: 'Salida de mercancía',
    count_adjustment: 'Ajuste por conteo',
    production_issue: 'Emisión a producción',
    production_receipt: 'Recibo de producción',
    sales_issue: 'Salida por venta',
    transfer: 'Traslado',
};

function quantity(value) {
    return Number(value ?? 0).toLocaleString('es-CR', { minimumFractionDigits: 0, maximumFractionDigits: 6 });
}

function location(row) {
    return row.bin ? `${row.warehouse} / ${row.bin}` : (row.warehouse ?? '—');
}
</script>

<template>
    <Head :title="`Trazabilidad del lote ${lot.code}`" />

    <AppLayout :title="`Trazabilidad — lote ${lot.code} de ${item.code}`">
        <div class="view-toolbar">
            <Link :href="route('item-lots.index', item.id)" class="btn btn-ghost"><ArrowLeftIcon /> Lotes</Link>
        </div>

        <div class="card lot-card">
            <div class="lot-header">
                <span><strong>{{ item.code }}</strong> — {{ item.name }}</span>
                <span class="badges">
                    <span class="badge" :class="lot.is_expired ? 'badge-danger' : 'badge-success'">
                        {{ lot.expires_at ? (lot.is_expired ? `Vencido el ${lot.expires_at}` : `Vence ${lot.expires_at}`) : 'Sin vencimiento' }}
                    </span>
                    <span class="badge" :class="lot.status === 'active' ? 'badge-success' : 'badge-warning'">
                        {{ lot.status === 'active' ? 'Activo' : 'Retenido' }}
                    </span>
                </span>
            </div>
            <p v-if="lot.notes" class="muted small lot-notes">{{ lot.notes }}</p>
        </div>

        <h2 class="block-title">Dónde está hoy</h2>

        <div class="card">
            <div class="table-responsive no-cards">
                <table>
                    <thead>
                        <tr>
                            <th>Almacén / ubicación</th>
                            <th class="num">Existencia</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(b, i) in balances" :key="i">
                            <td>{{ b.bin ? `${b.warehouse} / ${b.bin}` : b.warehouse }}</td>
                            <td class="num">{{ quantity(b.on_hand) }}</td>
                        </tr>
                        <tr v-if="!balances.length">
                            <td colspan="2" class="muted empty-row">Este lote ya no tiene existencia en ningún almacén.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <h2 class="block-title">Por dónde pasó</h2>

        <!-- Historia del lote: las cantidades de entrada y salida van en una
             sola columna con su signo, y el detalle queda debajo de la
             operación, para que la tabla quepa sin desplazarse de lado. -->
        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Operación</th>
                            <th>Almacén / ubicación</th>
                            <th class="num">Cantidad</th>
                            <th>Asiento</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="m in movements" :key="m.id">
                            <td class="date-cell">{{ m.posting_date }}</td>
                            <td data-label="Operación">
                                {{ OPERATIONS[m.operation] ?? m.operation ?? '—' }}
                                <div v-if="m.description" class="muted small">{{ m.description }}</div>
                            </td>
                            <td data-label="Almacén / ubicación">{{ location(m) }}</td>
                            <td data-label="Cantidad" class="num" :class="m.direction === 'in' ? 'qty-in' : 'qty-out'">
                                {{ m.direction === 'in' ? '+' : '−' }}{{ quantity(m.quantity) }}
                            </td>
                            <td data-label="Asiento" class="date-cell">{{ m.journal_entry ?? '—' }}</td>
                        </tr>
                        <tr v-if="!movements.length">
                            <td colspan="5" class="muted empty-row">Este lote todavía no tiene movimientos.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
table { font-size: 0.85rem; }
.lot-card { padding: 0.85rem 1.1rem; margin-bottom: 0.5rem; }
.lot-header { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.5rem 1rem; }
.badges { display: inline-flex; flex-wrap: wrap; gap: 0.35rem; }
.lot-notes { margin: 0.5rem 0 0; }
.date-cell { font-variant-numeric: tabular-nums; white-space: nowrap; }
.qty-in { color: var(--color-success); }
.qty-out { color: var(--color-danger); }
</style>
