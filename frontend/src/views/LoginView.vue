<template>
  <div class="login-container">
    <div class="login-card">
      <h1>🎲 Tippgemeinschaft</h1>
      <p class="subtitle">
        Lotto 6 aus 49
      </p>

      <p class="lead">
        Melde dich an, um deine Tippreihe, deine Gebühren und deinen Gewinnanteil zu sehen.
      </p>

      <button
        class="btn-login"
        :disabled="loggingIn"
        @click="handleLogin"
      >
        {{ loggingIn ? 'Weiterleitung …' : 'Anmelden' }}
      </button>

      <div
        v-if="error"
        class="error-message"
      >
        {{ error }}
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useAuthStore } from '@/stores/auth'

const authStore = useAuthStore()

const loggingIn = ref(false)
const error = ref(null)

/**
 * This page is the landing spot after a logout, not a station on the way in -
 * every protected route hands an anonymous visitor to Keycloak itself. So no
 * initialisation state is shown here: the navigation guard has already awaited
 * the Keycloak bootstrap before this view renders, and an already-signed-in
 * visitor never gets this far.
 */
const handleLogin = async () => {
  loggingIn.value = true
  error.value = null

  try {
    await authStore.login()
  } catch (err) {
    error.value = 'Anmeldung fehlgeschlagen. Bitte erneut versuchen.'
    console.error('Login error:', err)
  } finally {
    loggingIn.value = false
  }
}
</script>

<style scoped>
.login-container {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}

.login-card {
  background: white;
  padding: 3rem;
  border-radius: 12px;
  box-shadow: 0 20px 60px rgba(0,0,0,0.3);
  width: 100%;
  max-width: 500px;
}

h1 {
  text-align: center;
  color: #2563eb;
  margin-bottom: 0.5rem;
  font-size: 2rem;
}

.subtitle {
  text-align: center;
  color: #666;
  margin-bottom: 2rem;
}

.lead {
  text-align: center;
  color: #374151;
  margin-bottom: 1.5rem;
}

.btn-login {
  width: 100%;
  padding: 1rem;
  background: #2563eb;
  color: white;
  border: none;
  border-radius: 6px;
  font-size: 1.125rem;
  font-weight: 600;
  cursor: pointer;
  transition: background 0.2s;
}

.btn-login:hover:not(:disabled) {
  background: #1d4ed8;
}

.btn-login:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.error-message {
  margin-top: 1rem;
  padding: 0.75rem;
  background: #fee2e2;
  border: 1px solid #fca5a5;
  border-radius: 6px;
  color: #dc2626;
  text-align: center;
}
</style>
