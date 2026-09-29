<script setup>
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { PencilIcon, PlusIcon } from '@lucide/vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DetailModal from '../../../Components/DetailModal.vue';
import { confirmAction } from '../../../Utils/confirm';
import { useCrudModal } from '../../../Utils/crudModal';

const props = defineProps({
    determinations: { type: Array, default: () => [] },
    scopeLevels: { type: Object, default: () => ({}) },
    categories: { type: Object, default: () => ({}) },
    items: { type: Array, default: () => [] },
    itemGroups: { type: Array, default: () => [] },
    warehouses: { type: Array, default: () => [] },
    accounts: { type: Array, default: () => [] },
    costAllocationRules: { type: Array, default: () => [] },
});

const page = usePage();

// El orden de scopeLevels ES la precedencia que aplica el resolver, así que
// la tabla se muestra en ese mismo orden: lo más específico arriba.
const levelOrder = computed(() => Object.keys(props.scopeLevels));

const sorted = computed(() => [...props.determinations].sort((a, b) => {
    const byLevel = levelOrder.value.indexOf(a.scope_level) - levelOrder.value.indexOf(b.scope_level);
    if (byLevel !== 0) return byLevel;
    return a.category.localeCompare(b.category);
}));

const scopeOptions = computed(() => ({
    item: props.items,
    item_group: props.itemGroups,
    warehouse: props.warehouses,
    company: [],
}));

// Ficha, alta y edición en un solo modal (CLAUDE.md secc. 20 y 21). Alcance y
// categoría solo se eligen al crear: cambiarlos sería otra regla distinta.
const { mode, selected, modalOpen, form, openCreate, openDetail, close, startEdit, cancelForm, submit } = useCrudModal({
    records: () => props.determinations,
    defaults: () => ({ scope_level: 'company', scope_id: '', category: 'inventory', account_id: '', cost_allocation_rule_id: '' }),
    toForm: (d) => ({
        scope_level: d.scope_level,
        scope_id: d.scope_id ?? '',
        category: d.category,
        account_id: d.account_id,
        cost_allocation_rule_id: d.cost_allocation_rule_id ?? '',
    }),
    store: () => route('gl-determinations.store'),
    update: (d) => route('gl-determinations.update', d.id),
    storePayload: (data) => ({
        ...data,
        scope_id: data.scope_level === 'company' || data.scope_id === '' ? null : data.scope_id,
        cost_allocation_rule_id: data.cost_allocation_rule_id === '' ? null : data.cost_allocation_rule_id,
    }),
    updatePayload: ({ account_id, cost_allocation_rule_id }) => ({
        account_id,
        cost_allocation_rule_id: cost_allocation_rule_id === '' ? null : cost_allocation_rule_id,
    }),
});

const modalTitle = computed(() => {
    if (mode.value === 'create') return 'Nueva regla de determinación';
    return selected.value ? `${props.categories[selected.value.category]} — ${selected.value.scope_name}` : '';
});

function destroy() {
    const d = selected.value;

    confirmAction({
        title: 'Eliminar regla',
        message: `Se elimina la regla de «${props.categories[d.category]}» para ${d.scope_name}.`,
        confirmLabel: 'Eliminar',
        danger: true,
        onConfirm: () => router.delete(route('gl-determinations.destroy', d.id), { preserveScroll: true }),
    });
}
</script>

