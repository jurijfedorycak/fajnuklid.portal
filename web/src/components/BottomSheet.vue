<script setup>
import { ref, watch, nextTick, onBeforeUnmount } from 'vue'
import { X } from 'lucide-vue-next'

const props = defineProps({
  show: { type: Boolean, default: false },
  title: { type: String, default: '' },
})

const emit = defineEmits(['close'])

const panelRef = ref(null)
let lastFocused = null

function close() {
  emit('close')
}

function onBackdropClick(e) {
  if (e.target === e.currentTarget) close()
}

function trapFocus(e) {
  const panel = panelRef.value
  if (!panel) return
  const focusables = panel.querySelectorAll(
    'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
  )
  if (!focusables.length) {
    e.preventDefault()
    return
  }
  const first = focusables[0]
  const last = focusables[focusables.length - 1]
  if (!panel.contains(document.activeElement)) {
    e.preventDefault()
    first.focus()
  } else if (e.shiftKey && document.activeElement === first) {
    e.preventDefault()
    last.focus()
  } else if (!e.shiftKey && document.activeElement === last) {
    e.preventDefault()
    first.focus()
  }
}

function onKeydown(e) {
  if (e.key === 'Escape') close()
  else if (e.key === 'Tab') trapFocus(e)
}

watch(() => props.show, async (val) => {
  if (val) {
    lastFocused = document.activeElement
    document.addEventListener('keydown', onKeydown)
    document.body.style.overflow = 'hidden'
    await nextTick()
    panelRef.value?.focus()
  } else {
    document.removeEventListener('keydown', onKeydown)
    document.body.style.overflow = ''
    if (lastFocused && typeof lastFocused.focus === 'function') lastFocused.focus()
    lastFocused = null
  }
}, { immediate: true })

onBeforeUnmount(() => {
  document.removeEventListener('keydown', onKeydown)
  document.body.style.overflow = ''
})
</script>

<template>
  <Teleport to="body">
    <Transition name="sheet">
      <div v-if="show" id="bottom-sheet-backdrop" class="sheet-backdrop" @click="onBackdropClick">
        <div
          id="bottom-sheet-panel"
          ref="panelRef"
          class="sheet-panel"
          role="dialog"
          aria-modal="true"
          aria-labelledby="bottom-sheet-title"
          tabindex="-1"
        >
          <div id="bottom-sheet-handle" class="sheet-handle" aria-hidden="true"></div>
          <header id="bottom-sheet-header" class="sheet-header">
            <h2 id="bottom-sheet-title" class="sheet-title">{{ title }}</h2>
            <button id="bottom-sheet-close" class="sheet-close" aria-label="Zavřít" @click="close">
              <X :size="18" />
            </button>
          </header>
          <div id="bottom-sheet-body" class="sheet-body">
            <slot />
          </div>
          <footer v-if="$slots.footer" id="bottom-sheet-footer" class="sheet-footer">
            <slot name="footer" />
          </footer>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.sheet-backdrop {
  position: fixed;
  inset: 0;
  background: var(--color-overlay);
  z-index: 9999;
  display: flex;
  align-items: flex-end;
}

.sheet-panel {
  background: var(--color-white);
  width: 100%;
  border-radius: 20px 20px 0 0;
  max-height: 85vh;
  display: flex;
  flex-direction: column;
  padding-bottom: calc(16px + env(safe-area-inset-bottom, 0px));
}
.sheet-panel:focus {
  outline: none;
}

.sheet-handle {
  width: 36px;
  height: 4px;
  border-radius: var(--radius-pill);
  background: var(--color-gray-300);
  margin: 10px auto 0;
  flex-shrink: 0;
}

.sheet-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 10px 20px 6px;
  flex-shrink: 0;
}

.sheet-title {
  font-size: 17px;
  font-weight: 700;
  color: var(--color-primary);
}

.sheet-close {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  border: none;
  border-radius: 50%;
  background: var(--color-gray-100);
  color: var(--color-gray-600);
  cursor: pointer;
  transition: var(--transition);
}
.sheet-close:hover {
  background: var(--color-gray-200);
  color: var(--color-gray-900);
}

.sheet-body {
  overflow-y: auto;
  padding: 6px 20px 10px;
  min-height: 0;
}

.sheet-footer {
  padding: 10px 20px 0;
  flex-shrink: 0;
}

/* Enter/leave: backdrop fades, panel slides up from bottom */
.sheet-enter-active,
.sheet-leave-active {
  transition: opacity 0.25s ease;
}
.sheet-enter-active .sheet-panel,
.sheet-leave-active .sheet-panel {
  transition: transform 0.25s ease;
}
.sheet-enter-from,
.sheet-leave-to {
  opacity: 0;
}
.sheet-enter-from .sheet-panel,
.sheet-leave-to .sheet-panel {
  transform: translateY(100%);
}

@media (min-width: 768px) {
  .sheet-backdrop {
    align-items: center;
    justify-content: center;
    padding: 24px;
  }
  .sheet-panel {
    max-width: 420px;
    border-radius: var(--radius-xl);
    padding-bottom: 16px;
  }
  .sheet-handle {
    display: none;
  }
  .sheet-header {
    padding-top: 16px;
  }
  .sheet-enter-from .sheet-panel,
  .sheet-leave-to .sheet-panel {
    transform: translateY(12px) scale(0.98);
  }
}

@media (prefers-reduced-motion: reduce) {
  .sheet-enter-active,
  .sheet-leave-active,
  .sheet-enter-active .sheet-panel,
  .sheet-leave-active .sheet-panel {
    transition: none;
  }
}
</style>
