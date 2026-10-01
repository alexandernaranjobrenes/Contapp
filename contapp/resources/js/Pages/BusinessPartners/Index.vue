<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import DetailModal from '../../Components/DetailModal.vue';
import LedgerPanel from '../../Components/LedgerPanel.vue';
import { useRecordDetail } from '../../Utils/recordDetail';
import { ListChecksIcon, PencilIcon, PlusIcon, ScrollTextIcon } from '@lucide/vue';

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

// Ficha del socio (CLAUDE.md secc. 20): los datos de contacto y las
// acciones —editar, partidas abiertas, movimientos y saldo—.
const { selected, openDetail, closeDetail } = useRecordDetail(() => props.partners);

const ledger = ref({ open: false, ownerId: null, ownerLabel: '' });

function openLedger() {
    const partner = selected.value;
    closeDetail();
    ledger.value = { open: true, ownerId: partner.id, ownerLabel: `${partner.code} — ${partner.name}` };
}

function closeLedger() {
    ledger.value.open = false;
}
</script>

<template>
    <Head title="Socios de negocio" />

    <AppLayout title="Socios de negocio">
        <div class="view-toolbar">
            <div class="view-actions">
                <Link :href="route('bp-categories.index')" class="btn btn-ghost">Categorías</Link>
                <Link :href="route('business-partners.create')" class="btn btn-primary"><PlusIcon /> Crear nuevo</Link>
            </div>
            <div class="view-filters">
                <input v-model="search" type="search" placeholder="Buscar código, nombre, cédula o encargado..." aria-label="Buscar socio de negocio">
            </div>
        </div>

        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Nombre</th>
                            <th>Tipo</th>
                            <th>Cédula</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="partner in filtered"
                            :key="partner.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="openDetail(partner)"
                            @keydown.enter="openDetail(partner)"
                            @keydown.space.prevent="openDetail(partner)"
                        >
                            <td class="code-cell">{{ partner.code }}</td>
                            <td data-label="Nombre">{{ partner.name }}</td>
                            <td data-label="Tipo">{{ typeLabels[partner.type] ?? partner.type }}</td>
                            <td data-label="Cédula">{{ partner.tax_id ?? '—' }}</td>
                            <td data-label="Estado">
                                <span class="badge" :class="partner.status === 'active' ? 'badge-success' : 'badge-neutral'">
                                    {{ partner.status === 'active' ? 'Activo' : 'Inactivo' }}
                                </span>
                            </td>
                        </tr>
                        <tr v-if="!filtered.length">
                            <td colspan="5" class="muted empty-row">
                                {{ partners.length ? 'Ningún socio coincide con la búsqueda.' : 'Todavía no hay socios de negocio registrados.' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <DetailModal :open="!!selected" :title="selected ? `${selected.code} — ${selected.name}` : ''" @close="closeDetail">
            <template #badge>
                <span v-if="selected" class="badge" :class="selected.status === 'active' ? 'badge-success' : 'badge-neutral'">
                    {{ selected.status === 'active' ? 'Activo' : 'Inactivo' }}
                </span>
            </template>

            <dl v-if="selected" class="detail-list">
                <div>
                    <dt>Tipo</dt>
                    <dd>{{ typeLabels[selected.type] ?? selected.type }}</dd>
                </div>
                <div>
                    <dt>Cédula</dt>
                    <dd>{{ selected.tax_id ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Categoría</dt>
                    <dd>{{ selected.category ? `${selected.category.code} — ${selected.category.name}` : '—' }}</dd>
                </div>
                <div>
                    <dt>Centro de costo</dt>
                    <dd>{{ selected.cost_center ? `${selected.cost_center.code} — ${selected.cost_center.name}` : '—' }}</dd>
                </div>
                <div>
                    <dt>Encargado</dt>
                    <dd>{{ selected.contact_name ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Correo</dt>
                    <dd>{{ selected.email ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Teléfono</dt>
                    <dd>{{ selected.phone ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Socio desde</dt>
                    <dd>{{ selected.partner_since ?? '—' }}</dd>
                </div>
            </dl>

            <template #actions>
                <template v-if="selected">
                    <button type="button" class="btn btn-ghost" @click="openLedger"><ScrollTextIcon /> Movimientos y saldo</button>
                    <Link :href="route('business-partners.open-items', selected.id)" class="btn btn-ghost"><ListChecksIcon /> Partidas abiertas</Link>
                    <Link :href="route('business-partners.edit', selected.id)" class="btn btn-primary"><PencilIcon /> Editar</Link>
                </template>
            </template>
        </DetailModal>

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
table { font-size: 0.85rem; }
.code-cell { font-variant-numeric: tabular-nums; white-space: nowrap; }
</style>
