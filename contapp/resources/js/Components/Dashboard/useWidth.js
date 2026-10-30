import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * El ancho de un elemento, al día: los gráficos del Panel se dibujan a su
 * ancho real (no estirados), así el texto de los ejes no se deforma.
 */
export function useWidth(element) {
    const width = ref(0);
    let observer = null;

    onMounted(() => {
        if (!element.value) return;
        width.value = element.value.clientWidth;
        observer = new ResizeObserver(([entry]) => {
            width.value = Math.floor(entry.contentRect.width);
        });
        observer.observe(element.value);
    });

    onBeforeUnmount(() => observer?.disconnect());

    return width;
}
