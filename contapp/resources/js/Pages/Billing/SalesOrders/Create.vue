<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DetailModal from '../../../Components/DetailModal.vue';
import { ArrowLeftIcon, PlusIcon, XIcon } from '@lucide/vue';

const props = defineProps({
    customers: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
    warehouses: { type: Array, default: () => [] },
    stock: { type: Array, default: () => [] },
    // Si quien toma el pedido ya puede liberar cambios de precio, la
    // pantalla no le pide autorización a nadie. El servidor lo recomprueba.
    canAuthorizePriceChange: { type: Boolean, default: false },
});

const page = usePage();
const today = new Date().toISOString().slice(0, 10);

const form = useForm({
    business_partner_id: props.customers[0]?.id ?? '',
    order_date: today,
    delivery_date: '',
    description: '',
    lines: [emptyLine()],
    // Credenciales de quien libera un cambio de precio: viajan solo cuando
    // hacen falta y no se guardan en ningún lado.
    price_override_email: '',
    price_override_password: '',
    price_override_reason: '',
});

function emptyLine() {
    return {
        item_id: props.items[0]?.id ?? '',
        warehouse_id: props.warehouses[0]?.id ?? '',
        quantity: '',
        unit_price: '',
        description: '',
    };
}

function quantity(value) {
    return Number(value ?? 0).toLocaleString('es-CR', { minimumFractionDigits: 0, maximumFractionDigits: 6 });
}

