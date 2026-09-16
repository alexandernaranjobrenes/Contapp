<script setup>
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DocumentToolbar from '../../../Components/DocumentToolbar.vue';

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

const creating = ref(false);

const createForm = useForm({
    scope_level: 'company',
    scope_id: '',
    category: 'inventory',
    account_id: '',
    cost_allocation_rule_id: '',
});

function openCreate() {
    createForm.reset();
    creating.value = true;
}

function submitCreate() {
    createForm
        .transform((data) => ({
            ...data,
            scope_id: data.scope_level === 'company' || data.scope_id === '' ? null : data.scope_id,
            cost_allocation_rule_id: data.cost_allocation_rule_id === '' ? null : data.cost_allocation_rule_id,
        }))
        .post(route('gl-determinations.store'), { onSuccess: () => (creating.value = false), preserveScroll: true });
}

const editing = ref(null);

const editForm = useForm({ account_id: '', cost_allocation_rule_id: '' });

function openEdit(determination) {
    editForm.clearErrors();
    editForm.account_id = determination.account_id;
    editForm.cost_allocation_rule_id = determination.cost_allocation_rule_id ?? '';
    editing.value = determination;
}

function submitEdit() {
    editForm
        .transform((data) => ({
            ...data,
            cost_allocation_rule_id: data.cost_allocation_rule_id === '' ? null : data.cost_allocation_rule_id,
        }))
        .put(route('gl-determinations.update', editing.value.id), { onSuccess: () => (editing.value = null), preserveScroll: true });
}

function destroy(determination) {
    if (! confirm(`¿Eliminar la regla de "${props.categories[determination.category]}" para ${determination.scope_name}?`)) return;

    router.delete(route('gl-determinations.destroy', determination.id), { preserveScroll: true });
}
</script>