<template>
    <Head title="Determinación de cuentas" />

    <AppLayout title="Determinación de cuentas">
        <div class="view-toolbar">
            <div class="view-actions">
                <button type="button" class="btn btn-primary" @click="openCreate()"><PlusIcon /> Crear nuevo</button>
            </div>
        </div>

        <div v-if="page.props.errors?.gl_determination" class="flash flash-error">{{ page.props.errors.gl_determination }}</div>

        <p class="hint">
            Define a qué cuenta contable va cada categoría de movimiento. Se resuelve de lo <strong>más específico a lo más
            general</strong>: artículo, luego grupo, luego almacén, luego compañía. Si nada resuelve, se usa la cuenta por
            defecto del tipo de documento; si tampoco hay, el movimiento se rechaza entero y no queda contabilizado nada.
        </p>

        <div class="card">
            <div class="table-responsive table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Categoría</th>
                            <th>Alcance</th>
                            <th>Aplica a</th>
                            <th>Cuenta</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="d in sorted"
                            :key="d.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="openDetail(d)"
                            @keydown.enter="openDetail(d)"
                            @keydown.space.prevent="openDetail(d)"
                        >
                            <td>{{ categories[d.category] }}</td>
                            <td data-label="Alcance"><span class="badge badge-neutral">{{ scopeLevels[d.scope_level] }}</span></td>
                            <td data-label="Aplica a" class="code-cell">{{ d.scope_name }}</td>
                            <td data-label="Cuenta" class="muted">{{ d.account_label }}</td>
                        </tr>
                        <tr v-if="!sorted.length">
                            <td colspan="4" class="muted empty-row">
                                Todavía no hay reglas configuradas. Sin al menos las de nivel compañía, ningún movimiento de
                                inventario podrá contabilizarse.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <DetailModal :open="modalOpen" :title="modalTitle" @close="close">
            <template #badge>
                <span v-if="selected && mode !== 'create'" class="badge badge-neutral">{{ scopeLevels[selected.scope_level] }}</span>
            </template>

            <dl v-if="selected && mode === 'details'" class="detail-list">
                <div>
                    <dt>Categoría</dt>
                    <dd>{{ categories[selected.category] }}</dd>
                </div>
                <div>
                    <dt>Aplica a</dt>
                    <dd>{{ selected.scope_name }}</dd>
                </div>
                <div class="full">
                    <dt>Cuenta contable</dt>
                    <dd>{{ selected.account_label }}</dd>
                </div>
                <div class="full">
                    <dt>Norma de reparto</dt>
                    <dd>{{ selected.cost_allocation_rule_label ?? '—' }}</dd>
                </div>
            </dl>

            <form v-if="mode === 'create' || (selected && mode === 'edit')" id="determination-form" @submit.prevent="submit">
                <p v-if="mode === 'edit'" class="muted small">
                    {{ scopeLevels[selected.scope_level] }} · {{ selected.scope_name }} · {{ categories[selected.category] }}.
                    El alcance y la categoría no se cambian: sería otra regla distinta.
                </p>

                <template v-if="mode === 'create'">
                    <div class="field-row">
                        <div class="field">
                            <label for="det-scope">Alcance</label>
                            <select id="det-scope" v-model="form.scope_level" @change="form.scope_id = ''">
                                <option v-for="(label, key) in scopeLevels" :key="key" :value="key">{{ label }}</option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="det-scope-id">Aplica a</label>
                            <select id="det-scope-id" v-model="form.scope_id" :disabled="form.scope_level === 'company'">
                                <option value="">{{ form.scope_level === 'company' ? 'Toda la compañía' : '— Elegir —' }}</option>
                                <option v-for="o in scopeOptions[form.scope_level]" :key="o.id" :value="o.id">
                                    {{ o.code }} — {{ o.name }}
                                </option>
                            </select>
                            <span v-if="form.errors.scope_id" class="error">{{ form.errors.scope_id }}</span>
                        </div>
                    </div>

                    <div class="field">
                        <label for="det-category">Categoría contable</label>
                        <select id="det-category" v-model="form.category">
                            <option v-for="(label, key) in categories" :key="key" :value="key">{{ label }}</option>
                        </select>
                        <span v-if="form.errors.category" class="error">{{ form.errors.category }}</span>
                    </div>
                </template>

                <div class="field">
                    <label for="det-account">Cuenta contable</label>
                    <select id="det-account" v-model="form.account_id" required>
                        <option value="" disabled>— Elegir —</option>
                        <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.description_es }}</option>
                    </select>
                    <span v-if="form.errors.account_id" class="error">{{ form.errors.account_id }}</span>
                </div>

                <div class="field">
                    <label for="det-rule">Norma de reparto (obligatoria si la cuenta exige centro de costo)</label>
                    <select id="det-rule" v-model="form.cost_allocation_rule_id">
                        <option value="">— Ninguna —</option>
                        <option v-for="r in costAllocationRules" :key="r.id" :value="r.id">{{ r.code }} — {{ r.name }}</option>
                    </select>
                    <span v-if="form.errors.cost_allocation_rule_id" class="error">{{ form.errors.cost_allocation_rule_id }}</span>
                </div>
            </form>

            <template #actions>
                <template v-if="selected && mode === 'details'">
                    <button type="button" class="btn btn-ghost btn-danger-text" @click="destroy">Eliminar</button>
                    <button type="button" class="btn btn-primary" @click="startEdit"><PencilIcon /> Editar</button>
                </template>
                <template v-else-if="modalOpen">
                    <button type="button" class="btn btn-ghost" @click="cancelForm">Cancelar</button>
                    <button type="submit" form="determination-form" class="btn btn-primary" :disabled="form.processing">
                        {{ mode === 'create' ? 'Crear' : 'Guardar' }}
                    </button>
                </template>
            </template>
        </DetailModal>
    </AppLayout>
</template>

<style scoped>
table { font-size: 0.85rem; }
.code-cell { font-variant-numeric: tabular-nums; }
</style>