function money(value) {
    return Number(value ?? 0).toLocaleString('es-CR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// Lo libre es lo que decide si el pedido se puede tomar: la existencia menos
// lo que otros pedidos ya apartaron.
function freeFor(line) {
    const row = props.stock.find(
        (s) => s.item_id === Number(line.item_id) && s.warehouse_id === Number(line.warehouse_id)
    );

    return row ? row.free : 0;
}

function exceeds(line) {
    return Number(line.quantity || 0) > freeFor(line);
}

const total = computed(() => form.lines.reduce(
    (sum, line) => sum + Number(line.quantity || 0) * Number(line.unit_price || 0), 0
));

const invalid = computed(() =>
    ! form.lines.length
    || form.lines.some((line) => ! line.item_id || ! line.warehouse_id || Number(line.quantity || 0) <= 0 || exceeds(line))
);

// --- Precios de la lista del cliente ---

// El pedido no elegía precios de ninguna lista: se digitaban. Pedirle a
// alguien que respete un precio que no ve sería absurdo, así que el control
// y la precarga llegan juntos.
const priceMap = ref({});
const priceListInfo = ref(null);

async function loadPrices() {
    const params = new URLSearchParams();
    if (form.business_partner_id) params.set('business_partner_id', form.business_partner_id);
    if (form.order_date) params.set('date', form.order_date);

    try {
        const response = await fetch(`${route('price-lists.for-customer')}?${params}`, {
            headers: { Accept: 'application/json' },
        });

        if (! response.ok) return;

        const data = await response.json();
        priceMap.value = data.prices ?? {};
        priceListInfo.value = data.reason === 'found' ? data.list : null;
    } catch {
        priceMap.value = {};
    }
}

watch(() => [form.business_partner_id, form.order_date], loadPrices, { immediate: true });

function listPriceOf(line) {
    const price = priceMap.value[line.item_id];

    return price === undefined ? null : Number(price);
}

// Solo se pisa un precio vacío: no se le borra a nadie lo que ya negoció.
function applyListPrice(line) {
    const listed = listPriceOf(line);

    if (listed !== null && (line.unit_price === '' || line.unit_price === null)) {
        line.unit_price = listed;
    }
}

function deviatesFromList(line) {
    const listed = listPriceOf(line);

    if (listed === null || line.unit_price === '' || line.unit_price === null) return false;

    return Math.abs(Number(line.unit_price) - listed) > 0.00001;
}

const deviations = computed(() => form.lines.filter(deviatesFromList));

const needsAuthorization = computed(
    () => ! props.canAuthorizePriceChange && deviations.value.length > 0
);

const authorizing = ref(false);

function attemptSubmit() {
    if (needsAuthorization.value) {
        authorizing.value = true;

        return;
    }

    submit();
}

function submitWithAuthorization() {
    authorizing.value = false;
    submit();
}

function submit() {
    form
        .transform((data) => ({
            ...data,
            delivery_date: data.delivery_date === '' ? null : data.delivery_date,
            description: data.description === '' ? null : data.description,
            lines: data.lines.map((line) => ({
                ...line,
                unit_price: line.unit_price === '' ? null : line.unit_price,
                description: line.description === '' ? null : line.description,
            })),
        }))
        .post(route('sales-orders.store'));
}
</script>

<template>
    <Head title="Nuevo pedido" />

    <AppLayout title="Nueva orden de pedido">
        <div class="view-toolbar">
            <Link :href="route('sales-orders.index')" class="btn btn-ghost"><ArrowLeftIcon /> Órdenes de pedido</Link>
        </div>

        <div v-if="page.props.errors?.order" class="flash flash-error">{{ page.props.errors.order }}</div>

        <p class="hint">
            El pedido no contabiliza nada: solo aparta la mercancía para este cliente. Se puede apartar
            únicamente lo que esté <strong>libre</strong> —la existencia menos lo que otros pedidos ya
            comprometieron—, porque prometer lo que no hay no es apartar.
        </p>

        <form class="card order-form" @submit.prevent="attemptSubmit">
            <div class="form-grid">
                <div class="field">
                    <label>Cliente</label>
                    <select v-model="form.business_partner_id" required>
                        <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.code }} — {{ c.name }}</option>
                    </select>
                    <span v-if="form.errors.business_partner_id" class="error">{{ form.errors.business_partner_id }}</span>
                </div>
                <div class="field">
                    <label>Fecha del pedido</label>
                    <input v-model="form.order_date" type="date" required>
                </div>
                <div class="field">
                    <label>Fecha de entrega (opcional)</label>
                    <input v-model="form.delivery_date" type="date">
                </div>
            </div>

            <div class="table-responsive capture-grid lines-grid">
                <table>
                    <thead>
                        <tr>
                            <th>Artículo</th>
                            <th>Bodega</th>
                            <th class="num">Cantidad</th>
                            <th class="num">Precio pactado</th>
                            <th class="num">Subtotal</th>
                            <th><span class="sr-only">Quitar</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(line, index) in form.lines" :key="index">
                            <td class="item-cell">
                                <select v-model="line.item_id" required :aria-label="`Artículo de la línea ${index + 1}`" @change="applyListPrice(line)">
                                    <option v-for="i in items" :key="i.id" :value="i.id">{{ i.code }} — {{ i.name }}</option>
                                </select>
                            </td>
                            <td data-label="Bodega" class="warehouse-cell">
                                <select v-model="line.warehouse_id" required :aria-label="`Bodega de la línea ${index + 1}`">
                                    <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.code }}</option>
                                </select>
                            </td>
                            <td data-label="Cantidad" class="qty-cell">
                                <input v-model="line.quantity" type="number" step="0.000001" min="0" class="right" required :aria-label="`Cantidad de la línea ${index + 1}`">
                                <span class="free small" :class="{ none: freeFor(line) <= 0 }">Libres: {{ quantity(freeFor(line)) }}</span>
                                <span v-if="exceeds(line)" class="error">Solo hay {{ quantity(freeFor(line)) }} libres.</span>
                            </td>
                            <td data-label="Precio pactado" class="price-cell">
                                <input v-model="line.unit_price" type="number" step="0.01" min="0" class="right" :aria-label="`Precio pactado de la línea ${index + 1}`">
                            </td>
                            <td data-label="Subtotal" class="num">
                                {{ money(Number(line.quantity || 0) * Number(line.unit_price || 0)) }}
                            </td>
                            <td data-label="" class="remove-cell">
                                <button
                                    type="button" class="btn btn-ghost"
                                    :disabled="form.lines.length === 1"
                                    :aria-label="`Quitar la línea ${index + 1}`"
                                    @click="form.lines.splice(index, 1)"
                                ><XIcon /></button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <button type="button" class="btn btn-ghost add-line" @click="form.lines.push(emptyLine())"><PlusIcon /> Agregar línea</button>

            <div class="field">
                <label>Descripción (opcional)</label>
                <input v-model="form.description" type="text" maxlength="255">
            </div>

            <p v-if="priceListInfo" class="hint">
                Precios sugeridos de la lista <strong>{{ priceListInfo.code }} — {{ priceListInfo.name }}</strong>,
                precargados al elegir el artículo.
            </p>

            <div v-if="deviations.length" class="price-warning">
                <strong>{{ deviations.length }} línea(s) con precio distinto al de la lista.</strong>
                <ul class="deviation-list">
                    <li v-for="(line, i) in deviations" :key="i">
                        lista {{ money(listPriceOf(line)) }} → pactado {{ money(line.unit_price) }}
                    </li>
                </ul>
                <span v-if="needsAuthorization" class="needs-auth">
                    El precio se pacta acá, así que la autorización se pide al registrar el pedido y no
                    después: la factura que lo cumpla ya no la vuelve a pedir.
                </span>
                <span v-else class="muted small">Como administrador podés registrarlo directamente.</span>
            </div>

            <div v-if="page.props.errors?.price_override" class="flash flash-error">
                {{ page.props.errors.price_override }}
            </div>

            <div class="totals">
                <div><span class="muted small">Total pactado (informativo)</span><strong class="num total">{{ money(total) }}</strong></div>
            </div>

            <div class="form-actions">
                <Link :href="route('sales-orders.index')" class="btn btn-ghost">Cancelar</Link>
                <button type="submit" class="btn btn-primary" :disabled="invalid || form.processing">
                    {{ needsAuthorization ? 'Registrar (requiere autorización)' : 'Registrar pedido y apartar' }}
                </button>
            </div>
        </form>

        <DetailModal :open="authorizing" title="Autorización de cambio de precio" @close="authorizing = false">
            <form id="order-auth-form" @submit.prevent="submitWithAuthorization">
                <p class="muted small">
                    Este pedido se aparta de la lista en <strong>{{ deviations.length }}</strong> línea(s).
                    Lo que se firme acá vale también para la factura que lo cumpla.
                </p>

                <div class="field">
                    <label>Usuario que autoriza</label>
                    <input v-model="form.price_override_email" type="email" autocomplete="off" required placeholder="correo del administrador">
                </div>

                <div class="field">
                    <label>Contraseña</label>
                    <input v-model="form.price_override_password" type="password" autocomplete="new-password" required>
                    <span class="muted small">
                        No se guarda en ningún lado: se compara y se descarta. Queda registrado quién autorizó.
                    </span>
                </div>

                <div class="field">
                    <label>Motivo (opcional)</label>
                    <input v-model="form.price_override_reason" type="text" maxlength="255">
                </div>
            </form>

            <template #actions>
                <button type="button" class="btn btn-ghost" @click="authorizing = false">Cancelar</button>
                <button type="submit" form="order-auth-form" class="btn btn-primary" :disabled="form.processing">Autorizar y registrar</button>
            </template>
        </DetailModal>
    </AppLayout>
