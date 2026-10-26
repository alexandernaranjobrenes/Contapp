<script setup>
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import BackofficeLayout from '../../../Layouts/BackofficeLayout.vue';
import DetailModal from '../../../Components/DetailModal.vue';
import { fullDate, timeAgo } from '../../../Utils/relativeTime';
import { MailIcon, PhoneIcon, RotateCcwIcon, SendIcon, XIcon } from '@lucide/vue';

/**
 * Las solicitudes de licencia que llegan desde la página de precios del sitio
 * (LicenseRequestController, CLAUDE.md secc. 42). De cada una se decide:
 * responderla por correo (con el resumen de la cotización, si se quiere) o
 * descartarla sin responder. Se puede reabrir. Los filtros son del navegador.
 */
const props = defineProps({
    requests: { type: Array, required: true },
    openId: { type: Number, default: null },
    contactEmail: { type: String, default: '' },
});

const STATUS = {
    new: { label: 'Nueva', badge: 'badge-warning' },
    replied: { label: 'Respondida', badge: 'badge-success' },
    discarded: { label: 'Descartada', badge: 'badge-neutral' },
};

// ── Filtros ───────────────────────────────────────────────────────────────
const statusFilter = ref(props.requests.some((item) => item.status === 'new') ? 'new' : '');
const search = ref('');

