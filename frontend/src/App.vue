<template>
  <div class="app">
    <nav
      v-if="authStore.isAuthenticated"
      class="navbar"
    >
      <div class="container">
        <h1 class="logo">
          🎲 Tippgemeinschaft
        </h1>

        <div class="nav-links">
          <router-link to="/bet-row">
            Meine Reihe
          </router-link>
          <router-link to="/memberships">
            Teilnahmen
          </router-link>
          <router-link to="/fees">
            Gebühren
          </router-link>
          <router-link to="/payout-share">
            Gewinnanteil
          </router-link>
          <router-link to="/draws">
            Ziehungen
          </router-link>

          <template v-if="authStore.isAdmin()">
            <span class="nav-divider">|</span>
            <router-link to="/admin/tipp-years">
              Tippjahre
            </router-link>
            <router-link to="/admin/bet-rows">
              Reihen
            </router-link>
            <router-link to="/admin/draws">
              Ziehungen&nbsp;⚙
            </router-link>
            <router-link to="/admin/fees">
              Gebühren&nbsp;⚙
            </router-link>
            <router-link to="/admin/operations">
              Betrieb
            </router-link>
          </template>

          <div class="user-menu">
            <span class="username">{{ authStore.displayName }}</span>
            <button
              class="btn-logout"
              @click="logout"
            >
              Abmelden
            </button>
          </div>
        </div>
      </div>
    </nav>

    <main class="main-content">
      <router-view />
    </main>
  </div>
</template>

<script setup>
import { useAuthStore } from '@/stores/auth'
import { useRouter } from 'vue-router'

const authStore = useAuthStore()
const router = useRouter()

const logout = () => {
  authStore.logout()
  router.push('/login')
}
</script>

<style scoped>
.app {
  min-height: 100vh;
  background: #f5f5f5;
}

.navbar {
  background: white;
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
  position: sticky;
  top: 0;
  z-index: 100;
}

.container {
  max-width: 1200px;
  margin: 0 auto;
  padding: 0.75rem 20px;
  display: flex;
  flex-wrap: wrap;
  gap: 1rem;
  justify-content: space-between;
  align-items: center;
}

.logo {
  font-size: 1.25rem;
  color: #2563eb;
  margin: 0;
  white-space: nowrap;
}

.nav-links {
  display: flex;
  flex-wrap: wrap;
  gap: 1rem;
  align-items: center;
}

.nav-links a {
  text-decoration: none;
  color: #666;
  font-weight: 500;
  font-size: 0.9375rem;
  transition: color 0.2s;
}

.nav-links a:hover,
.nav-links a.router-link-active {
  color: #2563eb;
}

.nav-divider {
  color: #e5e7eb;
  font-weight: 300;
}

.user-menu {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding-left: 1rem;
  border-left: 1px solid #e5e7eb;
}

.username {
  color: #666;
  font-weight: 500;
}

.btn-logout {
  padding: 0.375rem 0.875rem;
  background: #ef4444;
  color: white;
  border: none;
  border-radius: 6px;
  cursor: pointer;
  font-weight: 500;
  transition: background 0.2s;
}

.btn-logout:hover {
  background: #dc2626;
}

.main-content {
  max-width: 1200px;
  margin: 2rem auto;
  padding: 0 20px;
}
</style>
