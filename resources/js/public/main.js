import './app.css';
import { createApp } from 'vue';
import { createRouter, createWebHistory } from 'vue-router';
import App from './App.vue';
import BranchSelector from './views/BranchSelector.vue';
import MenuHome from './views/MenuHome.vue';
import Cart from './views/Cart.vue';
import Checkout from './views/Checkout.vue';
import Confirmed from './views/Confirmed.vue';

const router = createRouter({
    history: createWebHistory('/menu'),
    routes: [
        { path: '/:tenantSlug', name: 'branches', component: BranchSelector },
        { path: '/:tenantSlug/s/:branchId', name: 'menu', component: MenuHome },
        { path: '/:tenantSlug/s/:branchId/cart', name: 'cart', component: Cart },
        { path: '/:tenantSlug/s/:branchId/checkout', name: 'checkout', component: Checkout },
        { path: '/:tenantSlug/s/:branchId/ok/:saleId', name: 'confirmed', component: Confirmed },
    ],
    scrollBehavior: () => ({ top: 0 }),
});

createApp(App).use(router).mount('#public-app');
