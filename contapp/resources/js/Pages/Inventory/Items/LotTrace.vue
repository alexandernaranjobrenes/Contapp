<script setup>
import { Head, Link } from '@inertiajs/vue3';
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
        <template #actions>
            <Link :href="route('item-lots.index', item.id)" class="btn btn-ghost">Volver a lotes</Link>
        </template>

        <div class="card">
            <div class="card-header">
                <span><strong>{{ item.code }}</strong> — {{ item.name }}</span>
                <span>
                    <span class="badge" :class="lot.is_expired ? 'badge-danger' : 'badge-success'">
                        {{ lot.expires_at ? (lot.is_expired ? `Vencido el ${lot.expires_at}` : `Vence ${lot.expires_at}`) : 'Sin vencimiento' }}
                    </span>
                    <span class="badge" :class="lot.status === 'active' ? 'badge-success' : 'badge-warning'">
                        {{ lot.status === 'active' ? 'Activo' : 'Retenido' }}
                    </span>
                </span>
            </div>
            <p v-if="lot.notes" class="muted small">{{ lot.notes }}</p>
        </div>

        <h2 class="section-title">Dónde está hoy</h2>

        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th>Almacén / ubicación</th>
                        <th class="right">Existencia</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(b, i) in balances" :key="i">
                        <td>{{ b.bin ? `${b.warehouse} / ${b.bin}` : b.warehouse }}</td>
                        <td class="num right">{{ quantity(b.on_hand) }}</td>
                    </tr>
                    <tr v-if="!balances.length">
                        <td colspan="2" class="muted empty-row">Este lote ya no tiene existencia en ningún almacén.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <h2 class="section-title">Por dónde pasó</h2>

        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Operación</th>
                        <th>Almacén / ubicación</th>
                        <th class="right">Entrada</th>
                        <th class="right">Salida</th>
                        <th>Asiento</th>
                        <th>Detalle</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="m in movements" :key="m.id">
                        <td class="num">{{ m.posting_date }}</td>
                        <td>{{ OPERATIONS[m.operation] ?? m.operation ?? '—' }}</td>
                        <td>{{ location(m) }}</td>
                        <td class="num right">{{ m.direction === 'in' ? quantity(m.quantity) : '' }}</td>
                        <td class="num right">{{ m.direction === 'out' ? quantity(m.quantity) : '' }}</td>
                        <td class="num">{{ m.journal_entry ?? '—' }}</td>
                        <td class="muted small">{{ m.description ?? '—' }}</td>
                    </tr>
                    <tr v-if="!movements.length">
                        <td colspan="7" class="muted empty-row">Este lote todavía no tiene movimientos.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
