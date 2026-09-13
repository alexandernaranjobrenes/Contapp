<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import LedgerPanel from '../../Components/LedgerPanel.vue';
import DocumentToolbar from '../../Components/DocumentToolbar.vue';

const props = defineProps({
    partners: { type: Array, default: () => [] },
});

const typeLabels = { client: 'Cliente', supplier: 'Proveedor', both: 'Ambos' };

const search = ref('');

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (! q) return props.partners;

    return props.partners.filter((p) =>
        p.code.toLowerCase().includes(q)
        || p.name.toLowerCase().includes(q)
        || (p.tax_id ?? '').toLowerCase().includes(q)
        || (p.contact_name ?? '').toLowerCase().includes(q)
    );
});

const ledger = ref({ open: false, ownerId: null, ownerLabel: '' });

function openLedger(partner) {
    ledger.value = { open: true, ownerId: partner.id, ownerLabel: `${partner.code} — ${partner.name}` };
}

function closeLedger() {
    ledger.value.open = false;
}
</script>

<template>
    <Head title="Socios de negocio" />

    <AppLayout title="Socios de negocio">
        <template #actions>
            <input v-model="search" type="search" placeholder="Buscar código, nombre, cédula o encargado..." class="search-input">
            <Link :href="route('business-partners.create')" class="btn btn-primary">+ Nuevo socio</Link>
        </template>

        <DocumentToolbar :new-href="route('business-partners.create')" />

        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Nombre</th>
                        <th>Tipo</th>
                        <th>Categoría</th>
                        <th>Centro de costo</th>
                        <th>Cédula</th>
                        <th>Encargado</th>
                        <th>Contacto</th>
                        <th>Desde</th>
                        <th>Estado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="partner in filtered" :key="partner.id" class="clickable-row" title="Ver movimientos y saldo" @click="openLedger(partner)">
                        <td class="num code-cell">{{ partner.code }}</td>
                        <td>{{ partner.name }}</td>
                        <td>{{ typeLabels[partner.type] ?? partner.type }}</td>
                        <td class="muted small">{{ partner.category ? `${partner.category.code} — ${partner.category.name}` : '—' }}</td>
                        <td class="muted small">{{ partner.cost_center ? `${partner.cost_center.code} — ${partner.cost_center.name}` : '—' }}</td>
                        <td>{{ partner.tax_id }}</td>
                        <td>{{ partner.contact_name }}</td>
                        <td class="muted small">
                            <div v-if="partner.email">{{ partner.email }}</div>
                            <div v-if="partner.phone">{{ partner.phone }}</div>
                        </td>
                        <td class="num">{{ partner.partner_since }}</td>
                        <td>
                            <span class="badge" :class="partner.status === 'active' ? 'badge-success' : 'badge-neutral'">
                                {{ partner.status === 'active' ? 'Activo' : 'Inactivo' }}
                            </span>
                        </td>
                        <td class="actions-cell" @click.stop>
                            <Link :href="route('business-partners.edit', partner.id)" class="btn btn-ghost">Editar</Link>
                            <Link :href="route('business-partners.open-items', partner.id)" class="btn btn-ghost">
                                Partidas abiertas
                            </Link>
                        </td>
                    </tr>
                    <tr v-if="!filtered.length">
                        <td colspan="11" class="muted empty-row">
                            {{ partners.length ? 'Ningún socio coincide con la búsqueda.' : 'Todavía no hay socios de negocio registrados.' }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <LedgerPanel
            :open="ledger.open"
            dimension="business-partner"
            :owner-id="ledger.ownerId"
            :owner-label="ledger.ownerLabel"
            @close="closeLedger"
        />
    </AppLayout>
</template>

<style scoped>
.search-input {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.4rem 0.6rem;
    font-size: 0.82rem;
    width: 260px;
}

table { font-size: 0.85rem; }
th, td { text-align: left; padding: 0.5rem 1rem; border-top: 1px solid var(--color-border); }
.code-cell { text-align: left; font-variant-numeric: tabular-nums; }
.num { font-variant-numeric: tabular-nums; }
.muted { color: var(--color-text-muted); }
.small { font-size: 0.76rem; }
.empty-row { text-align: center; padding: 1.5rem; }
.actions-cell { display: flex; gap: 0.4rem; }
.clickable-row { cursor: pointer; }
.clickable-row:hover { background: var(--color-primary-soft); }
</style>
