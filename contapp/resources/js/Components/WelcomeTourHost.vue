<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { companySwitch } from '../Utils/companySwitch';
import { startup } from '../Utils/startup';
import {
    ArrowLeftIcon, ArrowRightIcon, BookOpenIcon, BotIcon, Building2Icon, HandshakeIcon, LandmarkIcon, LibraryIcon,
    PackageIcon, PencilIcon, PercentIcon, ReceiptIcon, SettingsIcon, TagsIcon, UsersIcon, XIcon,
} from '@lucide/vue';

/**
 * La bienvenida de una licencia recién activada (CLAUDE.md secc. 12): la
 * primera vez que su dueño entra, un recorrido corto por las herramientas de
 * Pivox, que termina pidiéndole los datos reales de la compañía (nació con un
 * nombre predefinido, LicenseActivationService::DEFAULT_COMPANY_NAME).
 *
 * El servidor decide si va (page.props.welcomeTour) y qué secciones del menú
 * puede abrir la persona: un paso sin ninguna no se muestra, y el de Conti
 * solo si la licencia lo tiene. Terminarlo, saltarlo o cerrarlo lo apaga para
 * siempre (WelcomeTourController).
 *
 * Va montado al lado de la página en app.js, como StartupHost: espera a que
 * termine la entrada al sistema (o un cambio de compañía) para aparecer, y
 * sigue abierto si una pantalla se recarga detrás.
 */
const page = usePage();
const tour = computed(() => page.props.welcomeTour ?? null);

// Las secciones del menú, con el nombre y el ícono que tienen en AppLayout.vue.
const SECTIONS = {
    accounting: { label: 'Contabilidad', icon: BookOpenIcon },
    cost_fx: { label: 'Centros de costo y cambiario', icon: TagsIcon },
    banking: { label: 'Bancos', icon: LandmarkIcon },
    tax: { label: 'Impuestos', icon: PercentIcon },
    billing: { label: 'Facturación', icon: ReceiptIcon },
    business_partners: { label: 'Socios de negocio', icon: HandshakeIcon },
    inventory: { label: 'Inventario', icon: PackageIcon },
    payroll: { label: 'Planillas', icon: UsersIcon },
};

const steps = computed(() => {
    if (!tour.value) return [];

    const sections = tour.value.sections ?? [];
    const inMenu = (keys) => keys.filter((key) => sections.includes(key)).map((key) => SECTIONS[key]);
    const name = (page.props.auth?.user?.name ?? '').trim().split(/\s+/)[0];
    const company = tour.value.company;

    const visible = [
        {
            key: 'welcome',
            logo: true,
            title: name ? `¡Te damos la bienvenida, ${name}!` : '¡Te damos la bienvenida a Pivox!',
            text: 'Tu licencia ya está activa y tu compañía está lista para empezar. En un minuto te mostramos las herramientas principales de Pivox.',
            placesLabel: 'Lo que vas a ver',
        },
        {
            key: 'accounting',
            short: 'Contabilidad',
            icon: BookOpenIcon,
            title: 'Contabilidad, bancos e impuestos',
            text: 'Registrá asientos sobre tu catálogo de cuentas, conciliá los bancos y sacá el balance general, el estado de resultados y el reporte de IVA, listos para Excel o PDF.',
            places: inMenu(['accounting', 'cost_fx', 'banking', 'tax']),
        },
        {
            key: 'sales',
            short: 'Facturación',
            icon: ReceiptIcon,
            title: 'Facturación y socios de negocio',
            text: 'Emití facturas electrónicas que se firman y se envían solas a Hacienda, y llevá a tus clientes y proveedores con sus estados de cuenta, cobros y pagos.',
            places: inMenu(['billing', 'business_partners']),
        },
        {
            key: 'inventory',
            short: 'Inventario',
            icon: PackageIcon,
            title: 'Inventario',
            text: 'Artículos, almacenes, compras y existencias al día, con su costo promedio, el kardex y códigos de barras que se leen con la cámara del teléfono.',
            places: inMenu(['inventory']),
        },
        {
            key: 'payroll',
            short: 'Planillas',
            icon: UsersIcon,
            title: 'Planillas',
            text: 'Calculá la planilla con las cargas sociales de la CCSS y el impuesto sobre la renta, y llevá vacaciones, aguinaldo, liquidaciones y comprobantes de pago.',
            places: inMenu(['payroll']),
        },
        {
            key: 'conti',
            short: 'Conti',
            icon: BotIcon,
            title: 'Conti, tu asistente',
            text: 'Preguntale cómo hacer algo, pedile datos de tu compañía o escaneá una factura con el teléfono. Conti te deja todo listo, y nada se guarda sin que lo confirmes.',
            placesLabel: 'Dónde está',
            places: [{ label: 'El botón del robot, abajo a la derecha', icon: BotIcon }],
            show: !!page.props.conti?.enabled,
        },
        {
            key: 'team',
            short: 'Tu equipo',
            icon: SettingsIcon,
            title: 'Tu equipo y tu compañía',
            text: 'Invitá a tu equipo con permisos por pantalla, elegí el tema y el logo de tu compañía y sacá respaldos. En la Biblioteca tenés leyes y resoluciones de Hacienda para consultar.',
            places: [{ label: 'Administración', icon: SettingsIcon }, { label: 'Biblioteca', icon: LibraryIcon }],
            show: !!page.props.auth?.user?.can_manage_company,
        },
        {
            key: 'company',
            icon: Building2Icon,
            final: true,
            title: 'Último paso: los datos de tu compañía',
            text: `Tu compañía se creó como «${company.trade_name || company.legal_name}». Poné la razón social y el nombre comercial reales: salen en los reportes, en los comprobantes electrónicos y en el selector de compañías.`,
            company,
        },
    ].filter((step) => step.show ?? (step.places ? step.places.length > 0 : true));

    // La bienvenida adelanta los temas que siguen, los que quedaron.
    visible[0].places = visible.filter((step) => step.short).map((step) => ({ label: step.short, icon: step.icon }));

    return visible;
});

