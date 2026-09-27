<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';

defineProps({
    groups: { type: Object, default: () => ({}) },
    related: { type: Array, default: () => [] },
});
</script>

<template>
    <Head title="Reportes de planilla" />

    <AppLayout title="Reportes de planilla">
        <template #actions>
            <Link :href="route('payroll-periods.index')" class="btn btn-ghost">Períodos</Link>
            <Link :href="route('employees.index')" class="btn btn-ghost">Empleados</Link>
        </template>

        <p class="hint">
            Todos se consultan en pantalla con sus filtros y salen a <strong>Excel</strong>, a
            <strong>PDF</strong> o directo a la impresora. Cada uno dice
            <strong>qué decisión ayuda a tomar</strong>, que es lo que los distingue cuando hay varios que
            parecen iguales.
        </p>

        <section v-for="(reports, group) in groups" :key="group" class="group">
            <h2>{{ group }}</h2>

            <div class="cards">
                <Link
                    v-for="r in reports"
                    :key="r.code"
                    :href="route('payroll-reports.show', r.code)"
                    class="card report-card"
                >
                    <strong class="title">{{ r.label }}</strong>
                    <span class="description">{{ r.description }}</span>
                    <span class="decision"><span class="decision-label">Sirve para decidir:</span> {{ r.decision }}</span>
                    <span v-if="r.selectable_columns" class="badge">columnas escogibles</span>
                </Link>
            </div>
        </section>

        <section v-if="related.length" class="group">
            <h2>Otras salidas del módulo</h2>
            <p class="hint small">
                No son reportes tabulares sino documentos por trabajador o archivos para terceros; se enlazan
                acá para que este sea el único lugar al que haya que venir.
            </p>

            <div class="cards">
                <Link
                    v-for="r in related"
                    :key="r.label"
                    :href="route(r.route)"
                    class="card report-card related"
                >
                    <strong class="title">{{ r.label }}</strong>
                    <span class="decision"><span class="decision-label">Sirve para:</span> {{ r.decision }}</span>
                </Link>
            </div>
        </section>
    </AppLayout>
</template>

<style scoped>
.hint { color: var(--color-text-muted); font-size: 0.85rem; margin: 0 0 1.25rem; max-width: 80ch; }
.small { font-size: 0.78rem; margin-bottom: 0.75rem; }

.group { margin-bottom: 1.75rem; }
.group h2 {
    font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.06em;
    color: var(--color-text-muted); margin: 0 0 0.6rem;
}

.cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 0.75rem; }

.report-card {
    display: flex; flex-direction: column; gap: 0.3rem;
    padding: 0.9rem 1rem; text-decoration: none; color: inherit;
    border-left: 3px solid var(--color-primary, #0B1F3A);
    transition: transform 0.08s ease, box-shadow 0.08s ease;
}
.report-card:hover { transform: translateY(-1px); box-shadow: 0 2px 8px rgba(11, 31, 58, 0.12); }
.report-card.related { border-left-color: var(--color-border); }

.title { font-size: 0.92rem; }
.description { font-size: 0.8rem; color: var(--color-text-muted); }
.decision { font-size: 0.78rem; color: var(--color-text); margin-top: 0.2rem; }
.decision-label { font-weight: 600; color: var(--color-text-muted); }

.badge {
    align-self: flex-start;
    margin-top: 0.3rem;
    font-size: 0.68rem;
    padding: 0.08rem 0.4rem;
    border-radius: 999px;
    background: #eef2ff;
    color: #3730a3;
}
</style>
