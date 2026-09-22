<script setup>
import { Head, Link, useForm, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import DocumentToolbar from '../../Components/DocumentToolbar.vue';

const props = defineProps({
    categories: { type: Array, default: () => [] },
    priceLists: { type: Array, default: () => [] },
});

const page = usePage();
const search = ref('');

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (! q) return props.categories;

    return props.categories.filter((c) =>
        c.code.toLowerCase().includes(q) || c.name.toLowerCase().includes(q)
    );
});

const editing = ref(null);

const form = useForm({
    code: '',
    name: '',
    price_list_id: null,
});

// Un solo form reutilizado para crear y editar (mismos campos en los dos
// casos, a diferencia de centros de costo) — blankForm() se llama tanto al
// abrir "Nueva categoría" como al cerrar el modal por cualquier vía, para
// que cancelar una edición no deje datos de esa categoría pegados la
// próxima vez que se abre el modal (mismo bug ya encontrado y corregido en
// Tax/Rates.vue).
function blankForm() {
    form.clearErrors();
    form.code = '';
    form.name = '';
    form.price_list_id = null;
}

function openCreate() {
    blankForm();
    editing.value = { isNew: true };
}

function openEdit(category) {
    form.clearErrors();
    form.code = category.code;
    form.name = category.name;
    form.price_list_id = category.price_list_id;
    editing.value = category;
}

function closeModal() {
    editing.value = null;
    blankForm();
}

function submit() {
    if (editing.value.isNew) {
        form.post(route('bp-categories.store'), { onSuccess: closeModal, preserveScroll: true });
    } else {
        form.put(route('bp-categories.update', editing.value.id), { onSuccess: closeModal, preserveScroll: true });
    }
}

function destroy(category) {
    if (! confirm(`¿Eliminar la categoría ${category.code} — ${category.name}? Esta acción no se puede deshacer.`)) return;

    router.delete(route('bp-categories.destroy', category.id), { preserveScroll: true });
}
</script>

<template>
    <Head title="Categorías de socios" />

    <AppLayout title="Categorías de socios">
        <template #actions>
            <input v-model="search" type="search" placeholder="Buscar código o nombre..." class="search-input">
        </template>

        <DocumentToolbar can-create @new="openCreate" />

        <p class="intro muted">
            Categorías propias de esta compañía para agrupar clientes y proveedores (ej. "Mayorista", "Minorista", "Gobierno") — sirven para filtrar y comparar reportes de ventas por categoría.
            Asignale una a cada socio desde su ficha en <Link :href="route('business-partners.index')">Socios de negocio</Link>.
        </p>

        <div v-if="page.props.errors?.bp_category" class="flash flash-error">{{ page.props.errors.bp_category }}</div>

        <div class="card">
            <div class="card-header">
                <span class="muted">{{ filtered.length }} categoría(s)</span>
                <button type="button" class="btn btn-primary" @click="openCreate">+ Nueva categoría</button>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Nombre</th>
                        <th>Lista de precios</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="c in filtered" :key="c.id">
                        <td class="num code-cell">{{ c.code }}</td>
                        <td>{{ c.name }}</td>
                        <td class="muted">{{ c.price_list ?? '—' }}</td>
                        <td class="actions-cell">
                            <button type="button" class="btn btn-ghost" @click="openEdit(c)">Editar</button>
                            <button type="button" class="btn btn-ghost" @click="destroy(c)">Eliminar</button>
                        </td>
                    </tr>
                    <tr v-if="!filtered.length">
                        <td colspan="4" class="muted empty-row">Todavía no hay categorías registradas.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="editing" class="modal-backdrop" @click.self="closeModal">
            <form class="modal-card card" @submit.prevent="submit">
                <h2>{{ editing.isNew ? 'Nueva categoría' : `Editar categoría ${editing.code}` }}</h2>

                <div class="field">
                    <label>Código</label>
                    <input v-model="form.code" type="text" autocomplete="off" placeholder="MAYORISTA" required>
                    <span v-if="form.errors.code" class="error">{{ form.errors.code }}</span>
                </div>

                <div class="field">
                    <label>Nombre</label>
                    <input v-model="form.name" type="text" autocomplete="off" placeholder="Cliente mayorista" required>
                    <span v-if="form.errors.name" class="error">{{ form.errors.name }}</span>
                </div>

                <div class="field">
                    <label>Lista de precios que heredan sus socios</label>
                    <select v-model="form.price_list_id">
                        <option :value="null">Ninguna (usan la predeterminada)</option>
                        <option v-for="p in priceLists" :key="p.id" :value="p.id">{{ p.code }} — {{ p.name }}</option>
                    </select>
                    <span v-if="form.errors.price_list_id" class="error">{{ form.errors.price_list_id }}</span>
                    <span class="hint">
                        Se configura una vez acá en vez de cliente por cliente. Un socio con lista propia en su
                        ficha le gana a esta.
                    </span>
                </div>

                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary" :disabled="form.processing">Guardar</button>
                    <button type="button" class="btn btn-ghost" @click="closeModal">Cancelar</button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>

<style scoped>
.search-input {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.4rem 0.6rem;
    font-size: 0.82rem;
    width: 220px;
}

.intro {
    font-size: 0.85rem;
    margin: 0 0 0.75rem;
    max-width: 70ch;
}

.intro :deep(a) {
    color: var(--color-primary);
}

.card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.85rem 1.1rem;
    border-bottom: 1px solid var(--color-border);
}

.flash { margin-bottom: 0.75rem; padding: 0.6rem 0.9rem; border-radius: var(--radius-sm); font-size: 0.85rem; }
.flash-error { background: var(--color-danger-soft); color: var(--color-danger); }

table { font-size: 0.85rem; width: 100%; }
th, td { text-align: left; padding: 0.5rem 1rem; border-top: 1px solid var(--color-border); white-space: nowrap; }
.code-cell { font-variant-numeric: tabular-nums; }
.num { font-variant-numeric: tabular-nums; }
.muted { color: var(--color-text-muted); }
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

.modal-card {
    width: 420px;
    max-width: 100%;
    max-height: 90vh;
    overflow-y: auto;
    padding: 1.5rem;
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.modal-card h2 { font-size: 1rem; margin: 0; }

.field {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.field label {
    font-size: 0.78rem;
    color: var(--color-text-muted);
}

.field select {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.45rem 0.6rem;
    font-size: 0.85rem;
    color: var(--color-text);
}

.hint {
    font-size: 0.74rem;
    color: var(--color-text-muted);
}

.field input {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.45rem 0.6rem;
    font-size: 0.85rem;
    color: var(--color-text);
}

.error {
    color: var(--color-danger);
    font-size: 0.76rem;
}

.modal-actions {
    display: flex;
    gap: 0.6rem;
    margin-top: 0.25rem;
}
</style>