const index = ref(0);
const direction = ref(1);
const current = computed(() => steps.value[index.value] ?? null);
const isFirst = computed(() => index.value === 0);
const isLast = computed(() => index.value === steps.value.length - 1);

// Aparece cuando terminó la entrada al sistema: encima del logo no se lee.
const open = ref(false);
const busy = ref(false);
const ready = computed(() => !!tour.value && !startup.visible && !companySwitch.visible);
let showTimer = null;

watch(ready, (value) => {
    clearTimeout(showTimer);

    if (!value) {
        // Ya no va (se terminó y el servidor lo apagó): se cierra.
        if (!tour.value) open.value = false;
        return;
    }

    if (open.value) return;

    showTimer = setTimeout(() => {
        index.value = 0;
        direction.value = 1;
        open.value = true;
    }, 350);
}, { immediate: true });

onBeforeUnmount(() => clearTimeout(showTimer));

function go(to) {
    if (to < 0 || to >= steps.value.length || to === index.value) return;
    direction.value = to > index.value ? 1 : -1;
    index.value = to;
}

const next = () => go(index.value + 1);
const previous = () => go(index.value - 1);

// Terminarlo de cualquier forma lo apaga. El recorrido se va cuando el
// servidor contesta: la página se recarga sin welcomeTour (o se pasa a Mi
// cuenta), y si la visita falla queda abierto para volver a intentar.
function finish(editCompany = false) {
    if (busy.value) return;
    busy.value = true;

    router.post(route('welcome-tour.complete'), { edit_company: editCompany }, {
        preserveScroll: true,
        onFinish: () => { busy.value = false; },
    });
}

// El foco entra al abrir, queda adentro mientras está abierto (Tab da la
// vuelta) y vuelve a donde estaba al cerrar.
const dialog = ref(null);
const primary = ref(null);
let returnFocus = null;

watch(open, async (value) => {
    if (value) {
        returnFocus = document.activeElement;
        await nextTick();
        primary.value?.focus();
    } else if (returnFocus?.isConnected) {
        returnFocus.focus();
        returnFocus = null;
    }
});

watch(index, async () => {
    if (!open.value) return;
    await nextTick();
    primary.value?.focus();
});

