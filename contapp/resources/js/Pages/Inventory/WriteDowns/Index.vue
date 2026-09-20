<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';

defineProps({
    writeDowns: { type: Array, default: () => [] },
});

function money(value) {
    return Number(value ?? 0).toLocaleString('es-CR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
</script>

<template>
    <Head title="Deterioro de inventario" />

    <AppLayout title="Deterioro de inventario">
        <template #actions>
            <Link :href="route('inventory-write-downs.create')" class="btn btn-primary">+ Nuevo avalúo</Link>
        </template>

        <p class="hint">
            Avalúos de valor neto realizable (NIC 2 §28). Un efecto negativo significa que el avalúo
            <strong>reversó</strong> estimación reconocida antes, porque el VNR se recuperó (§33).
        </p>

        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th>Fecha de corte</th>
                        <th>Descripción</th>
                        <th class="num">Artículos</th>
                        <th class="num">Efecto en resultados</th>
                        <th>Asiento</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="w in writeDowns" :key="w.id">
                        <td class="num">
                            <Link :href="route('inventory-write-downs.show', w.id)" class="link">{{ w.as_of }}</Link>
                        </td>
                        <td>{{ w.description ?? '—' }}</td>
                        <td class="num">{{ w.lines_count }}</td>
                        <td class="num" :class="w.total_movement < 0 ? 'reversal' : 'impairment'">
                            {{ money(w.total_movement) }}
                        </td>
                        <td class="num">{{ w.journal_document_number ?? '—' }}</td>
                    </tr>
                    <tr v-if="!writeDowns.length">
                        <td colspan="5" class="muted empty-row">Todavía no hay avalúos de deterioro.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>

<style scoped>
.num { text-align: right; }
.impairment { color: #a02020; }
.reversal { color: #1d7a3c; }
</style>
