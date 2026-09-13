<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../Layouts/AppLayout.vue';

defineProps({
    noCompany: { type: Boolean, default: false },
    stats: { type: Object, default: () => ({}) },
    recentEntries: { type: Array, default: () => [] },
});
</script>

<template>
    <Head title="Panel" />

    <AppLayout title="Panel">
        <div v-if="noCompany" class="card empty-state">
            <p>Tu usuario no tiene ninguna compañía asignada todavía.</p>
            <p class="muted">Pedile a un administrador que te agregue a una compañía para empezar a trabajar.</p>
        </div>

        <template v-else>
            <div class="stat-grid">
                <div class="card stat-card">
                    <span class="stat-label">Cuentas en el catálogo</span>
                    <span class="stat-value">{{ stats.accounts }}</span>
                </div>
                <div class="card stat-card">
                    <span class="stat-label">Asientos contabilizados</span>
                    <span class="stat-value">{{ stats.journalEntries }}</span>
                </div>
                <div class="card stat-card">
                    <span class="stat-label">Partidas abiertas (CxC/CxP)</span>
                    <span class="stat-value">{{ stats.openItems }}</span>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2>Últimos documentos</h2>
                    <Link :href="route('journal-entries.index')" class="btn btn-ghost">Ver todos</Link>
                </div>

                <table v-if="recentEntries.length">
                    <thead>
                        <tr>
                            <th>Documento</th>
                            <th>Fecha</th>
                            <th>Descripción</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="entry in recentEntries" :key="entry.id">
                            <td>{{ entry.document_type?.code }}-{{ entry.document_number }}</td>
                            <td>{{ entry.posting_date }}</td>
                            <td>{{ entry.description }}</td>
                            <td>
                                <span class="badge" :class="entry.status === 'posted' ? 'badge-success' : 'badge-neutral'">
                                    {{ entry.status }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p v-else class="muted empty-row">Todavía no hay documentos contabilizados.</p>
            </div>
        </template>
    </AppLayout>
</template>

<style scoped>
.stat-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 1.25rem;
}

.stat-card {
    padding: 1rem 1.1rem;
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
}

.stat-label {
    font-size: 0.78rem;
    color: var(--color-text-muted);
    font-weight: 600;
}

.stat-value {
    font-size: 1.6rem;
    font-weight: 800;
    color: var(--color-primary);
}

.card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1rem 1.1rem 0.5rem;
}

.card-header h2 {
    font-size: 0.95rem;
    margin: 0;
}

table {
    font-size: 0.85rem;
}

th, td {
    text-align: left;
    padding: 0.55rem 1.1rem;
    border-top: 1px solid var(--color-border);
}

.empty-state, .empty-row {
    padding: 1.25rem;
}

.muted {
    color: var(--color-text-muted);
}
</style>
