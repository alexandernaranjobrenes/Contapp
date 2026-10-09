<script setup>
import { Head, useForm, Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import ScreenPermissionsEditor from '../../Components/ScreenPermissionsEditor.vue';
import PermissionProfilePicker from '../../Components/PermissionProfilePicker.vue';
import ContiAccessFields from '../../Components/Conti/ContiAccessFields.vue';
import { ArrowLeftIcon, CheckIcon } from '@lucide/vue';

/**
 * Los permisos de una persona por pantalla del menú (ScreenCatalog). Si
 * todavía los tenía por módulo (el esquema anterior), se muestran ya
 * repartidos en pantallas, y al guardar quedan por pantalla.
 *
 * El Superusuario además ve y cambia lo de Conti (ContiAccessFields.vue): si
 * puede usarlo, sus límites y sus modelos.
 *
 * Un perfil de su rol (Contador, Vendedor…) precarga los permisos; no se envía.
 */
const props = defineProps({
    targetUser: { type: Object, required: true },
    sections: { type: Array, required: true },
    profiles: { type: Array, default: () => [] },
    conti: { type: Object, default: null },
});

const form = useForm({
    permissions: Object.fromEntries(props.sections.flatMap((s) => s.screens.map((sc) => [sc.key, sc.current_level]))),
    ...(props.conti ? { conti: { ...props.conti.current, models: [...props.conti.current.models] } } : {}),
});

function submit() {
    form.put(route('users.permissions.update', props.targetUser.id), { preserveScroll: true });
}
</script>

<template>
    <Head title="Editar permisos" />

    <AppLayout title="Editar permisos">
        <div class="view-toolbar">
            <Link :href="route('users.index')" class="btn btn-ghost"><ArrowLeftIcon /> Usuarios</Link>
        </div>

        <div class="card form-card">
            <p class="user-line">Permisos de <strong>{{ targetUser.name }}</strong> ({{ targetUser.email }})</p>

            <form @submit.prevent="submit">
                <PermissionProfilePicker v-model="form.permissions" :profiles="profiles" :sections="sections" />

                <h3>Permisos</h3>
                <p class="hint">
                    Por cada opción del menú. «Lectura» deja consultar y exportar; «Lectura y escritura», además crear,
                    modificar y eliminar. Solo podés dar hasta el acceso que tenés vos.
                </p>
                <p v-if="form.errors.permissions" class="error">{{ form.errors.permissions }}</p>

                <ScreenPermissionsEditor v-model="form.permissions" :sections="sections" />

                <ContiAccessFields v-if="conti" v-model="form.conti" :options="conti" :errors="form.errors" />

                <div class="form-actions">
                    <Link :href="route('users.index')" class="btn btn-ghost">Cancelar</Link>
                    <button type="submit" class="btn btn-primary" :disabled="form.processing"><CheckIcon /> Guardar</button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>

<style scoped>
.user-line { font-size: 0.88rem; margin: 0 0 0.6rem; }
h3 { font-size: 0.88rem; margin: 1.25rem 0 0.25rem; }
.form-card { padding: 1.25rem 1.5rem; }
.hint { font-size: 0.78rem; margin: 0 0 0.6rem; }
.error { color: var(--color-danger); font-size: 0.78rem; margin: 0 0 0.6rem; }
.form-actions { margin-top: 1rem; }

@media (max-width: 640px) {
    .form-card { padding: 1rem; }
}
</style>
