import { createRouter, createWebHistory } from 'vue-router';
import { useAuth } from '@/composables/useAuth';
import LoginView from '@/views/LoginView.vue';
import DashboardView from '@/views/DashboardView.vue';
import PlanningView from '@/views/PlanningView.vue';
import InstallationsView from '@/views/InstallationsView.vue';
import InstallationDetailView from '@/views/InstallationDetailView.vue';
import WorkOrderDetailView from '@/views/WorkOrderDetailView.vue';


const routes = [
    {
        path: '/login',
        name: 'login',
        component: LoginView,
        meta: { guestOnly: true },
    },
    {
        path: '/',
        name: 'dashboard',
        component: DashboardView,
        meta: { requiresAuth: true },
    },
    {
        path: '/planning',
        name: 'planning',
        component: PlanningView,
        meta: { requiresAuth: true },
    },
    {
        path: '/installations',
        name: 'installations',
        component: InstallationsView,
        meta: { requiresAuth: true, ownerOnly: true },
    },
    {
        path: '/installations/:id',
        name: 'installation.detail',
        component: InstallationDetailView,
        meta: { requiresAuth: true },
    },
    {
        path: '/work-orders/:id',
        name: 'work-order.detail',
        component: WorkOrderDetailView,
        meta: { requiresAuth: true },
    },
    {
        path: '/:pathMatch(.*)*',
        redirect: '/',
    },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
});

router.beforeEach((to) => {
    const { isAuthenticated, isOwner } = useAuth();

    if (to.meta.requiresAuth && !isAuthenticated.value) {
        return { name: 'login' };
    }
    if (to.meta.guestOnly && isAuthenticated.value) {
        return { name: 'dashboard' };
    }
    if (to.meta.ownerOnly && !isOwner.value) {
        return { name: 'dashboard' };
    }
});

export default router;