function onKeydown(event) {
    if (event.key === 'Escape') {
        event.preventDefault();
        finish();
        return;
    }

    if (event.key === 'ArrowRight' && !isLast.value) {
        event.preventDefault();
        next();
        return;
    }

    if (event.key === 'ArrowLeft' && !isFirst.value) {
        event.preventDefault();
        previous();
        return;
    }

    if (event.key !== 'Tab' || !dialog.value) return;

    const focusable = [...dialog.value.querySelectorAll('button:not([disabled]), a[href]')];
    if (!focusable.length) return;

    const first = focusable[0];
    const last = focusable[focusable.length - 1];

    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
}
</script>

<template>
    <Teleport to="body">
        <Transition name="tour-fade">
            <div v-if="open && current" class="tour-backdrop" @keydown="onKeydown">
                <section
                    ref="dialog"
                    class="tour"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="tour-title"
                    aria-describedby="tour-text"
                >
                    <button
                        type="button"
                        class="tour-close"
                        aria-label="Cerrar el recorrido"
                        title="Cerrar el recorrido"
                        :disabled="busy"
                        @click="finish()"
                    >
                        <XIcon />
                    </button>

                    <Transition :name="direction > 0 ? 'tour-next' : 'tour-prev'" mode="out-in">
                        <div :key="current.key" class="tour-step">
                            <div class="tour-hero" aria-hidden="true">
                                <span v-if="current.logo" class="tour-logo"><img src="/images/logos/logo_pivox_corto.png" alt=""></span>
                                <span v-else class="tour-icon"><component :is="current.icon" :size="30" /></span>
                            </div>

                            <div class="tour-body">
                                <p class="tour-count">Paso {{ index + 1 }} de {{ steps.length }}</p>
                                <h2 id="tour-title" class="tour-title">{{ current.title }}</h2>
                                <p id="tour-text" class="tour-text">{{ current.text }}</p>

                                <div v-if="current.places?.length" class="tour-places">
                                    <span class="tour-places-label">{{ current.placesLabel ?? 'En el menú' }}</span>
                                    <ul>
                                        <li v-for="place in current.places" :key="place.label">
                                            <component :is="place.icon" :size="15" /> {{ place.label }}
                                        </li>
                                    </ul>
                                </div>

                                <dl v-if="current.final" class="tour-company">
                                    <div>
                                        <dt>Razón social</dt>
                                        <dd>{{ current.company.legal_name }}</dd>
                                    </div>
                                    <div>
                                        <dt>Nombre comercial</dt>
                                        <dd>{{ current.company.trade_name || current.company.legal_name }}</dd>
                                    </div>
                                    <span class="badge badge-warning">Provisional</span>
                                </dl>
                            </div>
                        </div>
                    </Transition>

                    <footer class="tour-footer">
                        <ol class="tour-dots">
                            <li v-for="(step, i) in steps" :key="step.key">
                                <button
                                    type="button"
                                    :class="{ active: i === index, seen: i < index }"
                                    :aria-label="`Paso ${i + 1}: ${step.title}`"
                                    :aria-current="i === index ? 'step' : undefined"
                                    :disabled="busy"
                                    @click="go(i)"
                                ></button>
                            </li>
                        </ol>

                        <div class="tour-actions">
                            <template v-if="!current.final">
                                <button type="button" class="btn btn-ghost tour-skip" :disabled="busy" @click="finish()">
                                    Saltar el recorrido
                                </button>
                                <span class="tour-nav">
                                    <button v-if="!isFirst" type="button" class="btn btn-ghost" @click="previous">
                                        <ArrowLeftIcon /> Anterior
                                    </button>
                                    <button ref="primary" type="button" class="btn btn-primary" @click="next">
                                        {{ isFirst ? 'Empezar el recorrido' : 'Siguiente' }} <ArrowRightIcon />
                                    </button>
                                </span>
                            </template>

                            <template v-else>
                                <button v-if="!isFirst" type="button" class="btn btn-ghost tour-skip" :disabled="busy" @click="previous">
                                    <ArrowLeftIcon /> Anterior
                                </button>
                                <span class="tour-nav">
                                    <button type="button" class="btn btn-ghost" :disabled="busy" @click="finish()">
                                        Más tarde
                                    </button>
                                    <button ref="primary" type="button" class="btn btn-primary" :disabled="busy" @click="finish(true)">
                                        <PencilIcon /> Cambiar los datos
                                    </button>
                                </span>
                            </template>
                        </div>
                    </footer>
                </section>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
