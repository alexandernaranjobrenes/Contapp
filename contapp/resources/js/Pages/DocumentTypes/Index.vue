<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { PencilIcon, PlusIcon } from '@lucide/vue';

import AppLayout from '../../Layouts/AppLayout.vue';
import DetailModal from '../../Components/DetailModal.vue';
import { useRecordDetail } from '../../Utils/recordDetail';

const props = defineProps({
    documentTypes: { type: Array, default: () => [] },
    originModules: { type: Object, required: true },
});

// Ficha del tipo de documento (CLAUDE.md secc. 20). Editar abre su propia
// pantalla y no un modal: ahí también se administran las series de
// numeración, una lista propia que no cabe en un modal.
const { selected, openDetail, closeDetail } = useRecordDetail(() => props.documentTypes);

function isSystemType(dt) {
    return dt.is_opening_type || dt.is_reconciliation_type || dt.is_closing_type;
}

function systemPurpose(dt) {
    if (dt.is_opening_type) return 'la carga de saldos iniciales';
    if (dt.is_reconciliation_type) return 'los traspasos de reconciliación interna';
    if (dt.is_closing_type) return 'el asiento de cierre anual';
    return null;
}
</script>

<template>
    <Head title="Tipos de documento" />

    <AppLayout title="Tipos de documento">
        <div class="view-toolbar">
            <div class="view-actions">
                <Link :href="route('document-types.create')" class="btn btn-primary"><PlusIcon /> Crear nuevo</Link>
            </div>
        </div>

        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Nombre</th>
                            <th>Módulo</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="dt in documentTypes"
                            :key="dt.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="openDetail(dt)"
                            @keydown.enter="openDetail(dt)"
                            @keydown.space.prevent="openDetail(dt)"
                        >
                            <td class="code-cell">{{ dt.code }}</td>
                            <td data-label="Nombre">
                                <span class="name-cell">
                                    {{ dt.name }}
                                    <span v-if="isSystemType(dt)" class="badge badge-neutral">Sistema</span>
                                </span>
                            </td>
                            <td data-label="Módulo">{{ originModules[dt.origin_module] ?? dt.origin_module }}</td>
                            <td data-label="Estado">
                                <span class="badge" :class="dt.status === 'active' ? 'badge-success' : 'badge-neutral'">
                                    {{ dt.status === 'active' ? 'Activo' : 'Inactivo' }}
                                </span>
                            </td>
                        </tr>
                        <tr v-if="!documentTypes.length">
                            <td colspan="4" class="muted empty-row">Todavía no hay tipos de documento configurados.</td>
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

            <template v-if="selected">
                <dl class="detail-list">
                    <div>
                        <dt>Módulo de origen</dt>
                        <dd>{{ originModules[selected.origin_module] ?? selected.origin_module }}</dd>
                    </div>
                    <div>
                        <dt>Genera asiento</dt>
                        <dd>{{ selected.generates_journal ? 'Sí' : 'No' }}</dd>
                    </div>
                    <div>
                        <dt>Próximo consecutivo interno</dt>
                        <dd class="num-value">{{ selected.next_consecutive }}</dd>
                    </div>
                    <div>
                        <dt>Series de numeración</dt>
                        <dd class="num-value">{{ selected.number_series_count }}</dd>
                    </div>
                </dl>
                <p v-if="isSystemType(selected)" class="hint system-hint">
                    Lo creó el sistema para {{ systemPurpose(selected) }}: no se elige a mano en el asiento manual.
                </p>
            </template>

            <template #actions>
                <Link v-if="selected" :href="route('document-types.edit', selected.id)" class="btn btn-primary"><PencilIcon /> Editar</Link>
            </template>
        </DetailModal>
    </AppLayout>
</template>

<style scoped>
table { font-size: 0.85rem; }
.code-cell { font-weight: 600; font-variant-numeric: tabular-nums; white-space: nowrap; }
.name-cell { display: inline-flex; align-items: center; flex-wrap: wrap; gap: 0.4rem; }
.num-value { font-variant-numeric: tabular-nums; }
.system-hint { margin: 0.9rem 0 0; }
</style>
