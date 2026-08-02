<template>
  <!--
    Above the top bar rather than in the page: the bar is sticky with z-index
    100, so anything meant to be seen over it has to outrank it.
  -->
  <div
    class="host"
    aria-live="polite"
  >
    <TransitionGroup name="drop">
      <div
        v-for="item in store.items"
        :key="item.id"
        class="notification"
        :class="item.type"
      >
        <span class="message">{{ item.message }}</span>
        <button
          type="button"
          class="close"
          aria-label="Meldung schließen"
          @click="store.dismiss(item.id)"
        >
          ×
        </button>
      </div>
    </TransitionGroup>
  </div>
</template>

<script setup>
import { useNotificationStore } from '@/stores/notifications'

/**
 * Where the answers to writes appear.
 *
 * Rendered once per layout, at the very top and over the navigation. The
 * region is `polite` rather than `assertive` for errors too: the person who
 * pressed the button is looking at the form, an interruption buys nothing, and
 * an error stays up until it is dismissed anyway.
 */
const store = useNotificationStore()
</script>

<style scoped>
.host {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  z-index: 1000;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.5rem;
  padding: 0.75rem 20px;

  /* The bar underneath has to stay clickable between the notifications */
  pointer-events: none;
}

.notification {
  pointer-events: auto;
  display: flex;
  align-items: flex-start;
  gap: 1rem;
  width: 100%;
  max-width: 32rem;
  padding: 0.75rem 1rem;
  border-radius: 8px;
  border: 1px solid;
  box-shadow: 0 4px 12px rgb(0 0 0 / 15%);
  font-size: 0.9375rem;
}

.notification.success {
  background: #d1fae5;
  border-color: #6ee7b7;
  color: #065f46;
}

.notification.error {
  background: #fee2e2;
  border-color: #fca5a5;
  color: var(--red-dark);
}

.message {
  flex: 1;
}

.close {
  border: none;
  background: none;
  color: inherit;
  font-size: 1.25rem;
  line-height: 1;
  cursor: pointer;
  padding: 0;
}

.drop-enter-active,
.drop-leave-active {
  transition: opacity 0.2s, transform 0.2s;
}

.drop-enter-from,
.drop-leave-to {
  opacity: 0;
  transform: translateY(-0.5rem);
}

/* Something sliding in at the top of the screen is exactly what this setting
   is about, so it moves not at all rather than a little less. */
@media (prefers-reduced-motion: reduce) {
  .drop-enter-active,
  .drop-leave-active {
    transition: opacity 0.2s;
  }

  .drop-enter-from,
  .drop-leave-to {
    transform: none;
  }
}
</style>