const plain = (text) => (text ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

const rows = computed(() => {
    const term = plain(search.value.trim());

    return props.requests.filter((item) => (!statusFilter.value || item.status === statusFilter.value)
        && (!term || plain(`${item.reference} ${item.contact_name} ${item.company_name} ${item.email} ${item.package_name ?? ''}`).includes(term)));
});

const countOf = (status) => props.requests.filter((item) => item.status === status).length;

// ── Ficha ─────────────────────────────────────────────────────────────────
const selectedId = ref(null);
const mode = ref('details'); // 'details' | 'reply' | 'discard'
const selected = computed(() => props.requests.find((item) => item.id === selectedId.value) ?? null);

const modalTitle = computed(() => {
    if (!selected.value) return '';
    if (mode.value === 'reply') return `Responder a ${selected.value.contact_name}`;
    if (mode.value === 'discard') return `Descartar ${selected.value.reference}`;

    return `${selected.value.reference} · ${selected.value.company_name}`;
});

function open(item) {
    selectedId.value = item.id;
    mode.value = 'details';
}

function close() {
    if (mode.value !== 'details') {
        mode.value = 'details';
        return;
    }

    selectedId.value = null;
}

onMounted(() => {
    const item = props.openId ? props.requests.find((request) => request.id === props.openId) : null;

    if (item) {
        statusFilter.value = '';
        open(item);
    }
});

// ── Responder ─────────────────────────────────────────────────────────────
const replyForm = useForm({ subject: '', body: '', include_quote: true });

function startReply() {
    const item = selected.value;
    const firstName = item.contact_name.split(/\s+/)[0];
    // «… S.A.» ya trae su punto: sin otro al final de la oración.
    const company = item.company_name.replace(/\.+$/, '');

    replyForm.clearErrors();
    replyForm.subject = `Tu solicitud de licencia de Pivox (${item.reference})`;
    replyForm.body = [
        `Hola, ${firstName}:`,
        `Gracias por tu interés en Pivox. Recibimos tu solicitud de licencia para ${company}.`,
        'Te escribimos para coordinar el pago y la activación de tu licencia.',
        'Quedamos atentos a cualquier consulta.',
        'Saludos,\nEquipo Pivox',
    ].join('\n\n');
    replyForm.include_quote = true;
    mode.value = 'reply';
}

function sendReply() {
    replyForm
        .transform((data) => ({ ...data, include_quote: data.include_quote ? 1 : 0 }))
        .post(route('backoffice.license-requests.reply', selected.value.id), {
            preserveScroll: true,
            onSuccess: () => { mode.value = 'details'; },
        });
}

// ── Descartar y reabrir ───────────────────────────────────────────────────
const discardForm = useForm({ note: '' });

function startDiscard() {
    discardForm.clearErrors();
    discardForm.note = selected.value.internal_note ?? '';
    mode.value = 'discard';
}

function discard() {
    discardForm.post(route('backoffice.license-requests.discard', selected.value.id), {
        preserveScroll: true,
        onSuccess: () => { mode.value = 'details'; },
    });
}

function reopen() {
    router.post(route('backoffice.license-requests.reopen', selected.value.id), {}, { preserveScroll: true });
}

const whatsapp = (phone) => `https://wa.me/${phone.replace(/[^0-9]/g, '').replace(/^(\d{8})$/, '506$1')}`;
</script>

<template>
    <Head title="Solicitudes de licencia" />

    <BackofficeLayout title="Solicitudes de licencia">
        <div class="view-toolbar">
            <div class="view-filters">
                <label class="filter-field">
                    <span>Estado</span>
                    <select v-model="statusFilter">
                        <option value="new">Nuevas ({{ countOf('new') }})</option>
                        <option value="replied">Respondidas ({{ countOf('replied') }})</option>
                        <option value="discarded">Descartadas ({{ countOf('discarded') }})</option>
                        <option value="">Todas ({{ requests.length }})</option>
                    </select>
                </label>
                <label class="filter-field">
                    <span>Buscar</span>
                    <input v-model="search" type="search" placeholder="Nombre, compañía, correo o número">
                </label>
            </div>
        </div>

        <p class="hint">
            Lo que piden los visitantes desde la página de precios, con la cotización tal como la vieron. De cada una decidís si
            se le responde por correo o se descarta sin responder. Al llegar una nueva, se avisa por correo.
        </p>

        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Solicitud</th>
                            <th>Plan</th>
                            <th class="right">Total</th>
                            <th>Estado</th>
                            <th>Recibida</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="item in rows"
                            :key="item.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="open(item)"
                            @keydown.enter="open(item)"
                            @keydown.space.prevent="open(item)"
                        >
                            <td>
                                <strong>{{ item.company_name }}</strong>
                                <span class="block muted small">{{ item.contact_name }} · {{ item.reference }}</span>
                            </td>
                            <td data-label="Plan">{{ item.package_name ?? 'Personalizado' }}<span class="block muted small">{{ item.cycle }}</span></td>
                            <td data-label="Total" class="right">{{ item.total }}</td>
                            <td data-label="Estado"><span class="badge" :class="STATUS[item.status].badge">{{ STATUS[item.status].label }}</span></td>
                            <td data-label="Recibida"><time :datetime="item.created_at" :title="fullDate(item.created_at)">{{ timeAgo(item.created_at) }}</time></td>
                        </tr>
                        <tr v-if="!rows.length">
                            <td colspan="5" class="muted empty-row">
                                <template v-if="requests.length">Ninguna solicitud coincide con los filtros.</template>
                                <template v-else>Todavía no llegó ninguna solicitud desde la página de precios.</template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <DetailModal :open="!!selected" :title="modalTitle" wide @close="close">
            <template v-if="selected && mode === 'details'" #badge>
                <span class="badge" :class="STATUS[selected.status].badge">{{ STATUS[selected.status].label }}</span>
            </template>

            <template v-if="selected && mode === 'details'">
                <dl class="detail-list">
                    <div>
                        <dt>Contacto</dt>
                        <dd>{{ selected.contact_name }}</dd>
                    </div>
                    <div>
                        <dt>Compañía</dt>
                        <dd>{{ selected.company_name }}<template v-if="selected.identification"> · {{ selected.identification }}</template></dd>
                    </div>
                    <div>
                        <dt>Correo</dt>
                        <dd><a :href="`mailto:${selected.email}`"><MailIcon /> {{ selected.email }}</a></dd>
                    </div>
                    <div>
                        <dt>Teléfono</dt>
                        <dd>
                            <template v-if="selected.phone">
                                <a :href="`tel:${selected.phone}`"><PhoneIcon /> {{ selected.phone }}</a>
                                · <a :href="whatsapp(selected.phone)" target="_blank" rel="noopener">WhatsApp</a>
                            </template>
                            <template v-else>—</template>
                        </dd>
                    </div>
                    <div>
                        <dt>Recibida</dt>
                        <dd>{{ fullDate(selected.created_at) }}</dd>
                    </div>
                    <div v-if="selected.handled_at">
                        <dt>{{ selected.status === 'discarded' ? 'Descartada' : 'Atendida' }}</dt>
                        <dd>{{ fullDate(selected.handled_at) }}<template v-if="selected.handled_by"> por {{ selected.handled_by }}</template></dd>
                    </div>
                    <div v-if="selected.message" class="full">
                        <dt>Su mensaje</dt>
                        <dd class="request-message">{{ selected.message }}</dd>
                    </div>
                </dl>

                <h3 class="section-title">{{ selected.package_name ?? 'Plan personalizado' }} · {{ selected.cycle }} · {{ selected.currency === 'USD' ? 'dólares' : 'colones' }}</h3>
                <div class="table-responsive no-cards">
                    <table class="quote-table">
                        <tbody>
                            <tr v-for="line in selected.lines" :key="line.name">
                                <td>{{ line.name }}</td>
                                <td class="num">{{ line.amount }}</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr v-for="row in selected.totals" :key="row.label">
                                <td>{{ row.label }}</td>
                                <td class="num">{{ row.amount }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <template v-if="selected.internal_note">
                    <h3 class="section-title">Nota interna</h3>
                    <p class="request-message">{{ selected.internal_note }}</p>
                </template>

                <template v-if="selected.replies.length">
                    <h3 class="section-title">Correos enviados</h3>
                    <ul class="reply-list">
                        <li v-for="reply in selected.replies" :key="reply.id" :class="{ 'is-failed': reply.status === 'failed' }">
                            <div class="reply-head">
                                <strong>{{ reply.subject }}</strong>
                                <span class="badge" :class="reply.status === 'sent' ? 'badge-success' : 'badge-danger'">{{ reply.status === 'sent' ? 'Enviado' : 'No salió' }}</span>
                            </div>
                            <span class="muted small">{{ fullDate(reply.created_at) }}<template v-if="reply.by"> · {{ reply.by }}</template><template v-if="reply.include_quote"> · con la cotización</template></span>
                            <p class="request-message">{{ reply.body }}</p>
                            <p v-if="reply.error" class="error small">{{ reply.error }}</p>
                        </li>
                    </ul>
                </template>
            </template>

            <form v-if="selected && mode === 'reply'" id="reply-form" @submit.prevent="sendReply">
                <div class="form-grid">
                    <div class="field">
                        <label for="reply-to">Para</label>
                        <input id="reply-to" :value="selected.email" type="email" readonly>
                    </div>
                    <div class="field">
                        <label for="reply-subject">Asunto</label>
                        <input id="reply-subject" v-model="replyForm.subject" type="text" maxlength="200" required>
                        <span v-if="replyForm.errors.subject" class="error">{{ replyForm.errors.subject }}</span>
                    </div>
                    <div class="field span-full">
                        <label for="reply-body">Mensaje</label>
                        <textarea id="reply-body" v-model="replyForm.body" rows="11" maxlength="5000" required />
                        <span class="field-hint">Una línea en blanco separa los párrafos.<template v-if="contactEmail"> Si responde al correo, le escribe a {{ contactEmail }}.</template></span>
                        <span v-if="replyForm.errors.body" class="error">{{ replyForm.errors.body }}</span>
                    </div>
                    <div class="field span-full">
                        <label class="check"><input v-model="replyForm.include_quote" type="checkbox"> Incluir el resumen de la cotización ({{ selected.total }} {{ selected.cycle }})</label>
                    </div>
                </div>
            </form>

            <form v-if="selected && mode === 'discard'" id="discard-form" @submit.prevent="discard">
                <p class="hint">La solicitud queda como atendida, sin enviarle nada a {{ selected.contact_name }}. Se puede reabrir.</p>
                <div class="field">
                    <label for="discard-note">Nota interna (opcional)</label>
                    <textarea id="discard-note" v-model="discardForm.note" rows="3" maxlength="1000" placeholder="Por ejemplo: duplicada, o se coordinó por teléfono." />
                    <span v-if="discardForm.errors.note" class="error">{{ discardForm.errors.note }}</span>
                </div>
            </form>

            <template #actions>
                <template v-if="selected && mode === 'details'">
                    <button v-if="selected.status === 'new'" type="button" class="btn btn-ghost btn-danger-text footer-start" @click="startDiscard"><XIcon /> Descartar</button>
                    <button v-else type="button" class="btn btn-ghost footer-start" @click="reopen"><RotateCcwIcon /> Reabrir</button>
                    <button type="button" class="btn btn-primary" @click="startReply"><MailIcon /> Responder por correo</button>
                </template>
                <template v-else-if="mode === 'reply'">
                    <button type="button" class="btn btn-ghost" @click="mode = 'details'">Cancelar</button>
                    <button type="submit" form="reply-form" class="btn btn-primary" :disabled="replyForm.processing"><SendIcon /> Enviar</button>
                </template>
                <template v-else-if="mode === 'discard'">
                    <button type="button" class="btn btn-ghost" @click="mode = 'details'">Cancelar</button>
                    <button type="submit" form="discard-form" class="btn btn-danger" :disabled="discardForm.processing"><XIcon /> Descartar sin responder</button>
                </template>
            </template>
        </DetailModal>
    </BackofficeLayout>
</template>

<style scoped>
.block { display: block; }

.request-message {
    margin: 0;
    white-space: pre-line;
    overflow-wrap: anywhere;
}

.quote-table td:last-child {
    width: 10rem;
}

.quote-table tfoot td {
    font-weight: 700;
}

.reply-list {
    display: grid;
    gap: 0.7rem;
    margin: 0;
    padding: 0;
    list-style: none;
}

.reply-list li {
    display: flex;
    flex-direction: column;
    gap: 0.3rem;
    padding: 0.75rem 0.9rem;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
}

.reply-list li.is-failed {
    border-color: var(--color-danger);
}

.reply-head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
}

.field :is(input:not([type="checkbox"], [type="radio"]), textarea) {
    width: 100%;
}

.field-hint {
    color: var(--color-text-muted);
    font-size: 0.75rem;
}

/* Descartar y reabrir van a la izquierda del pie (CLAUDE.md secc. 27). */
.footer-start {
    margin-right: auto;
}
</style>
