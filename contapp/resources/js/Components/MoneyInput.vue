<script setup>
import { ref, watch } from 'vue';
import { formatMoney } from '../Utils/money';

// Campo de monto con separador de miles (135.000,00): mientras está
// enfocado muestra el número tal cual se digita (sin interferir con el
// cursor), y lo reformatea recién al perder el foco — estándar de campos
// monetarios en SAP/Odoo/QuickBooks, evita el salto de cursor de un
// formateo en vivo. El valor que viaja por v-model siempre es un string
// numérico plano ("135000.00", sin separadores), igual que antes con
// type="number" — el backend no necesita ningún cambio.
const props = defineProps({
    modelValue: { type: [String, Number], default: '' },
});

const emit = defineEmits(['update:modelValue']);

function formatDisplay(value) {
    if (value === null || value === undefined || value === '') return '';

    const n = parseFloat(value);

    return Number.isNaN(n) ? String(value) : formatMoney(n);
}

const focused = ref(false);
const display = ref(formatDisplay(props.modelValue));

watch(() => props.modelValue, (value) => {
    if (! focused.value) display.value = formatDisplay(value);
});

// Deja escribir con coma o punto decimal indistintamente — si hay coma, se
// asume formato local (punto = miles, coma = decimal) y se normaliza a
// punto decimal plano para el modelo.
function parseTyped(raw) {
    let cleaned = raw.replace(/[^0-9,.-]/g, '');

    if (cleaned.includes(',')) {
        cleaned = cleaned.replace(/\./g, '').replace(',', '.');
    }

    return cleaned;
}

function onFocus(event) {
    focused.value = true;
    display.value = props.modelValue === null || props.modelValue === undefined ? '' : String(props.modelValue);
    event.target.select();
}

function onInput(event) {
    display.value = event.target.value;
    emit('update:modelValue', parseTyped(event.target.value));
}

function onBlur() {
    focused.value = false;

    const n = parseFloat(display.value);
    const normalized = Number.isNaN(n) ? '' : n.toFixed(2);

    emit('update:modelValue', normalized);
    display.value = formatDisplay(normalized);
}
</script>

<template>
    <input
        :value="display"
        type="text"
        inputmode="decimal"
        autocomplete="off"
        @focus="onFocus"
        @input="onInput"
        @blur="onBlur"
    >
</template>
