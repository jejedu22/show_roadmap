import { createRouter, createWebHistory } from 'vue-router'
import { session, loadConfig, setUnauthorizedHandler, can } from './api.js'

const routes = [
  { path: '/login', component: () => import('./views/LoginView.vue'), meta: { public: true, bare: true } },
  { path: '/', component: () => import('./views/HomeView.vue') },
  { path: '/companies', component: () => import('./views/HomeView.vue'), props: { stay: true } },
  { path: '/account', component: () => import('./views/AccountView.vue') },
  { path: '/admin/users', component: () => import('./views/admin/UsersView.vue'), meta: { perm: 'users.manage' } },
  { path: '/admin/groups', component: () => import('./views/admin/GroupsView.vue'), meta: { perm: 'groups.manage' } },
  { path: '/admin/lists', component: () => import('./views/admin/ListsView.vue'), meta: { perm: 'lists.manage' } },
  { path: '/admin/audit', component: () => import('./views/admin/AuditView.vue'), meta: { perm: 'users.manage' } },
  { path: '/mot-de-passe/:token', component: () => import('./views/PasswordTokenView.vue'), props: true, meta: { public: true, bare: true, anyone: true } },
  { path: '/companies/:id', component: () => import('./views/DashboardView.vue'), props: true },
  { path: '/companies/:id/calendar', component: () => import('./views/CalendarView.vue'), props: true },
  { path: '/companies/:id/shows', component: () => import('./views/ShowsView.vue'), props: true },
  { path: '/companies/:id/people', component: () => import('./views/PeopleView.vue'), props: true },
  { path: '/companies/:id/import', component: () => import('./views/ImportView.vue'), props: true },
  { path: '/companies/:id/settings', component: () => import('./views/SettingsView.vue'), props: true },
  { path: '/events/:id/print/:doc', component: () => import('./views/PrintView.vue'), props: true, meta: { bare: true } },
  {
    path: '/events/:id', component: () => import('./views/EventView.vue'), props: true,
    children: [
      { path: '', component: () => import('./views/event/OverviewTab.vue'), meta: { step: 'info' } },
      { path: 'team', component: () => import('./views/event/TeamTab.vue'), meta: { step: 'team' } },
      { path: 'stay', component: () => import('./views/event/StayTab.vue'), meta: { step: 'stay' } },
      { path: 'travel', component: () => import('./views/event/TravelTab.vue'), meta: { step: 'travel' } },
      { path: 'quote', component: () => import('./views/event/QuoteTab.vue'), meta: { step: 'quote' } },
      { path: 'roadmap', component: () => import('./views/event/RoadmapTab.vue'), meta: { step: 'roadmap' } },
    ],
  },
  { path: '/:pathMatch(.*)*', redirect: '/' },
]

const router = createRouter({ history: createWebHistory(), routes, scrollBehavior: () => ({ top: 0 }) })

router.beforeEach(async (to) => {
  if (!session.loaded) await loadConfig()
  if (!to.meta.public && !session.user) return { path: '/login' }
  if (to.path === '/login' && session.user) return { path: '/' }
  if (to.meta.perm && !can(to.meta.perm)) return { path: '/' }
})

setUnauthorizedHandler(() => router.push('/login'))

export default router
