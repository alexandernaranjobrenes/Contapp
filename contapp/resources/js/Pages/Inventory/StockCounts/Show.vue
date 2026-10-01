<script setup>
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import ConfirmModal from '../../../Components/ConfirmModal.vue';
import { ArrowLeftIcon, CheckIcon, PrinterIcon, SaveIcon } from '@lucide/vue';

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
        <div class="view-toolbar">
            <Link :href="route('stock-counts.index')" class="btn btn-ghost"><ArrowLeftIcon /> Tomas físicas</Link>
            <div class="view-actions">
                <a :href="route('stock-counts.print', count.id)" class="btn btn-ghost"><PrinterIcon /> Imprimir hoja</a>
                <button v-if="isOpen" type="button" class="btn btn-ghost btn-danger-text" @click="confirmingCancel = true">
                    Cancelar toma
                </button>
                <button
                    v-if="isOpen" type="button" class="btn btn-primary"
                    :disabled="pending > 0"
                    @click="confirmingPost = true"
                >
                    <CheckIcon /> Cerrar y ajustar
                </button>
            </div>
        </div>

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

        <!-- Hoja de conteo: lo contado se captura en la fila (CLAUDE.md secc.
             20, excepción de las grillas de captura). -->
        <form @submit.prevent="saveCounts">
            <div class="card">
                <div class="table-responsive capture-grid">
                    <table>
                        <thead>
                            <tr>
                                <th>Artículo</th>
                                <th v-if="lines.some((l) => l.bin_code)">Ubicación</th>
                                <th v-if="!count.blind || !isOpen" class="num">Existencia del sistema</th>
                                <th class="num">Contado</th>
                                <th class="num">Diferencia</th>
                                <th class="num">Valor</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="line in lines"
                                :key="line.id"
                                :class="{ diff: liveDifference(line) !== null && liveDifference(line) !== 0 }"
                            >
                                <td><span class="line-number">{{ line.line_number }}.</span> {{ line.item }}</td>
                                <td v-if="lines.some((l) => l.bin_code)" data-label="Ubicación" class="code">{{ line.bin_code ?? '—' }}</td>
                                <td v-if="!count.blind || !isOpen" data-label="Existencia del sistema" class="num">{{ quantity(line.theoretical_quantity) }}</td>
                                <td data-label="Contado" class="num">
                                    <input
                                        v-if="isOpen"
                                        v-model="form.counted[line.id]"
                                        type="number" step="0.000001" min="0" class="cell-input"
                                        :aria-label="`Contado de la línea ${line.line_number}`"
                                    >
                                    <span v-else>{{ quantity(line.counted_quantity) }}</span>
                                </td>
                                <td data-label="Diferencia" class="num" :class="liveDifference(line) < 0 ? 'neg' : ''">
                                    {{ liveDifference(line) === null ? '—' : quantity(liveDifference(line)) }}
                                </td>
                                <td data-label="Valor" class="num muted">
                                    {{ liveDifference(line) === null ? '—' : money(liveDifference(line) * line.unit_cost_local) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div v-if="isOpen" class="form-actions">
                <button type="submit" class="btn btn-primary" :disabled="form.processing"><SaveIcon /> Guardar conteo</button>
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
.summary { display: flex; flex-wrap: wrap; gap: 0.75rem 1.5rem; padding: 1rem 1.25rem; margin-bottom: 0.75rem; }
.summary > div { display: flex; flex-direction: column; gap: 0.15rem; }

.totals-card { display: flex; flex-wrap: wrap; gap: 0.75rem 1.75rem; padding: 0.85rem 1.25rem; margin-bottom: 0.75rem; }
.totals-card > div { display: flex; flex-direction: column; gap: 0.15rem; }

table { font-size: 0.85rem; }
th, td { padding: 0.5rem 0.75rem; }
.num, .code { font-variant-numeric: tabular-nums; }
.line-number { color: var(--color-text-muted); font-variant-numeric: tabular-nums; }
.neg { color: var(--color-danger); }
.diff td { background: var(--color-warning-soft); }
.cell-input { width: 7rem; text-align: right; }
.link { color: var(--color-primary); text-decoration: none; font-weight: 600; }
.link:hover { text-decoration: underline; }

@media screen and (max-width: 1024px) {
    .diff td { background: none; }
    .table-responsive tbody tr.diff { background: var(--color-warning-soft); }
    .cell-input { width: auto; }
}
</style>