/* Encima de la aplicación y de sus modales; debajo de la entrada al sistema
   (StartupHost, 300) y de los avisos (ToastHost, 1000). */
.tour-backdrop {
    position: fixed;
    inset: 0;
    z-index: 60;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1rem;
    background: rgba(8, 15, 30, 0.55);
    backdrop-filter: blur(3px);
}

.tour {
    position: relative;
    display: flex;
    flex-direction: column;
    width: min(34rem, 100%);
    max-height: calc(100dvh - 2rem);
    overflow: hidden;
    border-radius: var(--radius-md);
    background: var(--color-surface);
    color: var(--color-text);
    box-shadow: 0 24px 64px rgba(8, 15, 30, 0.35);
}

.tour-step {
    flex: 1;
    min-height: 0;
    overflow-y: auto;
}

/* La franja de arriba, con el color de la barra lateral del tema: el ícono
   de cada paso sobre un brillo del acento. */
.tour-hero {
    position: relative;
    display: grid;
    place-items: center;
    height: 9rem;
    overflow: hidden;
    background-color: var(--color-sidebar);
    background-image:
        radial-gradient(120% 95% at 88% 0%, color-mix(in srgb, var(--color-accent) 38%, transparent), transparent 62%),
        radial-gradient(90% 85% at 0% 100%, color-mix(in srgb, var(--color-primary) 60%, transparent), transparent 70%);
}

.tour-icon,
.tour-logo {
    display: grid;
    place-items: center;
    width: 4.25rem;
    height: 4.25rem;
    border-radius: 1.15rem;
    animation: tour-pop 0.45s cubic-bezier(.2, .9, .3, 1.2) both;
}

.tour-icon {
    color: var(--color-accent);
    background: color-mix(in srgb, var(--color-accent) 16%, transparent);
    border: 1px solid color-mix(in srgb, var(--color-accent) 45%, transparent);
    box-shadow: 0 0 0 0.6rem color-mix(in srgb, var(--color-accent) 7%, transparent);
}

/* El logo, como en las barras: en un recuadro blanco. */
.tour-logo {
    background: #fff;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.25);
}

.tour-logo img {
    width: 2.75rem;
    height: 2.75rem;
    object-fit: contain;
}

.tour-close {
    position: absolute;
    top: 0.6rem;
    right: 0.6rem;
    z-index: 1;
    display: grid;
    place-items: center;
    width: 2.25rem;
    height: 2.25rem;
    border: 0;
    border-radius: 999px;
    background: color-mix(in srgb, var(--color-on-sidebar) 12%, transparent);
    color: var(--color-on-sidebar);
    cursor: pointer;
}

.tour-close:hover { background: color-mix(in srgb, var(--color-on-sidebar) 22%, transparent); }
.tour-close:focus-visible { outline: 2px solid var(--color-accent); outline-offset: 2px; }

/* Todos los pasos del mismo alto, para que los botones no salten de lugar al
   avanzar. En un teléfono no hace falta: la hoja crece desde abajo. */
.tour-body {
    min-height: 16.5rem;
    padding: 1.25rem 1.5rem 0.5rem;
}

.tour-count {
    margin: 0 0 0.35rem;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--color-text-muted);
}

.tour-title {
    margin: 0 0 0.5rem;
    font-size: 1.25rem;
    line-height: 1.25;
}

.tour-text {
    margin: 0;
    font-size: 0.92rem;
    line-height: 1.6;
    color: var(--color-text-muted);
}

.tour-places {
    margin-top: 1rem;
}

.tour-places-label {
    display: block;
    margin-bottom: 0.4rem;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--color-text-muted);
}

.tour-places ul {
    display: flex;
    flex-wrap: wrap;
    gap: 0.4rem;
    margin: 0;
    padding: 0;
    list-style: none;
}

.tour-places li {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.3rem 0.65rem;
    border-radius: 999px;
    background: var(--color-primary-soft);
    color: var(--color-primary);
    font-size: 0.8rem;
    font-weight: 600;
}

