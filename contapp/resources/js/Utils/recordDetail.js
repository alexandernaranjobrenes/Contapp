import { computed, ref } from 'vue';

/**
 * Ficha de un registro de una tabla (CLAUDE.md secc. 20): qué fila está
 * abierta en el DetailModal.
 *
 *     const { selected, openDetail, closeDetail } = useRecordDetail(() => props.warehouses);
 *
 *     <tr class="clickable-row" tabindex="0" @click="openDetail(w)"
 *         @keydown.enter="openDetail(w)" @keydown.space.prevent="openDetail(w)">
 *     <DetailModal :open="!!selected" :title="selected?.name ?? ''" @close="closeDetail">
 *
 * El registro se busca en la lista cada vez, no se copia: después de
 * guardar, la ficha muestra el dato nuevo que mandó el servidor, y si el
 * registro se eliminó, desaparece de la lista y la ficha se cierra sola.
 */
export function useRecordDetail(records, key = 'id') {
    const selectedKey = ref(null);

    const selected = computed(() => {
        if (selectedKey.value === null) return null;
        return (records() ?? []).find((record) => record[key] === selectedKey.value) ?? null;
    });

    function openDetail(record) {
        selectedKey.value = record[key];
    }

    function closeDetail() {
        selectedKey.value = null;
    }

    return { selected, openDetail, closeDetail };
}
