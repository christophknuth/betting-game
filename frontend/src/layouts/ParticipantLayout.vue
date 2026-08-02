<template>
  <div class="shell">
    <NotificationHost />

    <nav class="topbar">
      <div class="topbar-inner">
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
        </div>

        <div class="user-menu">
          <!--
            The only door into the admin area. It is a link, not a tab in the
            same bar: the two areas are separate places, and mixing them is
            what made "Ziehungen" appear twice under the same heading.
          -->
          <router-link
            v-if="authStore.isAdmin()"
            to="/admin"
            class="to-admin"
          >
            Verwaltung <span aria-hidden="true">⚙</span>
          </router-link>
          <span class="username">{{ authStore.displayName }}</span>
          <button
            class="btn-logout"
            @click="logout"
          >
            Abmelden
          </button>
        </div>
      </div>
    </nav>

    <main class="content">
      <router-view />
    </main>
  </div>
</template>

<script setup>
import NotificationHost from '@/components/NotificationHost.vue'
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
.shell {
  min-height: 100vh;
  background: var(--gray-100);
}

.topbar {
  background: white;
  box-shadow: 0 2px 4px rgb(0 0 0 / 10%);
  position: sticky;
  top: 0;
  z-index: 100;
}

.topbar-inner {
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
  color: var(--blue);
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
  color: var(--gray-600);
  font-weight: 500;
  font-size: 0.9375rem;
  transition: color 0.2s;
}

.nav-links a:hover,
.nav-links a.router-link-active {
  color: var(--blue);
}

.user-menu {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding-left: 1rem;
  border-left: 1px solid var(--gray-300);
}

.to-admin {
  text-decoration: none;
  font-size: 0.875rem;
  font-weight: 500;
  color: var(--gray-600);
  border: 1px solid var(--gray-300);
  border-radius: 6px;
  padding: 0.3125rem 0.75rem;
  transition: border-color 0.2s, color 0.2s;
}

.to-admin:hover {
  border-color: var(--blue);
  color: var(--blue);
}

.username {
  color: var(--gray-600);
  font-weight: 500;
}

.btn-logout {
  padding: 0.375rem 0.875rem;
  background: var(--red);
  color: white;
  border: none;
  border-radius: 6px;
  cursor: pointer;
  font-weight: 500;
  transition: background 0.2s;
}

.btn-logout:hover {
  background: var(--red-dark);
}

.content {
  max-width: 1200px;
  margin: 2rem auto;
  padding: 0 20px;
}
</style>
