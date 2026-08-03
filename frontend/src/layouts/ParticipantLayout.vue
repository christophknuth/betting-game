<template>
  <div class="shell">
    <NotificationHost />

    <nav class="topbar">
      <div class="topbar-inner">
        <!--
          The brand, not a heading. It used to be the h1 of every page, which
          left each view's own title as an h2 and gave the document no
          top-level heading of its own - five pages all called
          "Tippgemeinschaft" to anything reading the outline.
        -->
        <p class="logo">
          🎲 Tippgemeinschaft
        </p>

        <div class="nav-links">
          <!-- E1-01: nur solange der Zugang noch kein Teilnehmer ist — danach
               gibt es dort nichts mehr zu tun. -->
          <router-link
            v-if="!authStore.participantId"
            to="/register"
          >
            Mitspielen
          </router-link>
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

/*
 * On a phone the bar wrapped into three stacked rows, and the user menu's left
 * border - meant to separate it from the links beside it - ran across the full
 * width as a stray line. The admin area had this treatment from the start;
 * this is the half people actually open on a phone.
 */
@media (width <= 820px) {
  .topbar-inner {
    gap: 0.5rem 1rem;
    padding: 0.625rem 20px;
  }

  .logo {
    font-size: 1.0625rem;
  }

  /* A scrolling strip rather than three lines of wrapping links */
  .nav-links {
    order: 3;
    width: 100%;
    flex-wrap: nowrap;
    overflow-x: auto;
    gap: 1.25rem;
    padding-bottom: 0.25rem;
  }

  .nav-links a {
    white-space: nowrap;
  }

  .user-menu {
    margin-left: auto;
    padding-left: 0;
    border-left: none;
    gap: 0.75rem;
  }

  /* Of the two names in that row, the one that can go is the one that
     repeats: the display name is on every screen anyway. */
  .username {
    display: none;
  }

  .content {
    margin: 1.5rem auto;
  }
}
</style>
