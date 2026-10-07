<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { CircleCheckIcon, PencilLineIcon } from '@lucide/vue';
import DetailModal from '../DetailModal.vue';
import ContiActionDetails from './ContiActionDetails.vue';
import { closeContiAction, conti, noteInConversation, pushForm } from '../../Utils/contiChat';
import { requestJson } from '../../Utils/http';

/**
 * Lo que Conti preparó para guardar, en un modal encima de la pantalla en que
 * se está: el enlace del chat lo abre (ContiPanel.vue) y la persona decide
 * sin irse de donde estaba (CLAUDE.md secc. 32). Mismas reglas que la
 * pantalla Conti/Action.vue: ContiActionController atiende las dos.
 *
 * Al confirmar, la pantalla de atrás se recarga para que se vea lo que se
 * guardó, y la conversación anota cómo quedó.
 *
 * Va montado al lado de la página (app.js), como el chat.
 */
const action = ref(null);
const loading = ref(false);
const loadError = ref('');
const busy = ref(null); // 'confirm' | 'discard' | null
const decideError = ref('');

const pending = computed(() => action.value?.status === 'pending');
const title = computed(() => action.value?.summary?.titulo ?? 'Lo que preparó Conti');

const BADGES = {
    pending: 'badge-warning',
    confirmed: 'badge-success',
    discarded: 'badge-neutral',
    failed: 'badge-danger',
    expired: 'badge-neutral',
};

const NOT_FOUND = 'No encontramos lo que preparó Conti. Puede que el enlace no sea tuyo o que ya no exista.';

async function load() {
    const uuid = conti.review;
    loading.value = true;
    loadError.value = '';

    const result = await requestJson(window.route('conti.actions.show', uuid));

    // Se cerró, o se abrió otro, mientras cargaba.
    if (conti.review !== uuid) return;

    loading.value = false;
    if (result.ok) {
        action.value = result.data.action;
    } else {
        loadError.value = result.status === 404 ? NOT_FOUND : result.message;
    }
}

watch(() => conti.review, (uuid) => {
    action.value = null;
    busy.value = null;
    decideError.value = '';
    if (uuid) load();
});

/**
 * «Corregir»: lo preparado se descarta y vuelve al chat como formulario, con
 * los mismos datos, para cambiar lo que haga falta y enviarlo de nuevo.
 */
async function revise() {
    if (busy.value || !action.value) return;

    const uuid = action.value.uuid;
    busy.value = 'revise';
    decideError.value = '';

    const result = await requestJson(window.route('conti.actions.revise', uuid), { method: 'POST' });

    if (conti.review !== uuid) return;
    busy.value = null;

    if (!result.ok) {
        decideError.value = result.status === 404 ? NOT_FOUND : ([403, 409].includes(result.status) && result.data?.message) || result.message;
        return;
    }

    closeContiAction();
    pushForm(result.data.formulario);
}

async function decide(kind) {
    if (busy.value || !action.value) return;

    const uuid = action.value.uuid;
    busy.value = kind;
    decideError.value = '';

    const name = kind === 'confirm' ? 'conti.actions.confirm' : 'conti.actions.discard';
    const result = await requestJson(window.route(name, uuid), { method: 'POST' });

    if (conti.review !== uuid) return;
    busy.value = null;

    if (!result.ok) {
        // 409: es de otra compañía; 403: la licencia venció. Los dos dicen por qué.
        decideError.value = result.status === 404 ? NOT_FOUND : (result.status === 409 && result.data?.message) || result.message;
        return;
    }

    action.value = result.data.action;
    const decided = action.value;

    if (decided.status === 'confirmed') {
        noteInConversation(`Confirmaste: ${decided.result?.mensaje ?? decided.summary?.titulo ?? 'guardado'}`);
        // La pantalla de atrás, al día: si muestra lo que se guardó, que se vea.
        router.reload();
    } else if (decided.status === 'discarded') {
        noteInConversation(`Descartaste: ${decided.summary?.titulo ?? 'no se guardó nada'}`);
    } else if (decided.status === 'failed') {
        noteInConversation(`No se guardó: ${decided.error}`);
    }
}
</script>

<template>
    <DetailModal :open="conti.review !== null" :title="title" wide @close="closeContiAction">
        <template v-if="action" #badge>
            <span class="badge" :class="BADGES[action.status]">{{ action.status_label }}</span>
        </template>

        <p v-if="loading" class="muted">Cargando lo que preparó Conti…</p>

        <div v-else-if="loadError" class="flash flash-error conti-modal-error" role="alert">
            <span>{{ loadError }}</span>
            <button type="button" class="btn btn-ghost btn-sm" @click="load">Reintentar</button>
        </div>

        <div v-else-if="action" class="conti-modal-body">
            <p class="muted small conti-modal-label">Conti preparó esto · {{ action.label }}</p>
            <ContiActionDetails :action="action" @leave="closeContiAction" />
            <div v-if="decideError" class="flash flash-error" role="alert">{{ decideError }}</div>
        </div>

        <template #actions>
            <template v-if="pending">
                <button
                    type="button"
                    class="btn btn-ghost"
                    :disabled="action.other_company || busy !== null"
                    :data-busy="busy === 'discard' ? '' : null"
                    :aria-busy="busy === 'discard'"
                    @click="decide('discard')"
                >Descartar</button>
                <button
                    type="button"
                    class="btn btn-ghost"
                    :disabled="action.other_company || busy !== null"
                    :data-busy="busy === 'revise' ? '' : null"
                    :aria-busy="busy === 'revise'"
                    @click="revise"
                ><PencilLineIcon /> Corregir</button>
                <button
                    type="button"
                    class="btn btn-primary"
                    :disabled="action.other_company || busy !== null"
                    :data-busy="busy === 'confirm' ? '' : null"
                    :aria-busy="busy === 'confirm'"
                    @click="decide('confirm')"
                ><CircleCheckIcon /> Confirmar y guardar</button>
            </template>
            <button v-else type="button" class="btn btn-ghost" @click="closeContiAction">Cerrar</button>
        </template>
    </DetailModal>
</template>

<style scoped>
.conti-modal-body {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.conti-modal-label {
    margin: -0.5rem 0 0;
}

.conti-modal-body .flash {
    margin: 0;
}

.conti-modal-error {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    margin: 0;
}
</style>
