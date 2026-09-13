<script setup>
defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, required: true },
    message: { type: String, required: true },
    confirmLabel: { type: String, default: 'Confirmar' },
    danger: { type: Boolean, default: false },
    processing: { type: Boolean, default: false },
});

defineEmits(['confirm', 'cancel']);
</script>

<template>
    <div v-if="open" class="modal-backdrop" @click.self="$emit('cancel')">
        <div class="modal-card" role="dialog" aria-modal="true">
            <h2 class="modal-title">{{ title }}</h2>
            <p class="modal-message">{{ message }}</p>
            <div class="modal-actions">
                <button type="button" class="btn btn-ghost" @click="$emit('cancel')">Cancelar</button>
                <button
                    type="button"
                    class="btn"
                    :class="danger ? 'btn-danger' : 'btn-primary'"
                    :disabled="processing"
                    @click="$emit('confirm')"
                >
                    {{ confirmLabel }}
                </button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.45);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 100;
}

.modal-card {
    background: var(--color-surface);
    border-radius: var(--radius-md, 10px);
    padding: 1.25rem 1.4rem;
    max-width: 420px;
    width: calc(100% - 2rem);
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.25);
}

.modal-title {
    margin: 0 0 0.5rem;
    font-size: 1rem;
    font-weight: 700;
}

.modal-message {
    margin: 0 0 1.1rem;
    font-size: 0.85rem;
    color: var(--color-text-muted);
}

.modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 0.5rem;
}

.btn-danger {
    background: var(--color-danger, #c0392b);
    color: #fff;
}
</style>
