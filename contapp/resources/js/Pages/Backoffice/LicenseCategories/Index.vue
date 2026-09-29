<script setup>
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import BackofficeLayout from '../../../Layouts/BackofficeLayout.vue';
import ConfirmModal from '../../../Components/ConfirmModal.vue';
import DetailModal from '../../../Components/DetailModal.vue';
import { PlusIcon } from '@lucide/vue';

const props = defineProps({
    categories: { type: Array, default: () => [] },
});

const page = usePage();

// Ficha de la categoría (CLAUDE.md secc. 20) y alta (secc. 21) en un solo
// modal: la fila lo abre en modo 'details', y «Crear nuevo» —arriba de la
// tabla— en modo 'create'. Crear y editar comparten el formulario.
const selectedId = ref(null);
const mode = ref('details'); // 'create' | 'details' | 'edit'

// Se busca en props cada vez: al guardar, la ficha muestra el dato nuevo; al
// eliminar, la categoría desaparece de props y el modal se cierra solo.
const selected = computed(() => props.categories.find((c) => c.id === selectedId.value) ?? null);

const modalOpen = computed(() => mode.value === 'create' || !!selected.value);
const modalTitle = computed(() => (mode.value === 'create' ? 'Nueva categoría' : selected.value?.name ?? ''));

const DEFAULTS = {
    name: '', max_companies: 1, max_admins: 3, max_users: 10, duration_months: 12, description: '', is_active: true,
};

const form = useForm({ ...DEFAULTS });

// Cada campo se asigna al abrir: el mismo formulario sirve para crear y para
// editar, y así no le queda el rastro de lo que se escribió la vez anterior.
function fillForm(values) {
    form.clearErrors();
    for (const [key, value] of Object.entries(values)) form[key] = value;
}

function openCreate() {
    fillForm(DEFAULTS);
    selectedId.value = null;
    mode.value = 'create';
}

function openCategory(category) {
    selectedId.value = category.id;
    mode.value = 'details';
}

function closeCategory() {
    // Con la confirmación de eliminar abierta encima, Escape cierra primero
    // esa confirmación, no la ficha que está debajo.
    if (confirmingDelete.value) {
        confirmingDelete.value = false;
        return;
    }

    selectedId.value = null;
    mode.value = 'details';
}

function startEdit() {
    const category = selected.value;
    fillForm({
        name: category.name,
        max_companies: category.max_companies,
        max_admins: category.max_admins,
        max_users: category.max_users,
        duration_months: category.duration_months,
        description: category.description ?? '',
        is_active: category.is_active,
    });
    mode.value = 'edit';
}

function cancelForm() {
    if (mode.value === 'create') {
        closeCategory();
        return;
    }

    mode.value = 'details';
}

function submit() {
    if (mode.value === 'create') {
        form.post(route('backoffice.license-categories.store'), { preserveScroll: true, onSuccess: closeCategory });
        return;
    }

    form.put(route('backoffice.license-categories.update', selected.value.id), {
        preserveScroll: true,
        onSuccess: () => { mode.value = 'details'; },
    });
}

// Eliminar no tiene vuelta atrás: se confirma antes (CLAUDE.md secc. 20).
const confirmingDelete = ref(false);
const deleting = ref(false);

function destroy() {
    deleting.value = true;
    router.delete(route('backoffice.license-categories.destroy', selected.value.id), {
        preserveScroll: true,
        onFinish: () => {
            deleting.value = false;
            confirmingDelete.value = false;
        },
    });
}
</script>

