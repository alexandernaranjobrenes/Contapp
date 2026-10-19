<script setup>
/**
 * Provincia, cantón y distrito con los códigos del nodo Ubicacion de Hacienda
 * (tabla oficial Codificacionubicacion_V4.4, CostaRicaTerritory en el
 * servidor). Cada lista se llena con lo que cabe en la anterior; al cambiar la
 * provincia o el cantón, lo que ya no le pertenece se limpia.
 *
 * `form` es el useForm de la pantalla: estos campos le escriben directo.
 * Va dentro de un .form-grid, igual que los campos sueltos que reemplaza.
 */
import { computed, watch } from 'vue';

const props = defineProps({
    form: { type: Object, required: true },
    territory: { type: Object, required: true },
    required: { type: Boolean, default: false },
});

const cantons = computed(() => props.territory[props.form.province]?.cantons ?? {});
const districts = computed(() => cantons.value[props.form.canton]?.districts ?? {});

// Un código guardado antes de que hubiera listas puede no estar en la tabla:
// se muestra tal cual, marcado, en vez de dejar el campo en blanco.
const unknownCanton = computed(() => props.form.canton && ! cantons.value[props.form.canton]);
const unknownDistrict = computed(() => props.form.district && ! districts.value[props.form.district]);

watch(() => props.form.province, () => {
    if (! cantons.value[props.form.canton]) {
        props.form.canton = '';
        props.form.district = '';
    }
});

watch(() => props.form.canton, () => {
    if (! districts.value[props.form.district]) {
        props.form.district = '';
    }
});
</script>

<template>
    <div class="field">
        <label for="province">Provincia</label>
        <select id="province" v-model="form.province" :required="required">
            <option value="" :disabled="required">{{ required ? '— Elegir —' : '—' }}</option>
            <option v-for="(node, code) in territory" :key="code" :value="code">{{ node.name }}</option>
        </select>
        <span v-if="form.errors.province" class="error">{{ form.errors.province }}</span>
    </div>

    <div class="field-row">
        <div class="field">
            <label for="canton">Cantón</label>
            <select id="canton" v-model="form.canton" :required="required" :disabled="! form.province">
                <option value="" :disabled="required">{{ form.province ? '— Elegir —' : 'Primero la provincia' }}</option>
                <option v-if="unknownCanton" :value="form.canton">{{ form.canton }} — no está en la tabla de Hacienda</option>
                <option v-for="(node, code) in cantons" :key="code" :value="code">{{ node.name }}</option>
            </select>
            <span v-if="form.errors.canton" class="error">{{ form.errors.canton }}</span>
        </div>
        <div class="field">
            <label for="district">Distrito</label>
            <select id="district" v-model="form.district" :required="required" :disabled="! form.canton">
                <option value="" :disabled="required">{{ form.canton ? '— Elegir —' : 'Primero el cantón' }}</option>
                <option v-if="unknownDistrict" :value="form.district">{{ form.district }} — no está en la tabla de Hacienda</option>
                <option v-for="(name, code) in districts" :key="code" :value="code">{{ name }}</option>
            </select>
            <span v-if="form.errors.district" class="error">{{ form.errors.district }}</span>
        </div>
    </div>
</template>
