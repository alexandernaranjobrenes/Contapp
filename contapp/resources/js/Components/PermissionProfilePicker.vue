<script setup>
import { computed, ref } from 'vue';
import {
    BriefcaseIcon, CalculatorIcon, CheckIcon, LandmarkIcon, NotebookPenIcon, ReceiptIcon, SearchCheckIcon,
    ShieldCheckIcon, TruckIcon, Undo2Icon, UserCogIcon, UsersIcon, WarehouseIcon,
} from '@lucide/vue';

/**
 * Elegir un perfil (Contador, Vendedor…) precarga los permisos del editor de
 * abajo (ScreenPermissionsEditor.vue); después se ajustan a mano. El perfil
 * no se guarda: lo que se envía son los permisos, como siempre. Los perfiles
 * vienen de config/permission_profiles.php (PermissionProfiles).
 *
 * - Cada perfil es una tarjeta: se toca para cargarlo.
 * - Nunca da más de lo que puede dar quien edita (el max_level de cada
 *   pantalla), y lo que la persona ya tiene de más (bloqueado) no se toca.
 *   Un perfil del que no puede dar nada queda deshabilitado.
 * - Queda marcado el perfil que calza con los permisos de abajo; si se
 *   cambian a mano, pasa solo a «Personalizado».
 * - «Deshacer» vuelve a los permisos de antes de elegirlo.
 *
 * v-model: los permisos, { clave de pantalla: 'none' | 'read' | 'read_write' }.
 */
const props = defineProps({
    profiles: { type: Array, required: true },
    sections: { type: Array, required: true },
    modelValue: { type: Object, required: true },
    id: { type: String, default: 'permission-profile' },
});

const emit = defineEmits(['update:modelValue']);

const LEVELS = ['none', 'read', 'read_write'];
const rank = (level) => LEVELS.indexOf(level ?? 'none');

// El ícono de cada perfil; uno nuevo de la configuración, el genérico.
const ICONS = {
    administrador_general: ShieldCheckIcon,
    contador_general: CalculatorIcon,
    contador: CalculatorIcon,
    gerente: BriefcaseIcon,
    asistente_contable: NotebookPenIcon,
    vendedor: ReceiptIcon,
    comprador: TruckIcon,
    bodeguero: WarehouseIcon,
    planillas: UsersIcon,
    tesoreria: LandmarkIcon,
    auditor: SearchCheckIcon,
};
const iconFor = (profile) => ICONS[profile.key] ?? UserCogIcon;
const screens = computed(() => props.sections.flatMap((section) => section.screens));

function locked(screen) {
    return rank(screen.current_level) > rank(screen.max_level) && props.modelValue[screen.key] === screen.current_level;
}

/** Los permisos que deja un perfil, recortados a lo que se puede dar. */
function resultOf(profile) {
    return Object.fromEntries(screens.value.map((screen) => {
        if (locked(screen)) return [screen.key, props.modelValue[screen.key]];
        const wanted = profile.levels[screen.key] ?? 'none';
        return [screen.key, LEVELS[Math.min(rank(wanted), rank(screen.max_level))]];
    }));
}

/** ¿Da algo? Si quien edita no tiene nada de lo del perfil, no se ofrece. */
function givesSomething(profile) {
    return screens.value.some((screen) => !locked(screen)
        && Math.min(rank(profile.levels[screen.key]), rank(screen.max_level)) > 0);
}

const hasAccess = computed(() => screens.value.some((screen) => (props.modelValue[screen.key] ?? 'none') !== 'none'));

function matches(profile) {
    if (!givesSomething(profile)) return false;
    const result = resultOf(profile);
    return screens.value.every((screen) => (props.modelValue[screen.key] ?? 'none') === result[screen.key]);
}

// Lo de antes de elegir un perfil, para «Deshacer», y qué perfil se eligió.
const before = ref(null);
const appliedKey = ref(null);

// Sin ningún permiso no calza ningún perfil: todos dan algo. Recortados a
// lo que puede dar quien edita, dos perfiles pueden quedar iguales: gana el
// que se eligió.
const selected = computed(() => {
    if (!hasAccess.value) return null;

    const applied = props.profiles.find((p) => p.key === appliedKey.value);
    if (applied && matches(applied)) return applied;

    return props.profiles.find(matches) ?? null;
});
const showApplied = computed(() => appliedKey.value !== null && selected.value?.key === appliedKey.value);

// ¿Quedó algo del perfil afuera, porque quien edita no lo puede dar?
const trimmed = computed(() => {
    if (!showApplied.value) return false;
    return screens.value.some((screen) => !locked(screen) && rank(selected.value.levels[screen.key]) > rank(screen.max_level));
});

function choose(key) {
    const profile = props.profiles.find((p) => p.key === key);
    // El que ya está elegido no se vuelve a cargar: «Deshacer» sigue volviendo a lo de antes.
    if (!profile || selected.value?.key === key) return;

    before.value = { ...props.modelValue };
    appliedKey.value = profile.key;
    emit('update:modelValue', { ...props.modelValue, ...resultOf(profile) });
}

