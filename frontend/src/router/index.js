import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const HOME = '/bet-row'
const ADMIN_HOME = '/admin/tipp-years'

// Two layouts, two areas. The paths are unchanged - every bookmark, every
// link in the docs and every E2E test still points where it did. What changed
// is which chrome wraps them: participant routes get the light top bar,
// /admin/* gets the dark bar and the sidebar.
//
// `meta` on a parent is merged into its children by Vue Router, so the guard
// below still reads `to.meta.requiresAdmin` on the leaf.
const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      // No layout: the login page is the one screen with neither navigation
      // nor a user to show in it.
      path: '/login',
      name: 'Login',
      component: () => import('@/views/LoginView.vue')
    },

    // --- Participant, read only (B-01 to B-05) ---

    {
      path: '/',
      component: () => import('@/layouts/ParticipantLayout.vue'),
      meta: { requiresAuth: true },
      children: [
        {
          path: '',
          redirect: HOME
        },
        {
          path: 'bet-row',
          name: 'BetRow',
          component: () => import('@/views/BetRowView.vue')
        },
        {
          path: 'memberships',
          name: 'Memberships',
          component: () => import('@/views/MembershipsView.vue')
        },
        {
          path: 'fees',
          name: 'Fees',
          component: () => import('@/views/FeesView.vue')
        },
        {
          path: 'payout-share',
          name: 'PayoutShare',
          component: () => import('@/views/PayoutShareView.vue')
        },
        {
          path: 'draws',
          name: 'Draws',
          component: () => import('@/views/DrawsView.vue')
        }
      ]
    },

    // --- Admin (B-06 to B-14, B-18, B-21, OPS-01, OPS-03, OPS-04) ---

    {
      path: '/admin',
      component: () => import('@/layouts/AdminLayout.vue'),
      meta: { requiresAuth: true, requiresAdmin: true },
      children: [
        {
          path: '',
          redirect: ADMIN_HOME
        },
        {
          path: 'tipp-years',
          name: 'AdminTippYears',
          component: () => import('@/views/AdminTippYearsView.vue')
        },
        {
          path: 'participants',
          name: 'AdminParticipants',
          component: () => import('@/views/AdminParticipantsView.vue')
        },
        {
          path: 'bet-rows',
          name: 'AdminBetRows',
          component: () => import('@/views/AdminBetRowsView.vue')
        },
        {
          path: 'draws',
          name: 'AdminDraws',
          component: () => import('@/views/AdminDrawsView.vue')
        },
        {
          path: 'fees',
          name: 'AdminFees',
          component: () => import('@/views/AdminFeesView.vue')
        },
        {
          path: 'operations',
          name: 'Operations',
          component: () => import('@/views/AdminOperationsView.vue')
        }
      ]
    },

    // Anything else is a dead link - not least every URL of the old sports
    // betting SPA, which this application no longer has an endpoint for.
    {
      path: '/:pathMatch(.*)*',
      redirect: HOME
    }
  ]
})

// Navigation guard
//
// Async because the very first navigation starts inside `app.use(router)`,
// before main.js has awaited the Keycloak bootstrap. Deciding at that point
// meant judging an authenticated user as anonymous: the guard sent the
// requested route to /login, and by the time the session was restored the
// original target was gone - every deep link and every reload of a protected
// page ended up on HOME. Awaiting the store's `ready()` makes the guard judge
// the session it is actually about to render.
router.beforeEach(async (to, from, next) => {
  const authStore = useAuthStore()

  await authStore.ready()

  if (to.meta.requiresAuth && !authStore.isAuthenticated) {
    next('/login')
  } else if (to.meta.requiresAdmin && !authStore.isAdmin()) {
    // The guard only hides the entrance. The API checks the role itself on
    // every admin route, which is where the decision actually is.
    next(HOME)
  } else if (to.path === '/login' && authStore.isAuthenticated) {
    next(HOME)
  } else {
    next()
  }
})

export default router