<template>
    <Head title="Categorías de licencia" />

    <BackofficeLayout title="Categorías de licencia">
        <div class="view-toolbar">
            <div class="view-actions">
                <button type="button" class="btn btn-primary" @click="openCreate"><PlusIcon /> Crear nuevo</button>
            </div>
        </div>

        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Empresas máx.</th>
                            <th>Duración</th>
                            <th>Licencias emitidas</th>
                            <th>Activa</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="category in categories"
                            :key="category.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="openCategory(category)"
                            @keydown.enter="openCategory(category)"
                            @keydown.space.prevent="openCategory(category)"
                        >
                            <td>{{ category.name }}</td>
                            <td data-label="Empresas máx." class="num">{{ category.max_companies }}</td>
                            <td data-label="Duración" class="num">{{ category.duration_months }} meses</td>
                            <td data-label="Licencias emitidas" class="num">{{ category.licenses_count }}</td>
                            <td data-label="Activa"><span class="badge" :class="category.is_active ? 'badge-success' : 'badge-danger'">{{ category.is_active ? 'Sí' : 'No' }}</span></td>
                        </tr>
                        <tr v-if="!categories.length">
                            <td colspan="5" class="muted empty-row">Todavía no hay categorías de licencia.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <DetailModal :open="modalOpen" :title="modalTitle" @close="closeCategory">
            <template #badge>
                <span v-if="selected && mode !== 'create'" class="badge" :class="selected.is_active ? 'badge-success' : 'badge-danger'">
                    {{ selected.is_active ? 'Activa' : 'Inactiva' }}
                </span>
            </template>

            <template v-if="selected && mode === 'details'">
                <dl class="detail-list">
                    <div>
                        <dt>Empresas máximas</dt>
                        <dd>{{ selected.max_companies }}</dd>
                    </div>
                    <div>
                        <dt>Duración</dt>
                        <dd>{{ selected.duration_months }} meses</dd>
                    </div>
                    <div>
                        <dt>Administradores máximos</dt>
                        <dd>{{ selected.max_admins }}</dd>
                    </div>
                    <div>
                        <dt>Usuarios máximos</dt>
                        <dd>{{ selected.max_users }}</dd>
                    </div>
                    <div>
                        <dt>Licencias emitidas</dt>
                        <dd>{{ selected.licenses_count }}</dd>
                    </div>
                    <div class="full">
                        <dt>Descripción</dt>
                        <dd>{{ selected.description || '—' }}</dd>
                    </div>
                </dl>
                <p v-if="selected.licenses_count" class="hint">
                    Ya tiene licencias emitidas: no se puede eliminar. Si ya no se ofrece, desactivala al editarla.
                </p>
                <p v-if="page.props.errors?.category" class="flash flash-error">{{ page.props.errors.category }}</p>
            </template>

            <form v-if="mode === 'create' || (selected && mode === 'edit')" id="category-form" @submit.prevent="submit">
                <h3 v-if="mode === 'edit'" class="section-title">Editar categoría</h3>
                <div class="field">
                    <label for="category-name">Nombre</label>
                    <input id="category-name" v-model="form.name" type="text" required placeholder="Básica, Profesional…">
                    <span v-if="form.errors.name" class="error">{{ form.errors.name }}</span>
                </div>
                <div class="field-row quota-row">
                    <div class="field">
                        <label for="category-companies">Empresas máx.</label>
                        <input id="category-companies" v-model="form.max_companies" type="number" min="1" required>
                        <span v-if="form.errors.max_companies" class="error">{{ form.errors.max_companies }}</span>
                    </div>
                    <div class="field">
                        <label for="category-admins">Admins máx.</label>
                        <input id="category-admins" v-model="form.max_admins" type="number" min="0" required>
                        <span v-if="form.errors.max_admins" class="error">{{ form.errors.max_admins }}</span>
                    </div>
                    <div class="field">
                        <label for="category-users">Usuarios máx.</label>
                        <input id="category-users" v-model="form.max_users" type="number" min="0" required>
                        <span v-if="form.errors.max_users" class="error">{{ form.errors.max_users }}</span>
                    </div>
                </div>
                <div class="field">
                    <label for="category-duration">Duración (meses)</label>
                    <input id="category-duration" v-model="form.duration_months" type="number" min="1" required>
                    <span v-if="form.errors.duration_months" class="error">{{ form.errors.duration_months }}</span>
                </div>
                <div class="field">
                    <label for="category-description">Descripción</label>
                    <input id="category-description" v-model="form.description" type="text">
                    <span v-if="form.errors.description" class="error">{{ form.errors.description }}</span>
                </div>
                <label class="check"><input v-model="form.is_active" type="checkbox"> Activa (se ofrece al emitir licencias)</label>
            </form>

            <template #actions>
                <template v-if="selected && mode === 'details'">
                    <button v-if="!selected.licenses_count" type="button" class="btn btn-ghost btn-danger-text" @click="confirmingDelete = true">Eliminar</button>
                    <button type="button" class="btn btn-primary" @click="startEdit">Editar</button>
                </template>
                <template v-else-if="mode === 'create' || mode === 'edit'">
                    <button type="button" class="btn btn-ghost" @click="cancelForm">Cancelar</button>
                    <button type="submit" form="category-form" class="btn btn-primary" :disabled="form.processing">
                        {{ mode === 'create' ? 'Crear' : 'Guardar' }}
                    </button>
                </template>
            </template>
        </DetailModal>

        <ConfirmModal
            :open="confirmingDelete && !!selected"
            title="Eliminar categoría"
            :message="selected ? `La categoría ${selected.name} se elimina de forma definitiva.` : ''"
            confirm-label="Eliminar"
            danger
            :processing="deleting"
            @confirm="destroy"
            @cancel="confirmingDelete = false"
        />
    </BackofficeLayout>
</template>

<style scoped>
table { font-size: 0.85rem; }
th, td { text-align: left; padding: 0.55rem 1rem; border-top: 1px solid var(--color-border); }
.muted { color: var(--color-text-muted); }
.empty-row { text-align: center; padding: 1.5rem; }

.section-title { margin-top: 0; }
.hint { margin: 0.9rem 0 0; }
.flash { margin: 0.9rem 0 0; }
/* Los tres cupos son números cortos: caben de a tres por línea incluso en
   un teléfono. */
.quota-row .field { min-width: 5.5rem; }
</style>
