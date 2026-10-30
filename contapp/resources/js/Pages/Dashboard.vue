<script setup>
import { computed, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import AppLayout from '../Layouts/AppLayout.vue';
import DashboardWidget from '../Components/Dashboard/DashboardWidget.vue';
import WidgetCatalog from '../Components/Dashboard/WidgetCatalog.vue';
import { CheckIcon, PencilIcon, PlusIcon } from '@lucide/vue';

/**
 * El Panel (CLAUDE.md secc. 44): los elementos que la compañía eligió, solo
 * los que esta persona puede ver (el servidor ya los filtró y calculó).
 *
 * El Superusuario y los Administradores lo personalizan: agregar del
 * catálogo, mover y quitar, y guardar. Lo que guardan es lo que ven, en su
 * orden; los elementos que no ven quedan donde estaban (DashboardService).
 * El máximo cuenta también esos.
 */
const props = defineProps({
    noCompany: { type: Boolean, default: false },
    widgets: { type: Array, default: () => [] },
    canEdit: { type: Boolean, default: false },
    // { catalog, hidden, max }: solo para quien puede editar.
    editor: { type: Object, default: null },
});

const page = usePage();

const editing = ref(false);
const draft = ref([]);
const catalogOpen = ref(false);
const saving = ref(false);

const loaded = computed(() => Object.fromEntries(props.widgets.map((w) => [w.key, w])));
const catalogByKey = computed(() => Object.fromEntries((props.editor?.catalog ?? []).map((w) => [w.key, w])));

// Lo que se dibuja: el Panel guardado, o el borrador mientras se edita. Uno
// recién agregado no tiene datos todavía (los trae al guardar).
const shown = computed(() => (editing.value
    ? draft.value.map((key) => loaded.value[key] ?? catalogByKey.value[key]).filter(Boolean)
    : props.widgets));

const used = computed(() => draft.value.length + (props.editor?.hidden ?? 0));
const full = computed(() => !!props.editor && used.value >= props.editor.max);
const changed = computed(() => draft.value.join() !== props.widgets.map((w) => w.key).join());

function startEditing() {
    draft.value = props.widgets.map((w) => w.key);
    editing.value = true;
}

function cancel() {
    editing.value = false;
    catalogOpen.value = false;
}

function move(index, step) {
    const to = index + step;
    if (to < 0 || to >= draft.value.length) return;
    const list = [...draft.value];
    [list[index], list[to]] = [list[to], list[index]];
    draft.value = list;
}

function remove(index) {
    draft.value = draft.value.filter((_, i) => i !== index);
}

function add(key) {
    if (full.value || draft.value.includes(key)) return;
    draft.value = [...draft.value, key];
}

function save() {
    router.put(route('dashboard.layout.update'), { widgets: draft.value }, {
        preserveScroll: true,
        onStart: () => { saving.value = true; },
        onSuccess: () => { editing.value = false; },
        onFinish: () => { saving.value = false; },
    });
}
</script>

<template>
    <Head title="Panel" />

    <AppLayout title="Panel">
        <div v-if="noCompany" class="card empty-state">
            <p>Tu usuario no tiene ninguna compañía asignada todavía.</p>
            <p class="muted">Pedile a un administrador que te agregue a una compañía para empezar a trabajar.</p>
        </div>

        <template v-else>
            <div v-if="canEdit" class="view-toolbar">
                <div class="view-actions">
                    <template v-if="!editing">
                        <button type="button" class="btn btn-ghost" @click="startEditing"><PencilIcon /> Personalizar el Panel</button>
                    </template>
                    <template v-else>
                        <span class="counter" :class="{ 'is-full': full }">
                            {{ used }} de {{ editor.max }} elementos<template v-if="editor.hidden">, {{ editor.hidden }} que no ves</template>
                        </span>
                        <button type="button" class="btn btn-ghost" :disabled="saving" @click="cancel">Cancelar</button>
                        <button type="button" class="btn btn-ghost" :disabled="full" @click="catalogOpen = true"><PlusIcon /> Agregar elemento</button>
                        <button type="button" class="btn btn-primary" :disabled="saving || !changed" @click="save"><CheckIcon /> Guardar el Panel</button>
                    </template>
                </div>
            </div>

            <p v-if="editing" class="hint">
                Lo que elijas lo ve toda la compañía, pero cada persona solo ve los elementos de las pantallas a las que
                tiene acceso.<template v-if="editor.hidden"> Los {{ editor.hidden }} elementos de pantallas a las que vos no
                tenés acceso siguen en el Panel: no los podés mover ni quitar.</template>
            </p>

            <p v-if="page.props.errors?.widgets" class="flash flash-error" role="alert">{{ page.props.errors.widgets }}</p>

            <div class="dashboard">
                <div v-if="shown.length" class="dashboard-grid">
                    <DashboardWidget
                        v-for="(widget, i) in shown"
                        :key="widget.key"
                        :widget="widget"
                        :editing="editing"
                        :first="i === 0"
                        :last="i === shown.length - 1"
                        @move="move(i, $event)"
                        @remove="remove(i)"
                    />
                </div>

                <div v-else class="card empty-state">
                    <template v-if="editing">
                        <p>El Panel está vacío.</p>
                        <p class="muted">Agregá gráficos y resúmenes con «Agregar elemento».</p>
                    </template>
                    <template v-else-if="canEdit">
                        <p>El Panel está vacío.</p>
                        <p class="muted">Con «Personalizar el Panel» elegís los gráficos y resúmenes que ve la compañía.</p>
                    </template>
                    <template v-else>
                        <p>Todavía no hay nada en el Panel para vos.</p>
                        <p class="muted">Cada elemento se ve solo con acceso a su pantalla. Usá el menú para ir a tus pantallas.</p>
                    </template>
                </div>
            </div>

            <WidgetCatalog
                v-if="editor"
                :open="catalogOpen"
                :catalog="editor.catalog"
                :selected="draft"
                :full="full"
                :max="editor.max"
                @add="add"
                @close="catalogOpen = false"
            />
        </template>
    </AppLayout>
</template>

<style scoped>
.counter {
    align-self: center;
    margin-right: auto;
    font-size: 0.82rem;
    color: var(--color-text-muted);
}

.counter.is-full { color: var(--color-warning); font-weight: 600; }

/*
 * La rejilla responde a su propio ancho (no al de la ventana): con el menú al
 * lado o arriba, el lugar para el Panel cambia. Cuatro columnas cuando hay
 * espacio, dos en una tablet, una en un teléfono. Un elemento mediano ocupa
 * dos; uno ancho, la fila entera.
 */
.dashboard { container-type: inline-size; }

.dashboard-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 1rem;
    align-items: stretch;
}

@container (min-width: 38rem) {
    .dashboard-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .dashboard-grid > :deep(.w-medium),
    .dashboard-grid > :deep(.w-large) { grid-column: 1 / -1; }
}

@container (min-width: 56rem) {
    .dashboard-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }
    .dashboard-grid > :deep(.w-medium) { grid-column: span 2; }
    .dashboard-grid > :deep(.w-large) { grid-column: 1 / -1; }
}

.empty-state { padding: 1.25rem; }
.empty-state p { margin: 0 0 0.35rem; }
</style>
