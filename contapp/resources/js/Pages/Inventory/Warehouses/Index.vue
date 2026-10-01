<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { MapPinIcon, PencilIcon, PlusIcon } from '@lucide/vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DetailModal from '../../../Components/DetailModal.vue';
import { confirmAction } from '../../../Utils/confirm';
import { useCrudModal } from '../../../Utils/crudModal';

const props = defineProps({
    accounts: { type: Array, default: () => [] },
    accountCategories: { type: Object, default: () => ({}) },
    warehouseAccounts: { type: Object, default: () => ({}) },
    warehouses: { type: Array, default: () => [] },
});

const page = usePage();
const search = ref('');

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    const sorted = [...props.warehouses].sort((a, b) => a.code.localeCompare(b.code));
    if (! q) return sorted;

    return sorted.filter((w) => w.code.toLowerCase().includes(q) || w.name.toLowerCase().includes(q));
});

const accountLabels = computed(() => Object.fromEntries(props.accounts.map((a) => [a.id, a.label])));

// '' significa "sin cuenta propia": viaja como null para que el servidor
// borre la regla y vuelva a heredar del nivel de arriba.
function withAccounts(data) {
    return {
        ...data,
        accounts: Object.fromEntries(
            Object.entries(data.accounts ?? {}).map(([k, v]) => [k, v === '' ? null : v])
        ),
    };
}

// Ficha, alta y edición en un solo modal (CLAUDE.md secc. 20 y 21). El
// código y «maneja ubicaciones» solo se eligen al crear.
const { mode, selected, modalOpen, form, openCreate, openDetail, close, startEdit, cancelForm, submit } = useCrudModal({
    records: () => props.warehouses,
    defaults: () => ({ code: '', name: '', address: '', is_default: false, uses_bins: false, status: 'active', accounts: {} }),
    toForm: (w) => ({
        code: w.code,
        name: w.name,
        address: w.address ?? '',
        is_default: w.is_default,
        uses_bins: w.uses_bins,
        status: w.status,
        accounts: { ...(props.warehouseAccounts[w.id] ?? {}) },
    }),
    store: () => route('warehouses.store'),
    update: (w) => route('warehouses.update', w.id),
    storePayload: withAccounts,
    updatePayload: ({ code, ...data }) => withAccounts(data),
});

const modalTitle = computed(() => {
    if (mode.value === 'create') return 'Nuevo almacén';
    return selected.value ? `${selected.value.code} — ${selected.value.name}` : '';
});

const selectedAccounts = computed(() => {
    if (! selected.value) return [];
    const own = props.warehouseAccounts[selected.value.id] ?? {};

    return Object.entries(props.accountCategories)
        .filter(([key]) => own[key])
        .map(([key, label]) => ({ key, label, account: accountLabels.value[own[key]] ?? own[key] }));
});

function destroy() {
    const w = selected.value;

    confirmAction({
        title: 'Eliminar almacén',
        message: `El almacén ${w.code} — ${w.name} se elimina de forma definitiva.`,
        confirmLabel: 'Eliminar',
        danger: true,
        onConfirm: () => router.delete(route('warehouses.destroy', w.id), { preserveScroll: true }),
    });
}
</script>

