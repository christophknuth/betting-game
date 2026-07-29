import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import { useAuthStore } from './stores/auth'
import './assets/app.css'

const pinia = createPinia()
const app = createApp(App)

app.use(pinia)
app.use(router)

// Initialize Keycloak before mounting the app
const authStore = useAuthStore()

authStore.initKeycloak().then(() => {
  app.mount('#app')
}).catch(error => {
  console.error('Failed to initialize Keycloak:', error)
  // Mount app anyway, but without authentication
  app.mount('#app')
})
