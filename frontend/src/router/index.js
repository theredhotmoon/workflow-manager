import { ref } from 'vue';
import { createRouter, createWebHistory } from 'vue-router';
import api from '../axios.js';
import AlarmsView from '../views/AlarmsView.vue';
import ChecklistBuilderView from '../views/ChecklistBuilderView.vue';
import ChecklistFillView from '../views/ChecklistFillView.vue';
import ChecklistHistoryView from '../views/ChecklistHistoryView.vue';
import DashboardView from '../views/DashboardView.vue';
import DelegationArchiveView from '../views/DelegationArchiveView.vue';
import DelegationsManageView from '../views/DelegationsManageView.vue';
import DelegationsView from '../views/DelegationsView.vue';
import LoginView from '../views/LoginView.vue';
import MyAvailabilityView from '../views/MyAvailabilityView.vue';
import MyShiftsView from '../views/MyShiftsView.vue';
import OfficeAvailabilityView from '../views/OfficeAvailabilityView.vue';
import ProcedureDetailView from '../views/ProcedureDetailView.vue';
import ProceduresListView from '../views/ProceduresListView.vue';
import ProceduresToLearnView from '../views/ProceduresToLearnView.vue';
import ProcedureTagsView from '../views/ProcedureTagsView.vue';
import ProcedureTreeEditorView from '../views/ProcedureTreeEditorView.vue';
import ScheduleView from '../views/ScheduleView.vue';
import SettlementPeriodsView from '../views/SettlementPeriodsView.vue';
import ShiftSettingsView from '../views/ShiftSettingsView.vue';
import UsersView from '../views/UsersView.vue';

// Shared reactive user state
export const currentUser = ref(null);

const routes = [
  {
    path: '/',
    redirect: '/login',
  },
  {
    path: '/login',
    name: 'login',
    component: LoginView,
    meta: { guest: true },
  },
  {
    path: '/dashboard',
    name: 'dashboard',
    component: DashboardView,
    meta: { requiresAuth: true },
  },
  {
    path: '/users',
    name: 'users',
    component: UsersView,
    meta: { requiresAuth: true, requiresAdmin: true },
  },
  {
    path: '/schedule/:month?',
    name: 'schedule',
    component: ScheduleView,
    meta: { requiresAuth: true },
  },
  {
    path: '/alarms',
    name: 'alarms',
    component: AlarmsView,
    meta: { requiresAuth: true },
  },
  {
    path: '/checklists',
    name: 'checklists',
    component: ChecklistBuilderView,
    meta: { requiresAuth: true, requiresAdmin: true },
  },
  {
    path: '/checklist/fill',
    name: 'checklist-fill',
    component: ChecklistFillView,
    meta: { requiresAuth: true },
  },
  {
    path: '/checklist/history',
    name: 'checklist-history',
    component: ChecklistHistoryView,
    meta: { requiresAuth: true, requiresAdmin: true },
  },
  {
    path: '/my-availability',
    name: 'my-availability',
    component: MyAvailabilityView,
    meta: { requiresAuth: true },
  },
  {
    path: '/my-shifts',
    name: 'my-shifts',
    component: MyShiftsView,
    meta: { requiresAuth: true },
  },
  {
    path: '/office-availability',
    name: 'office-availability',
    component: OfficeAvailabilityView,
    meta: { requiresAuth: true, requiresAdmin: true },
  },
  {
    path: '/procedure-tags',
    name: 'procedure-tags',
    component: ProcedureTagsView,
    meta: { requiresAuth: true, requiresAdmin: true },
  },
  {
    path: '/procedures',
    name: 'procedures',
    component: ProceduresListView,
    meta: { requiresAuth: true },
  },

  {
    path: '/procedures/tree-editor',
    name: 'procedure-tree-editor',
    component: ProcedureTreeEditorView,
    meta: { requiresAuth: true, requiresAdmin: true },
  },
  {
    path: '/procedures/:id',
    name: 'procedure-detail',
    component: ProcedureDetailView,
    meta: { requiresAuth: true },
  },
  {
    path: '/procedures/:id/version/:version',
    name: 'procedure-version',
    component: ProcedureDetailView,
    meta: { requiresAuth: true },
  },

  {
    path: '/procedures-to-learn',
    name: 'procedures-to-learn',
    component: ProceduresToLearnView,
    meta: { requiresAuth: true },
  },
  {
    path: '/delegations',
    name: 'delegations',
    component: DelegationsView,
    meta: { requiresAuth: true },
  },
  {
    path: '/delegations-manage',
    name: 'delegations-manage',
    component: DelegationsManageView,
    meta: { requiresAuth: true, requiresAdmin: true },
  },
  {
    path: '/delegation-archive',
    name: 'delegation-archive',
    component: DelegationArchiveView,
    meta: { requiresAuth: true, requiresAdmin: true },
  },
  {
    path: '/shift-settings',
    name: 'shift-settings',
    component: ShiftSettingsView,
    meta: { requiresAuth: true, requiresAdmin: true },
  },
  {
    path: '/settlement-periods',
    name: 'settlement-periods',
    component: SettlementPeriodsView,
    meta: { requiresAuth: true },
  },
];

const router = createRouter({
  history: createWebHistory(),
  routes,
});

router.beforeEach(async (to, from, next) => {
  if (to.meta.requiresAuth) {
    try {
      const response = await api.get('/api/user');
      currentUser.value = response.data;

      if (
        to.meta.requiresAdmin &&
        !currentUser.value.is_admin &&
        currentUser.value.role !== 'admin'
      ) {
        next({ name: 'dashboard' });
      } else {
        next();
      }
    } catch {
      currentUser.value = null;
      next({ name: 'login' });
    }
  } else if (to.meta.guest) {
    try {
      const response = await api.get('/api/user');
      currentUser.value = response.data;
      next({ name: 'dashboard' });
    } catch {
      currentUser.value = null;
      next();
    }
  } else {
    next();
  }
});

export default router;
