<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import DetailModal from '../../Components/DetailModal.vue';
import { confirmAction } from '../../Utils/confirm';
import { useCrudModal } from '../../Utils/crudModal';
import { ArrowLeftIcon, PencilIcon, PlusIcon } from '@lucide/vue';

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

// Ficha, alta y edición en un solo modal (CLAUDE.md secc. 20 y 21).
const { mode, selected, modalOpen, form, openCreate, openDetail, close, startEdit, cancelForm, submit } = useCrudModal({
    records: () => props.categories,
    defaults: () => ({ code: '', name: '', price_list_id: null }),
    toForm: (c) => ({ code: c.code, name: c.name, price_list_id: c.price_list_id }),
    store: () => route('bp-categories.store'),
    update: (c) => route('bp-categories.update', c.id),
});

const modalTitle = computed(() => {
    if (mode.value === 'create') return 'Nueva categoría';
    return selected.value ? `${selected.value.code} — ${selected.value.name}` : '';
});

function destroy() {
    const c = selected.value;

    confirmAction({
        title: 'Eliminar categoría',
        message: `La categoría ${c.code} — ${c.name} se elimina de forma definitiva.`,
        confirmLabel: 'Eliminar',
        danger: true,
        onConfirm: () => router.delete(route('bp-categories.destroy', c.id), { preserveScroll: true }),
    });
}
</script>

<template>
    <Head title="Categorías de socios" />

    <AppLayout title="Categorías de socios">
        <div class="view-toolbar">
            <Link :href="route('business-partners.index')" class="btn btn-ghost"><ArrowLeftIcon /> Socios de negocio</Link>
            <div class="view-actions">
                <button type="button" class="btn btn-primary" @click="openCreate()"><PlusIcon /> Crear nuevo</button>
            </div>
            <div class="view-filters">
                <input v-model="search" type="search" placeholder="Buscar código o nombre..." aria-label="Buscar categoría">
                <span class="muted small">{{ filtered.length }} categoría(s)</span>
            </div>
        </div>

        <p class="hint">
            Categorías propias de esta compañía para agrupar clientes y proveedores (ej. "Mayorista", "Minorista", "Gobierno") — sirven para filtrar y comparar reportes de ventas por categoría.
            Asignale una a cada socio desde su ficha en <Link :href="route('business-partners.index')">Socios de negocio</Link>.
        </p>

        <div v-if="page.props.errors?.bp_category" class="flash flash-error">{{ page.props.errors.bp_category }}</div>

        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Nombre</th>
                            <th>Lista de precios</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="c in filtered"
                            :key="c.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="openDetail(c)"
                            @keydown.enter="openDetail(c)"
                            @keydown.space.prevent="openDetail(c)"
                        >
                            <td class="code-cell">{{ c.code }}</td>
                            <td data-label="Nombre">{{ c.name }}</td>
                            <td data-label="Lista de precios" class="muted">{{ c.price_list ?? '—' }}</td>
                        </tr>
                        <tr v-if="!filtered.length">
                            <td colspan="3" class="muted empty-row">
                                {{ categories.length ? 'Ninguna categoría coincide con la búsqueda.' : 'Todavía no hay categorías registradas.' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <DetailModal :open="modalOpen" :title="modalTitle" @close="close">
            <dl v-if="selected && mode === 'details'" class="detail-list">
                <div>
                    <dt>Lista de precios que heredan sus socios</dt>
                    <dd>{{ selected.price_list ?? 'Ninguna (usan la predeterminada)' }}</dd>
                </div>
            </dl>

            <form v-if="mode === 'create' || (selected && mode === 'edit')" id="bp-category-form" @submit.prevent="submit">
                <div class="field-row">
                    <div class="field">
                        <label for="category-code">Código</label>
                        <input id="category-code" v-model="form.code" type="text" autocomplete="off" placeholder="MAYORISTA" required>
                        <span v-if="form.errors.code" class="error">{{ form.errors.code }}</span>
                    </div>
                    <div class="field">
                        <label for="category-name">Nombre</label>
                        <input id="category-name" v-model="form.name" type="text" autocomplete="off" placeholder="Cliente mayorista" required>
                        <span v-if="form.errors.name" class="error">{{ form.errors.name }}</span>
                    </div>
                </div>

                <div class="field">
                    <label for="category-price-list">Lista de precios que heredan sus socios</label>
                    <select id="category-price-list" v-model="form.price_list_id">
                        <option :value="null">Ninguna (usan la predeterminada)</option>
                        <option v-for="p in priceLists" :key="p.id" :value="p.id">{{ p.code }} — {{ p.name }}</option>
                    </select>
                    <span v-if="form.errors.price_list_id" class="error">{{ form.errors.price_list_id }}</span>
                    <span class="muted small">
                        Se configura una vez acá en vez de cliente por cliente. Un socio con lista propia en su
                        ficha le gana a esta.
                    </span>
                </div>
            </form>

            <template #actions>
                <template v-if="selected && mode === 'details'">
                    <button type="button" class="btn btn-ghost btn-danger-text" @click="destroy">Eliminar</button>
                    <button type="button" class="btn btn-primary" @click="startEdit"><PencilIcon /> Editar</button>
                </template>
                <template v-else-if="modalOpen">
                    <button type="button" class="btn btn-ghost" @click="cancelForm">Cancelar</button>
                    <button type="submit" form="bp-category-form" class="btn btn-primary" :disabled="form.processing">
                        {{ mode === 'create' ? 'Crear' : 'Guardar' }}
                    </button>
                </template>
            </template>
        </DetailModal>
    </AppLayout>
</template>

<style scoped>
.hint :deep(a) { color: var(--color-primary); }
table { font-size: 0.85rem; }
.code-cell { font-variant-numeric: tabular-nums; white-space: nowrap; }
</style>