<template>
    <Head title="Determinación de cuentas" />

    <AppLayout title="Determinación de cuentas">
        <DocumentToolbar can-create @new="openCreate()" />

        <div v-if="page.props.errors?.gl_determination" class="flash flash-error">{{ page.props.errors.gl_determination }}</div>

        <p class="hint">
            Define a qué cuenta contable va cada categoría de movimiento. Se resuelve de lo <strong>más específico a lo más
            general</strong>: artículo, luego grupo, luego almacén, luego compañía. Si nada resuelve, se usa la cuenta por
            defecto del tipo de documento; si tampoco hay, el movimiento se rechaza entero y no queda contabilizado nada.
        </p>

        <div class="card">
            <div class="card-header">
                <span class="muted">{{ sorted.length }} regla(s)</span>
                <button type="button" class="btn btn-primary" @click="openCreate()">+ Nueva regla</button>
            </div>

            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Alcance</th>
                            <th>Aplica a</th>
                            <th>Categoría</th>
                            <th>Cuenta</th>
                            <th>Norma de reparto</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="d in sorted" :key="d.id">
                            <td><span class="badge badge-neutral">{{ scopeLevels[d.scope_level] }}</span></td>
                            <td class="code-cell">{{ d.scope_name }}</td>
                            <td>{{ categories[d.category] }}</td>
                            <td class="muted small">{{ d.account_label }}</td>
                            <td class="muted small">{{ d.cost_allocation_rule_label ?? '—' }}</td>
                            <td class="actions-cell">
                                <button type="button" class="btn btn-ghost" @click="openEdit(d)">Editar</button>
                                <button type="button" class="btn btn-ghost" @click="destroy(d)">Eliminar</button>
                            </td>
                        </tr>
                        <tr v-if="!sorted.length">
                            <td colspan="6" class="muted empty-row">
                                Todavía no hay reglas configuradas. Sin al menos las de nivel compañía, ningún movimiento de
                                inventario podrá contabilizarse.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="creating" class="modal-backdrop" @click.self="creating = false">
            <form class="modal-card card" @submit.prevent="submitCreate">
                <h2>Nueva regla de determinación</h2>

                <div class="grid-2">
                    <div class="field">
                        <label>Alcance</label>
                        <select v-model="createForm.scope_level" @change="createForm.scope_id = ''">
                            <option v-for="(label, key) in scopeLevels" :key="key" :value="key">{{ label }}</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>Aplica a</label>
                        <select v-model="createForm.scope_id" :disabled="createForm.scope_level === 'company'">
                            <option value="">{{ createForm.scope_level === 'company' ? 'Toda la compañía' : '— Elegir —' }}</option>
                            <option v-for="o in scopeOptions[createForm.scope_level]" :key="o.id" :value="o.id">
                                {{ o.code }} — {{ o.name }}
                            </option>
                        </select>
                        <span v-if="createForm.errors.scope_id" class="error">{{ createForm.errors.scope_id }}</span>
                    </div>
                </div>

                <div class="field">
                    <label>Categoría contable</label>
                    <select v-model="createForm.category">
                        <option v-for="(label, key) in categories" :key="key" :value="key">{{ label }}</option>
                    </select>
                    <span v-if="createForm.errors.category" class="error">{{ createForm.errors.category }}</span>
                </div>

                <div class="field">
                    <label>Cuenta contable</label>
                    <select v-model="createForm.account_id" required>
                        <option value="" disabled>— Elegir —</option>
                        <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.description_es }}</option>
                    </select>
                    <span v-if="createForm.errors.account_id" class="error">{{ createForm.errors.account_id }}</span>
                </div>

                <div class="field">
                    <label>Norma de reparto (obligatoria si la cuenta exige centro de costo)</label>
                    <select v-model="createForm.cost_allocation_rule_id">
                        <option value="">— Ninguna —</option>
                        <option v-for="r in costAllocationRules" :key="r.id" :value="r.id">{{ r.code }} — {{ r.name }}</option>
                    </select>
                    <span v-if="createForm.errors.cost_allocation_rule_id" class="error">{{ createForm.errors.cost_allocation_rule_id }}</span>
                </div>

                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary" :disabled="createForm.processing">Guardar</button>
                    <button type="button" class="btn btn-ghost" @click="creating = false">Cancelar</button>
                </div>
            </form>
        </div>

        <div v-if="editing" class="modal-backdrop" @click.self="editing = null">
            <form class="modal-card card" @submit.prevent="submitEdit">
                <h2>Editar regla</h2>
                <p class="muted small">
                    {{ scopeLevels[editing.scope_level] }} · {{ editing.scope_name }} · {{ categories[editing.category] }}.
                    El alcance y la categoría no se cambian: sería otra regla distinta.
                </p>

                <div class="field">
                    <label>Cuenta contable</label>
                    <select v-model="editForm.account_id" required>
                        <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.description_es }}</option>
                    </select>
                    <span v-if="editForm.errors.account_id" class="error">{{ editForm.errors.account_id }}</span>
                </div>

                <div class="field">
                    <label>Norma de reparto</label>
                    <select v-model="editForm.cost_allocation_rule_id">
                        <option value="">— Ninguna —</option>
                        <option v-for="r in costAllocationRules" :key="r.id" :value="r.id">{{ r.code }} — {{ r.name }}</option>
                    </select>
                </div>

                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary" :disabled="editForm.processing">Guardar</button>
                    <button type="button" class="btn btn-ghost" @click="editing = null">Cancelar</button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>

<style scoped>
.card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.85rem 1.1rem;
    border-bottom: 1px solid var(--color-border);
}

.flash { margin-bottom: 0.75rem; padding: 0.6rem 0.9rem; border-radius: var(--radius-sm); font-size: 0.85rem; }
.flash-error { background: var(--color-danger-soft); color: var(--color-danger); }

.hint { font-size: 0.82rem; color: var(--color-text-muted); margin: -0.5rem 0 1rem; }

.table-scroll { overflow-x: auto; }
table { font-size: 0.85rem; width: 100%; }
th, td { text-align: left; padding: 0.5rem 1rem; border-top: 1px solid var(--color-border); white-space: nowrap; }
.code-cell { font-variant-numeric: tabular-nums; }
.muted { color: var(--color-text-muted); }
.small { font-size: 0.76rem; }
.empty-row { text-align: center; padding: 1.5rem; white-space: normal; }
.actions-cell { display: flex; gap: 0.4rem; }

.modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(11, 31, 58, 0.45);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 50;
    padding: 1rem;
}

.modal-card { width: 560px; max-width: 100%; max-height: 90vh; overflow-y: auto; padding: 1.5rem; }
.modal-card h2 { font-size: 1rem; margin: 0 0 0.5rem; }

.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0 1rem; }

.field { display: flex; flex-direction: column; gap: 0.2rem; margin-bottom: 0.75rem; }
.field label { font-size: 0.78rem; color: var(--color-text-muted); }

.field input, .field select {
    width: 100%;
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.45rem 0.6rem;
    font-size: 0.85rem;
    color: var(--color-text);
}

.error { color: var(--color-danger); font-size: 0.76rem; }
.modal-actions { display: flex; gap: 0.6rem; margin-top: 1rem; }
</style>