<template>
    <Head title="Almacenes" />

    <AppLayout title="Almacenes">
        <div class="view-toolbar">
            <div class="view-actions">
                <button type="button" class="btn btn-primary" @click="openCreate()"><PlusIcon /> Crear nuevo</button>
            </div>
            <div class="view-filters">
                <input v-model="search" type="search" placeholder="Buscar código o nombre..." aria-label="Buscar almacén">
            </div>
        </div>

        <div v-if="page.props.errors?.warehouse" class="flash flash-error">{{ page.props.errors.warehouse }}</div>

        <p class="hint">
            El almacén controla <strong>dónde está</strong> la existencia, no cuánto vale: el costo promedio es global por
            artículo. Un traslado entre almacenes no afecta resultados, y solo genera asiento si cada almacén tiene su
            propia cuenta de inventario en la determinación de cuentas.
        </p>

        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Nombre</th>
                            <th>Dirección</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="w in filtered"
                            :key="w.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="openDetail(w)"
                            @keydown.enter="openDetail(w)"
                            @keydown.space.prevent="openDetail(w)"
                        >
                            <td class="code-cell">{{ w.code }}</td>
                            <td data-label="Nombre">
                                <span class="name-cell">
                                    {{ w.name }}
                                    <span v-if="w.is_default" class="badge badge-warning">Por defecto</span>
                                    <span v-if="w.uses_bins" class="badge badge-neutral">Con ubicaciones</span>
                                </span>
                            </td>
                            <td data-label="Dirección" class="muted">{{ w.address || '—' }}</td>
                            <td data-label="Estado">
                                <span class="badge" :class="w.status === 'active' ? 'badge-success' : 'badge-neutral'">
                                    {{ w.status === 'active' ? 'Activo' : 'Inactivo' }}
                                </span>
                            </td>
                        </tr>
                        <tr v-if="!filtered.length">
                            <td colspan="4" class="muted empty-row">
                                {{ warehouses.length ? 'Ningún almacén coincide con la búsqueda.' : 'Todavía no hay almacenes registrados.' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <DetailModal :open="modalOpen" :title="modalTitle" @close="close">
            <template #badge>
                <span v-if="selected && mode !== 'create'" class="badge" :class="selected.status === 'active' ? 'badge-success' : 'badge-neutral'">
                    {{ selected.status === 'active' ? 'Activo' : 'Inactivo' }}
                </span>
            </template>

            <template v-if="selected && mode === 'details'">
                <dl class="detail-list">
                    <div class="full">
                        <dt>Dirección</dt>
                        <dd>{{ selected.address || '—' }}</dd>
                    </div>
                    <div>
                        <dt>Almacén por defecto</dt>
                        <dd>{{ selected.is_default ? 'Sí' : 'No' }}</dd>
                    </div>
                    <div>
                        <dt>Maneja ubicaciones</dt>
                        <dd>{{ selected.uses_bins ? 'Sí' : 'No' }}</dd>
                    </div>
                </dl>

                <h3 class="section-title">Cuentas contables propias</h3>
                <dl v-if="selectedAccounts.length" class="detail-list">
                    <div v-for="row in selectedAccounts" :key="row.key" class="full">
                        <dt>{{ row.label }}</dt>
                        <dd>{{ row.account }}</dd>
                    </div>
                </dl>
                <p v-else class="muted small">Ninguna: todas se heredan de la compañía.</p>
            </template>

            <form v-if="mode === 'create' || (selected && mode === 'edit')" id="warehouse-form" @submit.prevent="submit">
                <p v-if="mode === 'edit'" class="muted small">El código no se puede cambiar una vez creado el almacén.</p>

                <div class="field-row">
                    <div v-if="mode === 'create'" class="field">
                        <label for="wh-code">Código</label>
                        <input id="wh-code" v-model="form.code" type="text" maxlength="20" required>
                        <span v-if="form.errors.code" class="error">{{ form.errors.code }}</span>
                    </div>

                    <div class="field">
                        <label for="wh-name">Nombre</label>
                        <input id="wh-name" v-model="form.name" type="text" required>
                        <span v-if="form.errors.name" class="error">{{ form.errors.name }}</span>
                    </div>
                </div>

                <div class="field">
                    <label for="wh-address">Dirección (opcional)</label>
                    <input id="wh-address" v-model="form.address" type="text">
                    <span v-if="form.errors.address" class="error">{{ form.errors.address }}</span>
                </div>

                <div class="field">
                    <label for="wh-status">Estado</label>
                    <select id="wh-status" v-model="form.status">
                        <option value="active">Activo</option>
                        <option value="inactive">Inactivo</option>
                    </select>
                </div>

                <label class="check">
                    <input v-model="form.is_default" type="checkbox">
                    Almacén por defecto (desmarca al actual)
                </label>

                <label v-if="mode === 'create'" class="check">
                    <input v-model="form.uses_bins" type="checkbox">
                    Maneja ubicaciones (cada movimiento deberá indicar en cuál)
                </label>

                <h3 class="section-title">Cuentas contables</h3>
                <p class="hint small">Lo que se deje vacío se hereda de la <strong>compañía</strong>. El artículo y su grupo le ganan a lo que se ponga acá.</p>

                <div v-for="(label, key) in accountCategories" :key="key" class="field">
                    <label :for="`wh-account-${key}`">{{ label }}</label>
                    <select :id="`wh-account-${key}`" v-model="form.accounts[key]">
                        <option value="">Heredar de la compañía</option>
                        <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.label }}</option>
                    </select>
                </div>
            </form>

            <template #actions>
                <template v-if="selected && mode === 'details'">
                    <button type="button" class="btn btn-ghost btn-danger-text" @click="destroy">Eliminar</button>
                    <Link :href="route('warehouse-bins.index', selected.id)" class="btn btn-ghost"><MapPinIcon /> Ubicaciones</Link>
                    <button type="button" class="btn btn-primary" @click="startEdit"><PencilIcon /> Editar</button>
                </template>
                <template v-else-if="modalOpen">
                    <button type="button" class="btn btn-ghost" @click="cancelForm">Cancelar</button>
                    <button type="submit" form="warehouse-form" class="btn btn-primary" :disabled="form.processing">
                        {{ mode === 'create' ? 'Crear' : 'Guardar' }}
                    </button>
                </template>
            </template>
        </DetailModal>
    </AppLayout>
</template>

<style scoped>
table { font-size: 0.85rem; }
.code-cell { font-variant-numeric: tabular-nums; white-space: nowrap; }
.name-cell { display: inline-flex; align-items: center; flex-wrap: wrap; gap: 0.35rem; }
</style>
