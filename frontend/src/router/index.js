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
      // nor a user to show in it. Nobody is sent here on the way in any more -
      // the guard below goes straight to Keycloak. This is where a logout
      // lands, and it stays reachable by hand.
      path: '/login',
      name: 'Login',
      meta: { title: 'Anmelden' },
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
          // E1-01: the one participant route that needs no participant. Under
          // the same layout on purpose - somebody who has just registered is a
          // participant-to-be, not a special case with its own chrome.
          path: 'register',
          name: 'Register',
          meta: { title: 'Mitspielen' },
          component: () => import('@/views/RegisterView.vue')
        },
        {
          path: 'bet-row',
          name: 'BetRow',
          meta: { title: 'Meine Tippreihe' },
          component: () => import('@/views/BetRowView.vue')
        },
        {
          path: 'memberships',
          name: 'Memberships',
          meta: { title: 'Meine Teilnahmen' },
          component: () => import('@/views/MembershipsView.vue')
        },
        {
          path: 'fees',
          name: 'Fees',
          meta: { title: 'Meine Gebühren' },
          component: () => import('@/views/FeesView.vue')
        },
        {
          path: 'payout-share',
          name: 'PayoutShare',
          meta: { title: 'Mein Gewinnanteil' },
          component: () => import('@/views/PayoutShareView.vue')
        },
        {
          path: 'draws',
          name: 'Draws',
          meta: { title: 'Ziehungen' },
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
          meta: { title: 'Tippjahre' },
          component: () => import('@/views/AdminTippYearsView.vue')
        },
        {
          // The year itself, not a panel under the list. Constrained to digits
          // so a mistyped path is the not-found page rather than a view asking
          // the API for tipp year NaN.
          path: 'tipp-years/:tippYearId(\\d+)',
          name: 'AdminTippYear',
          meta: { title: 'Tippjahr' },
          component: () => import('@/views/AdminTippYearView.vue')
        },
        {
          path: 'participants',
          name: 'AdminParticipants',
          meta: { title: 'Teilnehmer' },
          component: () => import('@/views/AdminParticipantsView.vue')
        },
        {
          path: 'bet-rows',
          name: 'AdminBetRows',
          meta: { title: 'Tippreihe zuordnen' },
          component: () => import('@/views/AdminBetRowsView.vue')
        },
        {
          path: 'draws',
          name: 'AdminDraws',
          meta: { title: 'Ziehungen' },
          component: () => import('@/views/AdminDrawsView.vue')
        },
        {
          path: 'fees',
          name: 'AdminFees',
          meta: { title: 'Gebühren' },
          component: () => import('@/views/AdminFeesView.vue')
        },
        {
          path: 'operations',
          name: 'Operations',
          meta: { title: 'Betrieb' },
          component: () => import('@/views/AdminOperationsView.vue')
        }
      ]
    },

    // Anything else is a dead link - not least every URL of the old sports
    // betting SPA, which this application no longer has an endpoint for. It
    // used to redirect home without a word, which moved people to a page they
    // had not asked for and hid that the address was wrong.
    {
      path: '/:pathMatch(.*)*',
      name: 'NotFound',
      component: () => import('@/layouts/ParticipantLayout.vue'),
      meta: { requiresAuth: true },
      children: [
        {
          path: '',
          name: 'NotFoundPage',
          meta: { title: 'Seite nicht gefunden' },
          component: () => import('@/views/NotFoundView.vue')
        }
      ]
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
//
// The decision is *returned*, not handed to a `next` callback: Vue Router 5
// deprecates that third argument (VUE_ROUTER_R0025), and every guarded
// navigation printed a warning for it. Returning also removes the one way the
// callback form can go wrong - calling `next` twice, or forgetting it on a
// branch and hanging the navigation - because a branch that returns nothing
// still means "carry on".
router.beforeEach(async to => {
  const authStore = useAuthStore()

  await authStore.ready()

  if (to.meta.requiresAuth && !authStore.isAuthenticated) {
    // Straight to Keycloak, with no page of our own in between. The one that
    // used to sit here carried a single button and nothing a member could act
    // on. Saving the click by embedding Keycloak's form is not an option -
    // it refuses to be framed, and that refusal is what protects the password
    // field - so the detour is made invisible instead.
    //
    // Naming the target as the redirect URI is what keeps a deep link alive
    // across it: Keycloak returns to the route that was asked for, not to
    // whichever page the redirect happened to start from.
    authStore.login({ redirectUri: window.location.origin + to.fullPath })

    // Abort rather than route somewhere: the browser is leaving this document.
    // Rendering another view first would show a flash of a page the visitor is
    // not allowed to see.
    return false
  }

  if (to.meta.requiresAdmin && !authStore.isAdmin()) {
    // The guard only hides the entrance. The API checks the role itself on
    // every admin route, which is where the decision actually is.
    return HOME
  }

  if (to.path === '/login' && authStore.isAuthenticated) {
    return HOME
  }

  return true
})

/**
 * The tab said "Tippgemeinschaft – Lotto 6 aus 49" on every page, which with
 * several tabs open told nobody which one was the fee ledger.
 *
 * After the navigation rather than before it: a route that the guard sends
 * somewhere else must not leave its title behind on the page one actually
 * lands on.
 */
router.afterEach(to => {
  document.title = to.meta.title
    ? `${to.meta.title} – Tippgemeinschaft`
    : 'Tippgemeinschaft – Lotto 6 aus 49'
})

export default router