</template>

<style scoped>
.order-form { padding: 1rem 1.25rem; }
.error { color: var(--color-danger); font-size: 0.76rem; display: block; }

table { font-size: 0.85rem; }
th, td { padding: 0.4rem 0.5rem; }
.right, td input.right { text-align: right; }
.num { font-variant-numeric: tabular-nums; }
.none { color: var(--color-danger); }

.lines-grid { margin-bottom: 0.6rem; }
.lines-grid td { vertical-align: top; }
.warehouse-cell { width: 6rem; }
.qty-cell, .price-cell { width: 8rem; }
.item-cell select, .warehouse-cell select, .qty-cell input, .price-cell input { width: 100%; }
.free { display: block; margin-top: 0.2rem; color: var(--color-text-muted); font-variant-numeric: tabular-nums; }
.remove-cell { width: 1%; }
.add-line { margin-bottom: 0.9rem; }

@media screen and (max-width: 1024px) {
    .warehouse-cell, .qty-cell, .price-cell { width: auto; }
}

.price-warning { margin: 0.75rem 0; padding: 0.7rem 0.9rem; border-radius: var(--radius-sm); background: var(--color-warning-soft); color: var(--color-warning); font-size: 0.82rem; }
.deviation-list { margin: 0.4rem 0 0 1.1rem; padding: 0; font-size: 0.78rem; }
.needs-auth { display: block; margin-top: 0.4rem; font-weight: 600; }

.totals { display: flex; gap: 1.75rem; padding: 0.85rem 0; border-top: 1px solid var(--color-border); }
.totals > div { display: flex; flex-direction: column; gap: 0.15rem; }
.total { font-size: 1.05rem; }
</style>
