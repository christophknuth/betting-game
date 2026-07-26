import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/',
      redirect: '/predictions'
    },
    {
      path: '/login',
      name: 'Login',
      component: () => import('@/views/LoginView.vue')
    },
    {
      path: '/predictions',
      name: 'Predictions',
      component: () => import('@/views/PredictionsView.vue'),
      meta: { requiresAuth: true }
    },
    {
      path: '/predictions/new',
      name: 'NewPrediction',
      component: () => import('@/views/NewPredictionView.vue'),
      meta: { requiresAuth: true }
    },
    {
      path: '/predictions/:id/edit',
      name: 'EditPrediction',
      component: () => import('@/views/EditPredictionView.vue'),
      meta: { requiresAuth: true }
    },
    {
      path: '/scores',
      name: 'Scores',
      component: () => import('@/views/ScoresView.vue'),
      meta: { requiresAuth: true }
    },
    {
      path: '/games',
      name: 'Games',
      component: () => import('@/views/GamesView.vue'),
      meta: { requiresAuth: true }
    },
    {
      path: '/admin/games',
      name: 'AdminGames',
      component: () => import('@/views/AdminGamesView.vue'),
      meta: { requiresAuth: true, requiresAdmin: true }
    },
    {
      path: '/admin/predictions',
      name: 'AdminPredictions',
      component: () => import('@/views/AdminPredictionsView.vue'),
      meta: { requiresAuth: true, requiresAdmin: true }
    },
    {
      path: '/admin/results',
      name: 'AdminResults',
      component: () => import('@/views/AdminResultsView.vue'),
      meta: { requiresAuth: true, requiresAdmin: true }
    }
  ]
})

// Navigation guard
router.beforeEach((to, from, next) => {
  const authStore = useAuthStore()
  
  if (to.meta.requiresAuth && !authStore.isAuthenticated) {
    next('/login')
  } else if (to.meta.requiresAdmin && !authStore.isAdmin()) {
    next('/predictions')
  } else if (to.path === '/login' && authStore.isAuthenticated) {
    next('/predictions')
  } else {
    next()
  }
})

export default router
