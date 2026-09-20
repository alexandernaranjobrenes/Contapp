<script setup>
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
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
        <template #actions>
            <input v-model="asOf" type="date" class="date-input" @change="reloadAsOf">
            <Link :href="route('inventory-write-downs.index')" class="btn btn-ghost">Ver avalúos</Link>
        </template>

        <div v-if="page.props.errors?.lines" class="flash flash-error">{{ page.props.errors.lines }}</div>

        <p class="hint">
            NIC 2 §28: el inventario se valúa al <strong>menor entre costo y valor neto realizable</strong>.
            Digitá el VNR solo de los artículos que estás revisando — precio de venta esperado menos costos de
            terminación y de venta. La estimación es un contra-activo: <strong>no rebaja el costo</strong> del
            inventario ni toca el kardex. Si el VNR se recupera, un avalúo posterior reversa lo estimado (§33).
        </p>

        <form class="card" @submit.prevent="submit">
            <div class="field">
                <label>Descripción del avalúo (opcional)</label>
                <input v-model="form.description" type="text" maxlength="255">
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Artículo</th>
                        <th class="num">Existencia</th>
                        <th class="num">Costo unitario</th>
                        <th class="num">Costo total</th>
                        <th class="num">Ya estimado</th>
                        <th class="num">VNR unitario</th>
                        <th class="num">Estimación objetivo</th>
                        <th class="num">A contabilizar</th>
                        <th>Motivo</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="i in items" :key="i.id">
                        <td><strong class="num">{{ i.code }}</strong> — {{ i.name }}</td>
                        <td class="num">{{ quantity(i.quantity) }}</td>
                        <td class="num">{{ money(i.unit_cost_local) }}</td>
                        <td class="num">{{ money(i.cost_value_local) }}</td>
                        <td class="num muted">{{ money(i.current_allowance) }}</td>
                        <td class="num">
                            <input
                                v-model="nrv[i.id]"
                                type="number" step="0.000001" min="0"
                                class="nrv-input" placeholder="—"
                            >
                        </td>
                        <td class="num">{{ projection(i) ? money(projection(i).target) : '—' }}</td>
                        <td class="num">
                            <span v-if="projection(i)" :class="projection(i).movement < 0 ? 'reversal' : 'impairment'">
                                {{ money(projection(i).movement) }}
                            </span>
                            <span v-else>—</span>
                        </td>
                        <td>
                            <input v-model="reasons[i.id]" type="text" maxlength="255" :disabled="!projection(i)">
                        </td>
                    </tr>
                    <tr v-if="!items.length">
                        <td colspan="9" class="muted empty-row">
                            No hay artículos con existencia al {{ asOf }}.
                        </td>
                    </tr>
                </tbody>
                <tfoot v-if="assessed.length">
                    <tr>
                        <td colspan="7" class="total-label">Efecto neto en resultados</td>
                        <td class="num total-value" :class="totalMovement < 0 ? 'reversal' : 'impairment'">
                            {{ money(totalMovement) }}
                        </td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>

            <p v-if="totalMovement < 0" class="hint">
                El neto es negativo: este avalúo <strong>reversa</strong> estimación reconocida antes, lo que
                reduce el gasto del periodo. No es un ingreso.
            </p>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary" :disabled="form.processing || !assessed.length">
                    Contabilizar avalúo
                </button>
                <span v-if="!assessed.length" class="muted small">Digitá al menos un VNR.</span>
            </div>
        </form>
    </AppLayout>
</template>

<style scoped>
.num { text-align: right; }
.nrv-input { width: 7rem; text-align: right; }
.total-label { text-align: right; font-weight: 600; }
.total-value { font-weight: 700; }
.impairment { color: #a02020; }
.reversal { color: #1d7a3c; }
.form-actions { display: flex; gap: 0.75rem; align-items: center; padding-top: 0.75rem; }
</style>
