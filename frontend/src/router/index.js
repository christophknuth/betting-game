import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const HOME = '/bet-row'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/',
      redirect: HOME
    },
    {
      path: '/login',
      name: 'Login',
      component: () => import('@/views/LoginView.vue')
    },

    // --- Participant, read only (B-01 to B-05) ---

    {
      path: '/bet-row',
      name: 'BetRow',
      component: () => import('@/views/BetRowView.vue'),
      meta: { requiresAuth: true }
    },
    {
      path: '/memberships',
      name: 'Memberships',
      component: () => import('@/views/MembershipsView.vue'),
      meta: { requiresAuth: true }
    },
    {
      path: '/fees',
      name: 'Fees',
      component: () => import('@/views/FeesView.vue'),
      meta: { requiresAuth: true }
    },
    {
      path: '/payout-share',
      name: 'PayoutShare',
      component: () => import('@/views/PayoutShareView.vue'),
      meta: { requiresAuth: true }
    },
    {
      path: '/draws',
      name: 'Draws',
      component: () => import('@/views/DrawsView.vue'),
      meta: { requiresAuth: true }
    },

    // --- Admin (B-06 to B-14, OPS-01, OPS-03, OPS-04) ---

    {
      path: '/admin/tipp-years',
      name: 'AdminTippYears',
      component: () => import('@/views/AdminTippYearsView.vue'),
      meta: { requiresAuth: true, requiresAdmin: true }
    },
    {
      path: '/admin/bet-rows',
      name: 'AdminBetRows',
      component: () => import('@/views/AdminBetRowsView.vue'),
      meta: { requiresAuth: true, requiresAdmin: true }
    },
    {
      path: '/admin/draws',
      name: 'AdminDraws',
      component: () => import('@/views/AdminDrawsView.vue'),
      meta: { requiresAuth: true, requiresAdmin: true }
    },
    {
      path: '/admin/fees',
      name: 'AdminFees',
      component: () => import('@/views/AdminFeesView.vue'),
      meta: { requiresAuth: true, requiresAdmin: true }
    },
    {
      path: '/admin/operations',
      name: 'Operations',
      component: () => import('@/views/AdminOperationsView.vue'),
      meta: { requiresAuth: true, requiresAdmin: true }
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
