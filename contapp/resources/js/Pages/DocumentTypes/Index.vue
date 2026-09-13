<script setup>
import { Head, Link } from '@inertiajs/vue3';

import AppLayout from '../../Layouts/AppLayout.vue';
import DocumentToolbar from '../../Components/DocumentToolbar.vue';

const props = defineProps({
    documentTypes: { type: Array, default: () => [] },
    originModules: { type: Object, required: true },
});
</script>

<template>
    <Head title="Tipos de documento" />

    <AppLayout title="Tipos de documento">
        <template #actions>
            <Link :href="route('document-types.create')" class="btn btn-primary">+ Nuevo tipo de documento</Link>
        </template>

        <DocumentToolbar :new-href="route('document-types.create')" />

        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Nombre</th>
                        <th>Módulo</th>
                        <th>Genera asiento</th>
                        <th>Consecutivo interno</th>
                        <th>Series</th>
                        <th>Estado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="dt in documentTypes" :key="dt.id">
                        <td class="num code-cell">{{ dt.code }}</td>
                        <td>
                            {{ dt.name }}
                            <span v-if="dt.is_opening_type" class="badge badge-neutral" title="Creado por el sistema para la carga de saldos iniciales — no se elige a mano en el asiento manual">Sistema</span>
                            <span v-if="dt.is_reconciliation_type" class="badge badge-neutral" title="Creado por el sistema para traspasos de reconciliación interna — no se elige a mano en el asiento manual">Sistema</span>
                            <span v-if="dt.is_closing_type" class="badge badge-neutral" title="Creado por el sistema para el asiento de cierre anual — no se elige a mano en el asiento manual">Sistema</span>
                        </td>
                        <td>{{ originModules[dt.origin_module] ?? dt.origin_module }}</td>
                        <td>
                            <span class="badge" :class="dt.generates_journal ? 'badge-success' : 'badge-neutral'">
                                {{ dt.generates_journal ? 'Sí' : 'No' }}
                            </span>
                        </td>
                        <td class="num">{{ dt.next_consecutive }}</td>
                        <td class="num">{{ dt.number_series_count }}</td>
                        <td>
                            <span class="badge" :class="dt.status === 'active' ? 'badge-success' : 'badge-neutral'">
                                {{ dt.status === 'active' ? 'Activo' : 'Inactivo' }}
                            </span>
                        </td>
                        <td>
                            <Link :href="route('document-types.edit', dt.id)" class="btn btn-ghost">Editar</Link>
                        </td>
                    </tr>
                    <tr v-if="!documentTypes.length">
                        <td colspan="8" class="muted empty-row">Todavía no hay tipos de documento configurados.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>

<style scoped>
table { font-size: 0.85rem; width: 100%; }
th, td { text-align: left; padding: 0.5rem 1rem; border-top: 1px solid var(--color-border); white-space: nowrap; }
.code-cell { font-variant-numeric: tabular-nums; }
.num { font-variant-numeric: tabular-nums; }
.muted { color: var(--color-text-muted); }
.empty-row { text-align: center; padding: 1.5rem; white-space: normal; }
</style>
