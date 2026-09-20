<script setup>
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import ConfirmModal from '../../../Components/ConfirmModal.vue';

const props = defineProps({
    count: { type: Object, required: true },
    lines: { type: Array, default: () => [] },
});

const page = usePage();
const today = new Date().toISOString().slice(0, 10);
const isOpen = computed(() => props.count.status === 'open');

// Lo capturado vive en el formulario hasta que se guarda; null es "sin contar".
const form = useForm({
    counted: Object.fromEntries(props.lines.map((l) => [l.id, l.counted_quantity ?? ''])),
});

function money(value) {
    return Number(value ?? 0).toLocaleString('es-CR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function quantity(value) {
    return Number(value ?? 0).toLocaleString('es-CR', { minimumFractionDigits: 0, maximumFractionDigits: 6 });
}

// La diferencia se recalcula mientras se escribe, para ver el hallazgo sin
// tener que guardar. En conteo a ciegas no se muestra hasta cerrar.
function liveDifference(line) {
    const value = form.counted[line.id];

    if (value === '' || value === null) {
        return null;
    }

    return Number(value) - line.theoretical_quantity;
}

const pending = computed(() =>
    props.lines.filter((l) => form.counted[l.id] === '' || form.counted[l.id] === null).length
);

const withDifference = computed(() =>
    props.lines.filter((l) => {
        const d = liveDifference(l);

        return d !== null && d !== 0;
    })
);

const netValue = computed(() => withDifference.value.reduce(
    (sum, line) => sum + liveDifference(line) * line.unit_cost_local, 0
));

function saveCounts() {
    form
        .transform((data) => ({
            counted: Object.fromEntries(
                Object.entries(data.counted).map(([id, value]) => [id, value === '' ? null : value])
            ),
        }))
        .post(route('stock-counts.capture', props.count.id), { preserveScroll: true });
}

const confirmingPost = ref(false);
const confirmingCancel = ref(false);
const working = ref(false);

function closeCount() {
    working.value = true;

    router.post(route('stock-counts.post', props.count.id), { posting_date: today }, {
        onFinish: () => { working.value = false; confirmingPost.value = false; },
    });
}

function cancelCount() {
    working.value = true;

    router.post(route('stock-counts.cancel', props.count.id), {}, {
        onFinish: () => { working.value = false; confirmingCancel.value = false; },
    });
}

const badgeClass = { open: 'badge-warning', posted: 'badge-success', cancelled: 'badge-neutral' };
</script>

<template>
    <Head :title="`Toma física ${count.number}`" />

    <AppLayout :title="`Toma física ${count.number}`">
        <template #actions>
            <a :href="route('stock-counts.print', count.id)" class="btn btn-ghost">Imprimir hoja</a>
            <button v-if="isOpen" type="button" class="btn btn-ghost" @click="confirmingCancel = true">
                Cancelar toma
            </button>
            <button
                v-if="isOpen" type="button" class="btn btn-primary"
                :disabled="pending > 0"
                @click="confirmingPost = true"
            >
                Cerrar y ajustar
            </button>
        </template>

        <div v-if="page.props.errors?.count" class="flash flash-error">{{ page.props.errors.count }}</div>

        <div class="card summary">
            <div><span class="muted small">Fecha de corte</span><strong class="num">{{ count.cutoff_date }}</strong></div>
            <div><span class="muted small">Almacén</span><strong>{{ count.warehouse }}</strong></div>
            <div><span class="muted small">Familia</span><strong>{{ count.item_group ?? 'Todas' }}</strong></div>
            <div><span class="muted small">Modo</span><strong>{{ count.blind ? 'A ciegas' : 'Con existencia a la vista' }}</strong></div>
            <div>
                <span class="muted small">Estado</span>
                <span class="badge" :class="badgeClass[count.status]">{{ count.status_label }}</span>
            </div>
            <div v-if="count.journal_entry_id">
                <span class="muted small">Ajuste</span>
                <Link :href="route('journal-entries.show', count.journal_entry_id)" class="link">Ver asiento</Link>
            </div>
        </div>

        <p class="hint">
            <template v-if="isOpen">
                Anote lo contado y guarde; puede hacerlo por tandas. Para cerrar hacen falta
                <strong>todas</strong> las líneas contadas: si un artículo no apareció, se cuenta en cero.
                <template v-if="pending"> Quedan {{ pending }} sin contar.</template>
            </template>
            <template v-else-if="count.status === 'posted'">
                Toma cerrada el {{ count.posted_at }}.
                <template v-if="count.inventory_document_id">
                    El ajuste quedó contabilizado con las diferencias encontradas.
                </template>
                <template v-else>
                    No hubo diferencias: el conteo cuadró con el sistema y no hubo nada que ajustar.
                </template>
            </template>
            <template v-else>
                Toma cancelada. No se ajustó nada y el almacén quedó libre para abrir otra.
            </template>
        </p>

        <div v-if="isOpen" class="card totals-card">
            <div><span class="muted small">Líneas con diferencia</span><strong class="num">{{ withDifference.length }}</strong></div>
            <div><span class="muted small">Sin contar</span><strong class="num">{{ pending }}</strong></div>
            <div>
                <span class="muted small">Efecto neto en resultados</span>
                <strong class="num" :class="netValue < 0 ? 'neg' : ''">{{ money(netValue) }}</strong>
            </div>
        </div>

        <form class="card" @submit.prevent="saveCounts">
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Artículo</th>
                            <th v-if="lines.some((l) => l.bin_code)">Ubicación</th>
                            <th v-if="!count.blind || !isOpen" class="right">Existencia del sistema</th>
                            <th class="right">Contado</th>
                            <th class="right">Diferencia</th>
                            <th class="right">Valor</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="line in lines"
                            :key="line.id"
                            :class="{ diff: liveDifference(line) !== null && liveDifference(line) !== 0 }"
                        >
                            <td class="num">{{ line.line_number }}</td>
                            <td>{{ line.item }}</td>
                            <td v-if="lines.some((l) => l.bin_code)" class="num">{{ line.bin_code ?? '—' }}</td>
                            <td v-if="!count.blind || !isOpen" class="num right">{{ quantity(line.theoretical_quantity) }}</td>
                            <td class="right">
                                <input
                                    v-if="isOpen"
                                    v-model="form.counted[line.id]"
                                    type="number" step="0.000001" min="0" class="cell-input"
                                >
                                <span v-else class="num">{{ quantity(line.counted_quantity) }}</span>
                            </td>
                            <td class="num right" :class="liveDifference(line) < 0 ? 'neg' : ''">
                                {{ liveDifference(line) === null ? '—' : quantity(liveDifference(line)) }}
                            </td>
                            <td class="num right muted">
                                {{ liveDifference(line) === null ? '—' : money(liveDifference(line) * line.unit_cost_local) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="isOpen" class="actions">
                <button type="submit" class="btn btn-primary" :disabled="form.processing">Guardar conteo</button>
            </div>
        </form>

        <ConfirmModal
            :open="confirmingPost"
            title="Cerrar la toma física"
            message="Se contabilizará un ajuste de inventario con las diferencias encontradas: los faltantes salen del inventario y los sobrantes entran, ambos al costo promedio. Si no hubo diferencias, la toma se cierra sin ajuste. Esto no se puede deshacer."
            confirm-label="Cerrar y ajustar"
            :processing="working"
            @confirm="closeCount"
            @cancel="confirmingPost = false"
        />

        <ConfirmModal
            :open="confirmingCancel"
            title="Cancelar la toma física"
            message="El conteo capturado se conserva como constancia, pero no se ajustará nada y el almacén quedará libre para abrir otra toma."
            confirm-label="Cancelar la toma"
            danger
            :processing="working"
            @confirm="cancelCount"
            @cancel="confirmingCancel = false"
        />
    </AppLayout>
</template>

<style scoped>
.summary { display: flex; flex-wrap: wrap; gap: 1.5rem; padding: 1rem 1.25rem; margin-bottom: 0.75rem; }
.summary > div { display: flex; flex-direction: column; gap: 0.15rem; }

.totals-card { display: flex; flex-wrap: wrap; gap: 1.75rem; padding: 0.85rem 1.25rem; margin-bottom: 0.75rem; }
.totals-card > div { display: flex; flex-direction: column; gap: 0.15rem; }

.hint { font-size: 0.82rem; color: var(--color-text-muted); margin: 0 0 0.75rem; }
.card { padding: 0; }
.card.summary, .card.totals-card { padding: 1rem 1.25rem; }
.table-scroll { overflow-x: auto; }
table { font-size: 0.85rem; width: 100%; }
th, td { text-align: left; padding: 0.5rem 1rem; border-top: 1px solid var(--color-border); white-space: nowrap; }
.right { text-align: right; }
.num { font-variant-numeric: tabular-nums; }
.muted { color: var(--color-text-muted); }
.small { font-size: 0.76rem; }
.neg { color: var(--color-danger, #b91c1c); }
.diff { background: var(--color-warning-soft, rgb(255 200 0 / 10%)); }
.cell-input { width: 7rem; text-align: right; }
.link { color: var(--color-primary); text-decoration: none; font-weight: 600; }
.link:hover { text-decoration: underline; }

.actions { display: flex; justify-content: flex-end; gap: 0.5rem; padding: 0.85rem 1.25rem; }

.flash { margin-bottom: 0.75rem; padding: 0.6rem 0.9rem; border-radius: var(--radius-sm); font-size: 0.85rem; }
.flash-error { background: var(--color-danger-soft); color: var(--color-danger); }
</style>
