import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const router = createRouter({
  history: createWebHistory(),
  routes: [
   { path: '/', name: 'feed', component: () => import('../views/FeedView.vue') },                       // ch. 4, 8
    { path: '/login', name: 'login', component: () => import('../views/auth/LoginView.vue'), meta: { guestOnly: true } },
    { path: '/register', name: 'register', component: () => import('../views/auth/RegisterView.vue'), meta: { guestOnly: true } },
    { path: '/forgot-password', name: 'forgot-password', component: () => import('../views/auth/ForgotPasswordView.vue'), meta: { guestOnly: true } },
    { path: '/reset-password/:token', name: 'reset-password', component: () => import('../views/auth/ResetPasswordView.vue'), meta: { guestOnly: true } },
    { path: '/verify-email', name: 'verify-email', component: () => import('../views/auth/VerifyEmailView.vue'), meta: { requiresAuth: true } },
    // { path: '/users/:id(\\d+)', name: 'profile', component: () => import('../views/users/ProfileView.vue') },           // ch. 3
    // { path: '/settings/profile', name: 'edit-profile', component: () => import('../views/users/EditProfileView.vue'), meta: { requiresAuth: true } }, // ch. 12
    // { path: '/notifications', name: 'notifications', component: () => import('../views/notifications/NotificationsView.vue'), meta: { requiresAuth: true } }, // ch. 10
    // { path: '/search', name: 'search', component: () => import('../views/SearchView.vue') },             // ch. 11
    // { path: '/:pathMatch(.*)*', name: 'not-found', component: () => import('../views/NotFoundView.vue') },
  ],
  scrollBehavior: () => ({ top: 0 }),
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()
  if (!auth.initialized) await auth.fetchUser()

  if (to.meta.requiresAuth && !auth.user) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }
  if (to.meta.guestOnly && auth.user) return { name: 'feed' }
})

export default router