.tour-company {
    position: relative;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 11rem), 1fr));
    gap: 0.6rem 1rem;
    margin: 1rem 0 0;
    padding: 0.85rem 1rem;
    border: 1px dashed var(--color-control-border);
    border-radius: var(--radius-sm);
    background: var(--color-surface-alt);
}

.tour-company dt {
    font-size: 0.72rem;
    color: var(--color-text-muted);
}

.tour-company dd {
    margin: 0.1rem 0 0;
    font-weight: 700;
    overflow-wrap: anywhere;
}

.tour-company .badge {
    position: absolute;
    top: -0.65rem;
    right: 0.75rem;
}

.tour-footer {
    display: flex;
    flex-direction: column;
    gap: 0.9rem;
    padding: 0.75rem 1.5rem 1.25rem;
}

.tour-dots {
    display: flex;
    justify-content: center;
    gap: 0.4rem;
    margin: 0;
    padding: 0;
    list-style: none;
}

.tour-dots button {
    display: block;
    width: 0.5rem;
    height: 0.5rem;
    padding: 0;
    border: 0;
    border-radius: 999px;
    background: var(--color-border);
    cursor: pointer;
    transition: width 0.25s ease, background-color 0.25s ease;
}

.tour-dots button.seen { background: color-mix(in srgb, var(--color-primary) 45%, var(--color-border)); }
.tour-dots button.active { width: 1.5rem; background: var(--color-primary); }
.tour-dots button:focus-visible { outline: 2px solid var(--color-primary); outline-offset: 2px; }

.tour-actions {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
}

.tour-nav {
    display: flex;
    gap: 0.5rem;
    margin-left: auto;
}

/* Entrar y salir, y el paso a paso: de lado según hacia dónde se va. */
.tour-fade-enter-active,
.tour-fade-leave-active { transition: opacity 0.25s ease; }
.tour-fade-enter-active .tour,
.tour-fade-leave-active .tour { transition: transform 0.3s cubic-bezier(.2, .8, .2, 1); }
.tour-fade-enter-from,
.tour-fade-leave-to { opacity: 0; }
.tour-fade-enter-from .tour,
.tour-fade-leave-to .tour { transform: translateY(14px) scale(0.98); }

.tour-next-enter-active,
.tour-next-leave-active,
.tour-prev-enter-active,
.tour-prev-leave-active { transition: opacity 0.18s ease, transform 0.18s ease; }
.tour-next-enter-from,
.tour-prev-leave-to { opacity: 0; transform: translateX(18px); }
.tour-next-leave-to,
.tour-prev-enter-from { opacity: 0; transform: translateX(-18px); }

@keyframes tour-pop {
    from { opacity: 0; transform: scale(0.8) translateY(6px); }
    to { opacity: 1; transform: none; }
}

@media (prefers-reduced-motion: reduce) {
    .tour-fade-enter-active,
    .tour-fade-leave-active,
    .tour-fade-enter-active .tour,
    .tour-fade-leave-active .tour,
    .tour-next-enter-active,
    .tour-next-leave-active,
    .tour-prev-enter-active,
    .tour-prev-leave-active,
    .tour-dots button { transition: none; }

    .tour-icon,
    .tour-logo { animation: none; }
}

/* En un teléfono: una hoja que sube desde abajo, a todo el ancho, con los
   botones a lo ancho y el principal arriba. */
@media (max-width: 640px) {
    .tour-backdrop {
        align-items: flex-end;
        padding: 0;
    }

    .tour {
        width: 100%;
        max-height: 92dvh;
        border-radius: var(--radius-md) var(--radius-md) 0 0;
    }

    .tour-hero { height: 7.5rem; }

    .tour-body {
        min-height: 0;
        padding: 1.1rem 1.1rem 0.4rem;
    }
    .tour-footer { padding: 0.75rem 1.1rem calc(1rem + env(safe-area-inset-bottom)); }

    .tour-actions {
        flex-direction: column-reverse;
        align-items: stretch;
    }

    .tour-nav {
        flex-direction: column-reverse;
        margin-left: 0;
    }

    .tour-actions .btn {
        justify-content: center;
        width: 100%;
    }

    .tour-fade-enter-from .tour,
    .tour-fade-leave-to .tour { transform: translateY(100%); }
}
</style>
