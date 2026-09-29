import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

/**
 * Ficha, alta y edición de un catálogo en un solo DetailModal
 * (CLAUDE.md secc. 20 y 21): la fila lo abre en 'details', «Crear nuevo» en
 * 'create', y «Editar» pasa la misma ficha a 'edit'. Crear y editar
 * comparten un formulario; al abrirlo se asigna cada campo, para que no le
 * quede el rastro de lo que se escribió la vez anterior.
 *
 *     const crud = useCrudModal({
 *         records: () => props.warehouses,
 *         defaults: () => ({ code: '', name: '', status: 'active' }),
 *         toForm: (w) => ({ code: w.code, name: w.name, status: w.status }),
 *         store: () => route('warehouses.store'),
 *         update: (w) => route('warehouses.update', w.id),
 *         // Opcionales: lo que se manda al crear o al editar, si no es el
 *         // formulario tal cual.
 *         storePayload: (data) => data,
 *         updatePayload: ({ name, status }) => ({ name, status }),
 *     });
 *     const { mode, selected, modalOpen, form, openCreate, openDetail, close, startEdit, cancelForm, submit } = crud;
 *
 * El registro de la ficha se busca en la lista cada vez: después de guardar
 * muestra el dato nuevo, y si se eliminó, la ficha se cierra sola.
 */
export function useCrudModal({ records, defaults, toForm, store, update, storePayload = null, updatePayload = null, key = 'id' }) {
    const mode = ref('details'); // 'create' | 'details' | 'edit'
    const selectedKey = ref(null);

    const selected = computed(() => {
        if (selectedKey.value === null) return null;
        return (records() ?? []).find((record) => record[key] === selectedKey.value) ?? null;
    });

    const modalOpen = computed(() => mode.value === 'create' || !!selected.value);

    const form = useForm({ ...defaults() });

    function fill(values) {
        form.clearErrors();
        for (const [field, value] of Object.entries(values)) form[field] = value;
    }

    function openCreate(overrides = {}) {
        fill({ ...defaults(), ...overrides });
        selectedKey.value = null;
        mode.value = 'create';
    }

    function openDetail(record) {
        selectedKey.value = record[key];
        mode.value = 'details';
    }

    function close() {
        selectedKey.value = null;
        mode.value = 'details';
    }

    function startEdit() {
        fill(toForm(selected.value));
        mode.value = 'edit';
    }

    function cancelForm() {
        if (mode.value === 'create') {
            close();
            return;
        }

        mode.value = 'details';
    }

    // Con @submit.prevent="submit" llega el evento del formulario: no son
    // opciones de la visita.
    function submit(options = {}) {
        if (options instanceof Event) options = {};

        if (mode.value === 'create') {
            form.transform(storePayload ?? ((data) => data)).post(store(), { preserveScroll: true, onSuccess: close, ...options });
            return;
        }

        form.transform(updatePayload ?? ((data) => data)).put(update(selected.value), {
            preserveScroll: true,
            onSuccess: () => { mode.value = 'details'; },
            ...options,
        });
    }

    return { mode, selected, modalOpen, form, openCreate, openDetail, close, startEdit, cancelForm, submit };
}
