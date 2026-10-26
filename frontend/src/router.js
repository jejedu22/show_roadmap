import { createRouter, createWebHistory } from 'vue-router'
import { session, loadConfig, setUnauthorizedHandler } from './api.js'

const routes = [
  { path: '/login', component: () => import('./views/LoginView.vue'), meta: { public: true } },
  { path: '/', component: () => import('./views/HomeView.vue') },
  { path: '/account', component: () => import('./views/AccountView.vue') },
  { path: '/admin/users', component: () => import('./views/AdminUsersView.vue') },
  { path: '/companies/:id', component: () => import('./views/CompanyView.vue'), props: true },
  { path: '/companies/:id/people', component: () => import('./views/PeopleView.vue'), props: true },
  { path: '/events/:id/print/:doc', component: () => import('./views/PrintView.vue'), props: true, meta: { bare: true } },
  {
    path: '/events/:id', component: () => import('./views/EventView.vue'), props: true,
    children: [
      { path: '', component: () => import('./views/event/OverviewTab.vue') },
      { path: 'team', component: () => import('./views/event/TeamTab.vue') },
      { path: 'stay', component: () => import('./views/event/StayTab.vue') },
      { path: 'travel', component: () => import('./views/event/TravelTab.vue') },
      { path: 'quote', component: () => import('./views/event/QuoteTab.vue') },
      { path: 'roadmap', component: () => import('./views/event/RoadmapTab.vue') },
    ],
  },
  { path: '/:pathMatch(.*)*', redirect: '/' },
]

const router = createRouter({ history: createWebHistory(), routes })

router.beforeEach(async (to) => {
  if (!session.loaded) await loadConfig()
  if (!to.meta.public && !session.user) return { path: '/login' }
  if (to.path === '/login' && session.user) return { path: '/' }
})

setUnauthorizedHandler(() => router.push('/login'))

export default router
