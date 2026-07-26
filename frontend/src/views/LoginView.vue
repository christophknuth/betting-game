<template>
  <div class="login-container">
    <div class="login-card">
      <h1>🎯 Betting Game</h1>
      <p class="subtitle">Secure Authentication with Keycloak</p>

      <div v-if="loading" class="loading-state">
        <div class="spinner"></div>
        <p>Initializing...</p>
      </div>

      <div v-else>
        <div class="info-box">
          <p><strong>Authentication via Keycloak</strong></p>
          <p>Click the button below to login securely through Keycloak.</p>
        </div>

        <button @click="handleLogin" class="btn-login" :disabled="loggingIn">
          {{ loggingIn ? 'Redirecting...' : 'Login with Keycloak' }}
        </button>

        <div v-if="error" class="error-message">
          {{ error }}
        </div>

        <div class="demo-credentials">
          <p><strong>Demo Credentials:</strong></p>
          <ul>
            <li>
              <strong>Admin:</strong> admin / admin123
              <span class="role-badge admin">Admin</span>
            </li>
            <li>
              <strong>Test User:</strong> testuser / test123
              <span class="role-badge user">User</span>
            </li>
            <li>
              <strong>John Doe:</strong> john.doe / password
              <span class="role-badge user">User</span>
            </li>
          </ul>
        </div>

        <div class="keycloak-info">
          <p>Powered by <strong>Keycloak</strong> - Open Source Identity and Access Management</p>
          <p class="small">
            <a href="http://localhost:8090/admin/master/console/#/betting-game" target="_blank">
              Keycloak Admin Console
            </a> (admin/admin)
          </p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const authStore = useAuthStore()

const loading = ref(true)
const loggingIn = ref(false)
const error = ref(null)

onMounted(async () => {
  // Check if already authenticated
  if (authStore.isAuthenticated) {
    router.push('/predictions')
  }
  loading.value = false
})

const handleLogin = async () => {
  loggingIn.value = true
  error.value = null

  try {
    await authStore.login()
  } catch (err) {
    error.value = 'Login failed. Please try again.'
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

.loading-state {
  text-align: center;
  padding: 2rem 0;
}

.spinner {
  width: 50px;
  height: 50px;
  margin: 0 auto 1rem;
  border: 4px solid #f3f4f6;
  border-top: 4px solid #2563eb;
  border-radius: 50%;
  animation: spin 1s linear infinite;
}

@keyframes spin {
  0% { transform: rotate(0deg); }
  100% { transform: rotate(360deg); }
}

.info-box {
  padding: 1rem;
  background: #f0f9ff;
  border: 1px solid #bae6fd;
  border-radius: 6px;
  margin-bottom: 1.5rem;
}

.info-box p {
  margin: 0.5rem 0;
  color: #0c4a6e;
  font-size: 0.875rem;
}

.info-box strong {
  color: #0369a1;
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

.demo-credentials {
  margin-top: 2rem;
  padding: 1rem;
  background: #f9fafb;
  border-radius: 6px;
  font-size: 0.875rem;
}

.demo-credentials p {
  margin: 0 0 0.5rem 0;
  color: #666;
}

.demo-credentials strong {
  color: #2563eb;
}

.demo-credentials ul {
  margin: 0.5rem 0 0 0;
  padding-left: 1.5rem;
}

.demo-credentials li {
  margin: 0.5rem 0;
  color: #374151;
}

.role-badge {
  display: inline-block;
  padding: 0.125rem 0.5rem;
  border-radius: 12px;
  font-size: 0.75rem;
  font-weight: 600;
  margin-left: 0.5rem;
}

.role-badge.admin {
  background: #fef3c7;
  color: #92400e;
}

.role-badge.user {
  background: #dbeafe;
  color: #1e40af;
}

.keycloak-info {
  margin-top: 1.5rem;
  padding-top: 1.5rem;
  border-top: 1px solid #e5e7eb;
  text-align: center;
  font-size: 0.875rem;
  color: #6b7280;
}

.keycloak-info strong {
  color: #2563eb;
}

.keycloak-info .small {
  margin-top: 0.5rem;
  font-size: 0.75rem;
}

.keycloak-info a {
  color: #2563eb;
  text-decoration: none;
}

.keycloak-info a:hover {
  text-decoration: underline;
}
</style>
