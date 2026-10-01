<script setup>
import { Head, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { PencilIcon, PlusIcon } from '@lucide/vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DetailModal from '../../../Components/DetailModal.vue';
import { confirmAction } from '../../../Utils/confirm';
import { useCrudModal } from '../../../Utils/crudModal';

const props = defineProps({
    accounts: { type: Array, default: () => [] },
    accountCategories: { type: Object, default: () => ({}) },
    groupAccounts: { type: Object, default: () => ({}) },
    itemGroups: { type: Array, default: () => [] },
});

const page = usePage();
const search = ref('');

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    const sorted = [...props.itemGroups].sort((a, b) => a.code.localeCompare(b.code));
    if (! q) return sorted;

    return sorted.filter((g) => g.code.toLowerCase().includes(q) || g.name.toLowerCase().includes(q));
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

// Ficha, alta y edición en un solo modal (CLAUDE.md secc. 20 y 21), con las
// cuentas contables propias del grupo. El código solo se elige al crear.
const { mode, selected, modalOpen, form, openCreate, openDetail, close, startEdit, cancelForm, submit } = useCrudModal({
    records: () => props.itemGroups,
    defaults: () => ({ code: '', name: '', status: 'active', accounts: {} }),
    toForm: (g) => ({ code: g.code, name: g.name, status: g.status, accounts: { ...(props.groupAccounts[g.id] ?? {}) } }),
    store: () => route('item-groups.store'),
    update: (g) => route('item-groups.update', g.id),
    storePayload: withAccounts,
    updatePayload: ({ name, status, accounts }) => withAccounts({ name, status, accounts }),
});

const modalTitle = computed(() => {
    if (mode.value === 'create') return 'Nuevo grupo de artículos';
    return selected.value ? `${selected.value.code} — ${selected.value.name}` : '';
});

const selectedAccounts = computed(() => {
    if (! selected.value) return [];
    const own = props.groupAccounts[selected.value.id] ?? {};

    return Object.entries(props.accountCategories)
        .filter(([key]) => own[key])
        .map(([key, label]) => ({ key, label, account: accountLabels.value[own[key]] ?? own[key] }));
});

function destroy() {
    const g = selected.value;

    confirmAction({
        title: 'Eliminar grupo',
        message: `El grupo ${g.code} — ${g.name} se elimina de forma definitiva.`,
        confirmLabel: 'Eliminar',
        danger: true,
        onConfirm: () => router.delete(route('item-groups.destroy', g.id), { preserveScroll: true }),
    });
}
</script>

<template>
    <Head title="Grupos de artículos" />

    <AppLayout title="Grupos de artículos">
        <div class="view-toolbar">
            <div class="view-actions">
                <button type="button" class="btn btn-primary" @click="openCreate()"><PlusIcon /> Crear nuevo</button>
            </div>
            <div class="view-filters">
                <input v-model="search" type="search" placeholder="Buscar código o nombre..." aria-label="Buscar grupo">
            </div>
        </div>

        <div v-if="page.props.errors?.item_group" class="flash flash-error">{{ page.props.errors.item_group }}</div>

        <p class="hint">
            Además de clasificar el catálogo, el grupo es uno de los niveles de la <strong>determinación de cuentas</strong>:
            permite que todos los artículos de un grupo compartan las mismas cuentas de inventario, costo de ventas y ajuste
            sin configurarlas uno por uno.
        </p>

        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Nombre</th>
                            <th class="num">Artículos</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="g in filtered"
                            :key="g.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="openDetail(g)"
                            @keydown.enter="openDetail(g)"
                            @keydown.space.prevent="openDetail(g)"
                        >
                            <td class="code-cell">{{ g.code }}</td>
                            <td data-label="Nombre">{{ g.name }}</td>
                            <td data-label="Artículos" class="num">{{ g.items_count }}</td>
                            <td data-label="Estado">
                                <span class="badge" :class="g.status === 'active' ? 'badge-success' : 'badge-neutral'">
                                    {{ g.status === 'active' ? 'Activo' : 'Inactivo' }}
                                </span>
                            </td>
                        </tr>
                        <tr v-if="!filtered.length">
                            <td colspan="4" class="muted empty-row">
                                {{ itemGroups.length ? 'Ningún grupo coincide con la búsqueda.' : 'Todavía no hay grupos de artículos registrados.' }}
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
                    <div>
                        <dt>Artículos</dt>
                        <dd>{{ selected.items_count }}</dd>
                    </div>
                </dl>

                <h3 class="section-title">Cuentas contables propias</h3>
                <dl v-if="selectedAccounts.length" class="detail-list">
                    <div v-for="row in selectedAccounts" :key="row.key" class="full">
                        <dt>{{ row.label }}</dt>
                        <dd>{{ row.account }}</dd>
                    </div>
                </dl>
                <p v-else class="muted small">Ninguna: todas se heredan del almacén o de la compañía.</p>
            </template>

            <form v-if="mode === 'create' || (selected && mode === 'edit')" id="item-group-form" @submit.prevent="submit">
                <p v-if="mode === 'edit'" class="muted small">El código no se puede cambiar una vez creado el grupo.</p>

                <div class="field-row">
                    <div v-if="mode === 'create'" class="field">
                        <label for="group-code">Código</label>
                        <input id="group-code" v-model="form.code" type="text" maxlength="20" required>
                        <span v-if="form.errors.code" class="error">{{ form.errors.code }}</span>
                    </div>

                    <div class="field">
                        <label for="group-name">Nombre</label>
                        <input id="group-name" v-model="form.name" type="text" required>
                        <span v-if="form.errors.name" class="error">{{ form.errors.name }}</span>
                    </div>
                </div>

                <div class="field">
                    <label for="group-status">Estado</label>
                    <select id="group-status" v-model="form.status">
                        <option value="active">Activo</option>
                        <option value="inactive">Inactivo</option>
                    </select>
                </div>

                <h3 class="section-title">Cuentas contables</h3>
                <p class="hint small">Lo que se deje vacío se hereda del <strong>almacén</strong> y después de la <strong>compañía</strong>. Un artículo que tenga su propia cuenta le gana a la del grupo.</p>

                <div v-for="(label, key) in accountCategories" :key="key" class="field">
                    <label :for="`group-account-${key}`">{{ label }}</label>
                    <select :id="`group-account-${key}`" v-model="form.accounts[key]">
                        <option value="">Heredar del almacén o la compañía</option>
                        <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.label }}</option>
                    </select>
                </div>
            </form>

            <template #actions>
                <template v-if="selected && mode === 'details'">
                    <button type="button" class="btn btn-ghost btn-danger-text" @click="destroy">Eliminar</button>
                    <button type="button" class="btn btn-primary" @click="startEdit"><PencilIcon /> Editar</button>
                </template>
                <template v-else-if="modalOpen">
                    <button type="button" class="btn btn-ghost" @click="cancelForm">Cancelar</button>
                    <button type="submit" form="item-group-form" class="btn btn-primary" :disabled="form.processing">
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
</style>
