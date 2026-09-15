import { setUnauthorizedHandler } from '@/api/http';
import { useAuth } from '@/composables/useAuth';
import { createRouter, createWebHistory } from 'vue-router';

export const router = createRouter({
    history: createWebHistory(),
    routes: [
        { path: '/', redirect: { name: 'settings' } },
        { path: '/login', name: 'login', component: () => import('@/pages/LoginPage.vue'), meta: { guest: true } },
        { path: '/settings', name: 'settings', component: () => import('@/pages/SettingsPage.vue') },
        {
            path: '/organizations/:id(\\d+)',
            name: 'organization',
            component: () => import('@/pages/OrganizationPage.vue'),
            props: (route) => ({ id: Number(route.params.id) }),
        },
        { path: '/:pathMatch(.*)*', redirect: { name: 'settings' } },
    ],
});

router.beforeEach(async (to) => {
    const auth = useAuth();
    await auth.loadUser();

    if (to.meta.guest) {
        return auth.isAuthenticated.value ? { name: 'settings' } : true;
    }

    return auth.isAuthenticated.value ? true : { name: 'login', query: { redirect: to.fullPath } };
});

setUnauthorizedHandler(() => {
    useAuth().forget();
    const current = router.currentRoute.value;
    if (current.name !== 'login') {
        router.push({ name: 'login', query: { redirect: current.fullPath } });
    }
});
