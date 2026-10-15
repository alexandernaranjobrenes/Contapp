<script setup>
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import { formatMoney } from '../../Utils/money';

/**
 * Cobros y pagos (Socios de negocio → Cobros y pagos): los socios con
 * partidas abiertas. Es su propia pantalla, aparte de la ficha del socio
 * (separación de funciones, CLAUDE.md secc. 36): quien aplica los pagos no
 * necesita poder editar al proveedor. Cada fila lleva a sus partidas.
 */
const props = defineProps({
    partners: { type: Array, default: () => [] },
});

const TYPES = { client: 'Cliente', supplier: 'Proveedor', both: 'Cliente y proveedor' };

const search = ref('');

const visible = computed(() => {
    const needle = search.value.trim().toLowerCase();
    if (! needle) return props.partners;
    return props.partners.filter((p) => `${p.code} ${p.name}`.toLowerCase().includes(needle));
});

function open(partner) {
    router.visit(route('business-partners.open-items', partner.id));
}
</script>

<template>
    <Head title="Cobros y pagos" />

    <AppLayout title="Cobros y pagos">
        <div class="view-toolbar">
            <div class="view-filters">
                <input v-model="search" type="search" placeholder="Buscar socio por código o nombre..." aria-label="Buscar socio">
            </div>
        </div>

        <p class="hint">
            Los socios con partidas pendientes de cobro o de pago. Entrá a uno para aplicarle un cobro o un pago, cambiar
            un vencimiento o reconciliar sus partidas.
        </p>

        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Socio</th>
                            <th>Tipo</th>
                            <th class="num">Partidas</th>
                            <th class="num">Saldo pendiente</th>
                            <th>Próximo vencimiento</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="p in visible"
                            :key="p.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="open(p)"
                            @keydown.enter="open(p)"
                            @keydown.space.prevent="open(p)"
                        >
                            <td>{{ p.code }} — {{ p.name }}</td>
                            <td data-label="Tipo">{{ TYPES[p.type] ?? p.type }}</td>
                            <td data-label="Partidas" class="num">{{ p.balances.reduce((sum, b) => sum + b.items, 0) }}</td>
                            <td data-label="Saldo pendiente" class="num">
                                <span v-for="b in p.balances" :key="b.currency" class="balance">{{ b.currency }} {{ formatMoney(b.balance) }}</span>
                            </td>
                            <td data-label="Próximo vencimiento">{{ p.balances.map((b) => b.next_due).filter(Boolean).sort()[0] ?? '—' }}</td>
                        </tr>
                        <tr v-if="! visible.length">
                            <td colspan="5" class="muted empty-row">
                                {{ partners.length ? 'Ningún socio coincide con la búsqueda.' : 'No hay partidas pendientes de cobro ni de pago.' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
table { font-size: 0.85rem; }
.balance { display: block; font-variant-numeric: tabular-nums; }
</style>
