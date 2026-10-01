<script setup>
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { ArrowLeftIcon, CheckIcon } from '@lucide/vue';
import AppLayout from '../../../Layouts/AppLayout.vue';

const props = defineProps({
    asOf: { type: String, required: true },
    items: { type: Array, default: () => [] },
});

const page = usePage();
const asOf = ref(props.asOf);

// El VNR se digita por artículo y solo para los que se están revisando: un
// avalúo normal toca unos pocos, no el catálogo entero.
const nrv = ref({});
const reasons = ref({});

function reloadAsOf() {
    router.get(route('inventory-write-downs.create'), { as_of: asOf.value }, { preserveState: false });
}

function money(value) {
    return Number(value ?? 0).toLocaleString('es-CR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function quantity(value) {
    return Number(value ?? 0).toLocaleString('es-CR', { minimumFractionDigits: 0, maximumFractionDigits: 6 });
}

/**
 * Mismo cálculo que hace el servidor, para que quien avalúa vea el efecto
 * antes de contabilizar. El servidor lo vuelve a calcular: esto es una
 * ayuda visual, no la fuente de verdad.
 */
function projection(item) {
    const raw = nrv.value[item.id];

    if (raw === undefined || raw === '') return null;

    const nrvValue = Number(raw) * Number(item.quantity);
    const target = Math.max(0, Number(item.cost_value_local) - nrvValue);
    const movement = target - Number(item.current_allowance);

    return { nrvValue, target, movement };
}

const assessed = computed(() => props.items.filter((i) => projection(i) !== null));

const totalMovement = computed(() => assessed.value
    .reduce((sum, i) => sum + projection(i).movement, 0));

const form = useForm({ as_of: props.asOf, description: '', lines: [] });

function submit() {
    form.transform(() => ({
        as_of: asOf.value,
        description: form.description === '' ? null : form.description,
        lines: assessed.value.map((i) => ({
            item_id: i.id,
            nrv_unit: nrv.value[i.id],
            reason: reasons.value[i.id] || null,
        })),
    })).post(route('inventory-write-downs.store'));
}
</script>

<template>
    <Head title="Avalúo de deterioro" />

    <AppLayout title="Avalúo de deterioro de inventario">
        <div class="view-toolbar">
            <Link :href="route('inventory-write-downs.index')" class="btn btn-ghost"><ArrowLeftIcon /> Avalúos</Link>
            <div class="view-filters">
                <label class="filter-field">
                    <span>Corte al</span>
                    <input v-model="asOf" type="date" @change="reloadAsOf">
                </label>
            </div>
        </div>

        <div v-if="page.props.errors?.lines" class="flash flash-error">{{ page.props.errors.lines }}</div>

        <p class="hint">
            NIC 2 §28: el inventario se valúa al <strong>menor entre costo y valor neto realizable</strong>.
            Digitá el VNR solo de los artículos que estás revisando — precio de venta esperado menos costos de
            terminación y de venta. La estimación es un contra-activo: <strong>no rebaja el costo</strong> del
            inventario ni toca el kardex. Si el VNR se recupera, un avalúo posterior reversa lo estimado (§33).
        </p>

        <form @submit.prevent="submit">
            <div class="field description-field">
                <label for="wd-description">Descripción del avalúo (opcional)</label>
                <input id="wd-description" v-model="form.description" type="text" maxlength="255">
            </div>

            <!-- Grilla de captura: el VNR y el motivo se digitan en la fila
                 (CLAUDE.md secc. 20, excepción). Existencia y costo unitario van
                 debajo del artículo; la estimación objetivo, debajo de lo que se
                 va a contabilizar. -->
            <div class="card">
                <div class="table-responsive capture-grid">
                    <table>
                        <thead>
                            <tr>
                                <th>Artículo</th>
                                <th class="num">Costo total</th>
                                <th class="num">Ya estimado</th>
                                <th class="num">VNR unitario</th>
                                <th class="num">A contabilizar</th>
                                <th>Motivo</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="i in items" :key="i.id">
                                <td>
                                    <strong class="code">{{ i.code }}</strong> — {{ i.name }}
                                    <span class="breakdown">{{ quantity(i.quantity) }} a {{ money(i.unit_cost_local) }}</span>
                                </td>
                                <td data-label="Costo total" class="num">{{ money(i.cost_value_local) }}</td>
                                <td data-label="Ya estimado" class="num muted">{{ money(i.current_allowance) }}</td>
                                <td data-label="VNR unitario" class="num">
                                    <input
                                        v-model="nrv[i.id]"
                                        type="number" step="0.000001" min="0"
                                        class="nrv-input" placeholder="—"
                                        :aria-label="`VNR unitario de ${i.code}`"
                                    >
                                </td>
                                <td data-label="A contabilizar" class="num">
                                    <template v-if="projection(i)">
                                        <span :class="projection(i).movement < 0 ? 'reversal' : 'impairment'">{{ money(projection(i).movement) }}</span>
                                        <span class="breakdown">objetivo {{ money(projection(i).target) }}</span>
                                    </template>
                                    <span v-else>—</span>
                                </td>
                                <td data-label="Motivo">
                                    <input v-model="reasons[i.id]" class="reason-input" type="text" maxlength="255" :disabled="!projection(i)" :aria-label="`Motivo de ${i.code}`">
                                </td>
                            </tr>
                            <tr v-if="!items.length">
                                <td colspan="6" class="muted empty-row">
                                    No hay artículos con existencia al {{ asOf }}.
                                </td>
                            </tr>
                        </tbody>
                        <tfoot v-if="assessed.length">
                            <tr>
                                <td colspan="4" class="total-label">Efecto neto en resultados</td>
                                <td data-label="Efecto neto" class="num total-value" :class="totalMovement < 0 ? 'reversal' : 'impairment'">
                                    {{ money(totalMovement) }}
                                </td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <p v-if="totalMovement < 0" class="hint net-hint">
                El neto es negativo: este avalúo <strong>reversa</strong> estimación reconocida antes, lo que
                reduce el gasto del periodo. No es un ingreso.
            </p>

            <div class="form-actions">
                <span v-if="!assessed.length" class="muted small">Digitá al menos un VNR.</span>
                <button type="submit" class="btn btn-primary" :disabled="form.processing || !assessed.length">
                    <CheckIcon /> Contabilizar avalúo
                </button>
            </div>
        </form>
    </AppLayout>
</template>

<style scoped>
table { font-size: 0.85rem; }
th, td { padding: 0.5rem 0.6rem; }
.code { font-variant-numeric: tabular-nums; }
.description-field { max-width: none; }
.breakdown { display: block; font-size: 0.7rem; color: var(--color-text-muted); font-weight: 400; }
.nrv-input { width: 6.5rem; text-align: right; }
/* El motivo toma lo que quede de ancho: con su tamaño natural empujaba la
   tabla más allá de los 720px útiles a 1025px. */
.reason-input { width: 100%; min-width: 6rem; }
.total-label { text-align: right; font-weight: 600; }
.total-value { font-weight: 700; }
.impairment { color: var(--color-danger); }
.reversal { color: var(--color-success); }
.net-hint { margin-top: 0.75rem; }
.form-actions { align-items: center; margin-top: 0.75rem; }

@media screen and (max-width: 1024px) {
    .nrv-input { width: auto; }
    .total-label { text-align: left; }
    .breakdown { display: inline; margin-left: 0.4rem; }
}
</style>
