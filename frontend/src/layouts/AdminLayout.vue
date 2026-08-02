<template>
  <div class="shell">
    <NotificationHost />

    <!--
      A dark bar and a sidebar rather than the participant area's light top
      navigation. The difference is the point: whoever is in here is writing
      for everyone, not reading their own data, and the interface should say
      so before the first click does.
    -->
    <header class="topbar">
      <div class="topbar-inner">
        <span class="badge-admin">⚙ Verwaltung</span>
        <span class="scope">Tippgemeinschaft</span>

        <div class="user-menu">
          <router-link
            to="/bet-row"
            class="to-participant"
          >
            <span aria-hidden="true">←</span> Zur Teilnehmersicht
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
    </header>

    <div class="body">
      <nav class="sidebar">
        <p class="group">
          Tippjahr
        </p>
        <router-link to="/admin/tipp-years">
          Tippjahre
        </router-link>
        <router-link to="/admin/participants">
          Teilnehmer
        </router-link>
        <router-link to="/admin/bet-rows">
          Tippreihen
        </router-link>

        <p class="group">
          Spielbetrieb
        </p>
        <router-link to="/admin/draws">
          Ziehungen
        </router-link>
        <router-link to="/admin/fees">
          Gebühren
        </router-link>

        <p class="group">
          System
        </p>
        <router-link to="/admin/operations">
          Betrieb
        </router-link>
      </nav>

      <main class="content">
        <router-view />
      </main>
    </div>
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
  display: flex;
  flex-direction: column;
}

/* --- Top bar ---------------------------------------------------------- */

.topbar {
  background: var(--gray-900);
  position: sticky;
  top: 0;
  z-index: 100;
}

.topbar-inner {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
  align-items: center;
  padding: 0.625rem 20px;
}

.badge-admin {
  color: white;
  font-weight: 600;
  font-size: 0.9375rem;
  white-space: nowrap;
}

.scope {
  color: var(--gray-400);
  font-size: 0.875rem;
}

.user-menu {
  margin-left: auto;
  display: flex;
  align-items: center;
  gap: 1rem;
}

.to-participant {
  text-decoration: none;
  font-size: 0.875rem;
  font-weight: 500;
  color: var(--gray-300);
  transition: color 0.2s;
}

.to-participant:hover {
  color: white;
}

.username {
  color: var(--gray-400);
  font-size: 0.875rem;
}

.btn-logout {
  padding: 0.3125rem 0.75rem;
  background: transparent;
  color: var(--gray-300);
  border: 1px solid var(--gray-600);
  border-radius: 6px;
  cursor: pointer;
  font-size: 0.875rem;
  font-weight: 500;
  transition: border-color 0.2s, color 0.2s;
}

.btn-logout:hover {
  border-color: var(--red);
  color: var(--red);
}

/* --- Sidebar ---------------------------------------------------------- */

.body {
  flex: 1;
  display: flex;
  align-items: flex-start;
}

.sidebar {
  flex: 0 0 220px;
  align-self: stretch;
  background: white;
  border-right: 1px solid var(--gray-300);
  padding: 1.25rem 0;
  display: flex;
  flex-direction: column;
}

.group {
  padding: 0 1.25rem;
  margin: 1.25rem 0 0.5rem;
  color: var(--gray-400);
  font-size: 0.75rem;
  font-weight: 600;
  letter-spacing: 0.05em;
  text-transform: uppercase;
}

.group:first-child {
  margin-top: 0;
}

.sidebar a {
  padding: 0.5rem 1.25rem;
  text-decoration: none;
  color: var(--gray-600);
  font-weight: 500;
  font-size: 0.9375rem;
  border-left: 3px solid transparent;
  transition: background 0.2s, color 0.2s, border-color 0.2s;
}

.sidebar a:hover {
  background: var(--gray-50);
  color: var(--gray-900);
}

.sidebar a.router-link-active {
  background: var(--gray-50);
  border-left-color: var(--blue);
  color: var(--blue);
}

.content {
  flex: 1;
  min-width: 0;
  padding: 2rem;
}

/* The sidebar becomes a scrolling strip rather than eating half a phone. */
@media (width <= 820px) {
  .body {
    flex-direction: column;
  }

  .sidebar {
    flex: none;
    width: 100%;
    flex-direction: row;
    overflow-x: auto;
    padding: 0;
    border-right: none;
    border-bottom: 1px solid var(--gray-300);
  }

  .group {
    display: none;
  }

  .sidebar a {
    white-space: nowrap;
    border-left: none;
    border-bottom: 3px solid transparent;
  }

  .sidebar a.router-link-active {
    border-left-color: transparent;
    border-bottom-color: var(--blue);
  }

  .content {
    padding: 1.5rem 20px;
    width: 100%;
  }
}
</style>
