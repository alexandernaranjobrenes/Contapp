<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    // { root_id, nodes: [{ kind, label, date, detail, amount, state, current,
    //   journal_entry_id, journal_document_number, route, route_params }] }
    cycle: { type: Object, required: true },
});

const ICONS = {
    receipt: '📦',
    landed_cost: '🚢',
    invoice: '🧾',
    credit_note: '↩️',
    void: '🚫',
};

function money(value) {
    return Number(value ?? 0).toLocaleString('es-CR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function href(node) {
    if (! node.route) {
        return null;
    }

    return route(node.route, node.route_params);
}
</script>

<template>
    <div class="card cycle">
        <div class="cycle-header">
            <strong>Mapa del ciclo de compra</strong>
            <span class="muted small">Entrada #{{ cycle.root_id }}</span>
        </div>

        <ol class="cycle-list">
            <li
                v-for="(node, index) in cycle.nodes"
                :key="node.kind + index"
                class="node"
                :class="[`state-${node.state}`, { current: node.current }]"
            >
                <span class="node-icon" aria-hidden="true">{{ ICONS[node.kind] }}</span>

                <div class="node-body">
                    <div class="node-title">
                        <component
                            :is="href(node) ? Link : 'span'"
                            :href="href(node) ?? undefined"
                            :class="href(node) ? 'link' : ''"
                        >
                            {{ node.label }}
                        </component>

                        <span v-if="node.current" class="badge badge-neutral">Está viendo este</span>
                        <span v-else-if="node.state === 'pending'" class="badge badge-warning">Pendiente</span>
                        <span v-else-if="node.state === 'voided'" class="badge badge-warning">Anulado</span>
                    </div>

                    <span class="muted small">{{ node.detail }}</span>

                    <span v-if="node.journal_entry_id" class="muted small">
                        Asiento
                        <Link :href="route('journal-entries.show', node.journal_entry_id)" class="link">
                            #{{ node.journal_document_number }}
                        </Link>
                    </span>
                </div>

                <div class="node-side">
                    <strong v-if="node.amount !== null" class="num">{{ money(node.amount) }}</strong>
                    <span v-if="node.date" class="muted small num">{{ node.date }}</span>
                </div>
            </li>
        </ol>
    </div>
</template>

<style scoped>
.cycle { padding: 1rem 1.25rem; margin-bottom: 0.75rem; }

.cycle-header {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 0.75rem;
}

.cycle-list {
    list-style: none;
    margin: 0;
    padding: 0;
}

.node {
    position: relative;
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
    padding: 0.6rem 0.65rem;
    border-radius: var(--radius-sm, 6px);
}

/* La línea que une los pasos: se dibuja desde el ícono de cada nodo hacia el
   siguiente, así el ciclo se lee como una cadena y no como una lista suelta. */
.node:not(:last-child)::before {
    content: '';
    position: absolute;
    left: 1.42rem;
    top: 2.1rem;
    bottom: -0.2rem;
    width: 2px;
    background: var(--color-border);
}

.node.current { background: var(--color-surface-muted, rgb(0 0 0 / 4%)); }
.node.state-pending .node-icon { opacity: 0.45; }
.node.state-voided .node-icon { opacity: 0.6; }

.node-icon {
    flex-shrink: 0;
    width: 1.75rem;
    height: 1.75rem;
    display: grid;
    place-items: center;
    border-radius: 50%;
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    font-size: 0.85rem;
    z-index: 1;
}

.node-body { display: flex; flex-direction: column; gap: 0.1rem; flex: 1; min-width: 0; }
.node-title { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; font-weight: 600; }
.node-side { display: flex; flex-direction: column; align-items: flex-end; gap: 0.1rem; text-align: right; }

.num { font-variant-numeric: tabular-nums; }
.muted { color: var(--color-text-muted); font-weight: 400; }
.small { font-size: 0.76rem; }
.link { color: var(--color-primary); text-decoration: none; }
.link:hover { text-decoration: underline; }
</style>