function undo() {
    if (before.value) emit('update:modelValue', before.value);
    before.value = null;
    appliedKey.value = null;
}
</script>

<template>
    <div v-if="profiles.length" class="profile-picker">
        <div class="profile-head">
            <h3 :id="`${id}-title`" class="profile-title">Perfil</h3>
            <span v-if="selected" class="profile-state is-profile">{{ selected.label }}</span>
            <span v-else-if="hasAccess" class="profile-state">Personalizado</span>
        </div>
        <p class="hint profile-hint">
            Elegí uno para precargar los permisos de abajo, y después ajustalos. El perfil no se guarda.
        </p>

        <div :id="id" class="profile-grid" role="group" :aria-labelledby="`${id}-title`">
            <button
                v-for="profile in profiles"
                :key="profile.key"
                type="button"
                class="profile-card"
                :class="{ 'is-selected': selected?.key === profile.key }"
                :aria-pressed="selected?.key === profile.key"
                :disabled="!givesSomething(profile)"
                @click="choose(profile.key)"
            >
                <span class="profile-icon"><component :is="iconFor(profile)" /></span>
                <span class="profile-text">
                    <strong>{{ profile.label }}</strong>
                    <span class="profile-desc">
                        {{ givesSomething(profile) ? profile.description : 'No tenés esos permisos para darlos.' }}
                    </span>
                </span>
                <CheckIcon v-if="selected?.key === profile.key" class="profile-check" aria-hidden="true" />
            </button>
        </div>

        <div v-if="showApplied" class="profile-applied" role="status">
            <span>
                Se cargaron los permisos de «{{ selected.label }}». Revisalos abajo y ajustá lo que haga falta.
                <template v-if="trimmed"> Algunas pantallas del perfil no las podés dar vos: quedaron con menos acceso.</template>
            </span>
            <button type="button" class="btn btn-ghost btn-sm" @click="undo"><Undo2Icon /> Deshacer</button>
        </div>
    </div>
</template>

<style scoped>
.profile-picker {
    margin-top: 0.25rem;
}

.profile-head {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.5rem;
}

.profile-title {
    margin: 0;
    font-size: 0.88rem;
}

.profile-state {
    padding: 0.1rem 0.55rem;
    border-radius: 999px;
    background: var(--color-surface-alt);
    color: var(--color-text-muted);
    font-size: 0.72rem;
    font-weight: 700;
}

.profile-state.is-profile {
    background: var(--color-primary-soft);
    color: color-mix(in srgb, var(--color-primary) 80%, var(--color-text));
}

.profile-hint {
    font-size: 0.78rem;
    margin: 0.25rem 0 0.6rem;
}

.profile-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(min(100%, 15rem), 1fr));
    gap: 0.5rem;
}

.profile-card {
    position: relative;
    display: flex;
    align-items: flex-start;
    gap: 0.65rem;
    min-width: 0;
    padding: 0.7rem 2rem 0.7rem 0.75rem;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    background: var(--color-surface);
    font: inherit;
    color: var(--color-text);
    text-align: left;
    cursor: pointer;
    transition: border-color .15s ease, background .15s ease;
}

.profile-card:not(:disabled):hover {
    border-color: var(--color-primary);
}

.profile-card:focus-visible {
    outline: 2px solid var(--color-primary);
    outline-offset: 2px;
}

.profile-card.is-selected {
    border-color: var(--color-primary);
    background: var(--color-primary-soft);
    box-shadow: inset 0 0 0 1px var(--color-primary);
}

.profile-card:disabled {
    opacity: 0.55;
    cursor: not-allowed;
}

.profile-icon {
    display: inline-grid;
    place-items: center;
    flex-shrink: 0;
    width: 2.1rem;
    height: 2.1rem;
    border-radius: 50%;
    background: var(--color-surface-alt);
    color: color-mix(in srgb, var(--color-primary) 75%, var(--color-text));
}

.profile-icon :deep(svg) {
    width: 1.1rem;
    height: 1.1rem;
}

.is-selected .profile-icon {
    background: var(--color-primary);
    color: var(--color-on-primary);
}

.profile-text {
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
    min-width: 0;
}

.profile-text strong {
    font-size: 0.88rem;
}

.profile-desc {
    font-size: 0.76rem;
    line-height: 1.4;
    color: var(--color-text-muted);
}

.profile-check {
    position: absolute;
    top: 0.55rem;
    right: 0.55rem;
    width: 1.05rem;
    height: 1.05rem;
    color: var(--color-primary);
}

@media (prefers-reduced-motion: reduce) {
    .profile-card { transition: none; }
}

.profile-applied {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.5rem 0.75rem;
    margin-top: 0.6rem;
    padding: 0.5rem 0.6rem 0.5rem 0.75rem;
    border-radius: var(--radius-sm);
    background: var(--color-info-soft);
    color: var(--color-text);
    font-size: 0.8rem;
    line-height: 1.45;
}

.profile-applied span {
    flex: 1 1 18rem;
}
</style>
