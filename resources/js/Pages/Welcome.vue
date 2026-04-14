<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { ref, onMounted } from 'vue';

defineProps({
    canLogin: {
        type: Boolean,
    },
    canRegister: {
        type: Boolean,
    },
});

// Interactive Mock POS State
const activeFilter = ref('activas');
const mockSales = ref([
    { id: 'S-0042', time: '10:45 AM', total: '$1,250.00', status: 'active', items: 3 },
    { id: 'S-0043', time: '10:48 AM', total: '$850.50', status: 'active', items: 2 },
    { id: 'S-0041', time: '10:30 AM', total: '$4,300.00', status: 'pending', items: 8 },
]);

const filteredSales = computed(() => {
    if (activeFilter.value === 'todas') return mockSales.value;
    return mockSales.value.filter(s => s.status === activeFilter.value);
});

import { computed } from 'vue';
</script>

<template>
    <Head title="Carniceria El Puebla - Gestión de Ventas" />

    <div class="min-h-screen bg-[#050505] text-gray-300 font-inter selection:bg-red-500 selection:text-white overflow-hidden relative">
        
        <!-- Ambient Background Glows -->
        <div class="absolute top-0 left-1/2 -translate-x-1/2 w-full max-w-5xl h-[500px] opacity-30 pointer-events-none" style="background: radial-gradient(circle at 50% 0%, rgba(225, 29, 72, 0.4) 0%, rgba(0,0,0,0) 60%);"></div>

        <!-- Navigation -->
        <nav class="relative z-10 flex items-center justify-between px-6 py-6 max-w-7xl mx-auto">
            <div class="flex items-center gap-3">
                <img src="/logo.png" alt="Logo" class="h-10 w-auto rounded object-contain" onerror="this.style.display='none'" />
                <span class="font-outfit font-bold text-xl tracking-tight text-white">Carnicería El Puebla</span>
            </div>
            
            <div v-if="canLogin" class="flex gap-4">
                <Link
                    v-if="$page.props.auth.user"
                    :href="route('dashboard')"
                    class="font-medium text-sm text-gray-300 hover:text-white transition-colors"
                >
                    Dashboard
                </Link>
                <template v-else>
                    <Link
                        :href="route('login')"
                        class="font-medium text-sm text-gray-300 hover:text-white transition-colors py-2"
                    >
                        Ingresar
                    </Link>
                    <Link
                        v-if="canRegister"
                        :href="route('register')"
                        class="font-medium text-sm bg-red-600 hover:bg-red-500 text-white px-5 py-2 rounded-full transition-all shadow-[0_0_15px_rgba(220,38,38,0.4)] hover:shadow-[0_0_25px_rgba(220,38,38,0.6)]"
                    >
                        Registrarse
                    </Link>
                </template>
            </div>
        </nav>

        <main class="relative z-10 max-w-7xl mx-auto px-6 pt-20 pb-32">
            <!-- Hero Section -->
            <section class="text-center max-w-4xl mx-auto mb-32 fade-in-up">
                <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-red-950/40 border border-red-500/20 text-red-400 text-xs font-semibold uppercase tracking-wider mb-8">
                    <span class="relative flex h-2 w-2">
                      <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                      <span class="relative inline-flex rounded-full h-2 w-2 bg-red-500"></span>
                    </span>
                    El Sistema Definitivo
                </div>
                
                <h1 class="font-outfit text-5xl md:text-7xl font-extrabold text-white leading-[1.1] mb-8 tracking-tight">
                    Gestión Inteligente para <br/>
                    <span class="text-transparent bg-clip-text bg-gradient-to-r from-red-500 to-rose-400">Carnicerías Exitosas</span>
                </h1>
                
                <p class="text-lg md:text-xl text-gray-400 mb-10 max-w-2xl mx-auto leading-relaxed">
                    Controla tus sucursales, administra cortes, gestiona turnos y vende más rápido con una mesa de trabajo en tiempo real que previene colisiones.
                </p>

                <div class="flex flex-col sm:flex-row items-center justify-center gap-4">
                    <Link :href="route('login')" class="w-full sm:w-auto px-8 py-3.5 bg-white text-black font-semibold rounded-full hover:bg-gray-100 transition-all font-outfit text-lg flex items-center justify-center gap-2">
                        Acceder al Sistema
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
                    </Link>
                </div>
            </section>

            <!-- Bento Grid Features -->
            <section class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-32">
                <!-- Box 1: Multi-Branch -->
                <div class="md:col-span-2 rounded-3xl bg-gradient-to-b from-[#151515] to-[#0a0a0a] border border-white/5 p-8 md:p-12 hover:border-white/10 transition-colors group relative overflow-hidden">
                    <div class="absolute top-0 right-0 p-8 opacity-10 group-hover:opacity-20 transition-opacity">
                        <svg class="w-32 h-32 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path></svg>
                    </div>
                    <div class="relative z-10 w-full md:w-2/3">
                        <h3 class="font-outfit text-2xl font-bold text-white mb-4">Administración Multisucursal</h3>
                        <p class="text-gray-400 leading-relaxed text-lg">Centraliza tu operación. Configura tickets, asigna cajeros, administra el catálogo y supervisa retiros o cortes de caja de todas tus sucursales desde un único panel administrador.</p>
                    </div>
                </div>

                <!-- Box 2: Secure & Collaborative -->
                <div class="rounded-3xl bg-gradient-to-b from-[#151515] to-[#0a0a0a] border border-white/5 p-8 hover:border-white/10 transition-colors flex flex-col justify-between">
                    <div class="bg-red-500/10 w-14 h-14 rounded-2xl flex items-center justify-center mb-6">
                        <svg class="w-7 h-7 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 11c0 3.517-1.009 6.799-2.753 9.571m-3.44-2.04l.054-.09A13.916 13.916 0 008 11a4 4 0 118 0c0 1.017-.07 2.019-.203 3m-2.118 6.844A21.88 21.88 0 0015.171 17m3.839 1.132c.645-2.266.99-4.659.99-7.132A8 8 0 008 4.07M3 15.364c.64-1.319 1-2.8 1-4.364 0-1.457.39-2.823 1.07-4"></path></svg>
                    </div>
                    <div>
                        <h3 class="font-outfit text-xl font-bold text-white mb-3">Colaboración Segura</h3>
                        <p class="text-gray-400">Lock de ventas en tiempo real ("Sale Lock") para que dos cajeros no cobren la misma cuenta.</p>
                    </div>
                </div>

                <!-- Box 3: Shifts -->
                <div class="rounded-3xl bg-gradient-to-b from-[#151515] to-[#0a0a0a] border border-white/5 p-8 hover:border-white/10 transition-colors flex flex-col justify-between">
                     <div class="bg-red-500/10 w-14 h-14 rounded-2xl flex items-center justify-center mb-6">
                        <svg class="w-7 h-7 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    </div>
                    <div>
                        <h3 class="font-outfit text-xl font-bold text-white mb-3">Turnos y Cortes</h3>
                        <p class="text-gray-400">Audita cada pago y retiro. Abre y cierra turnos con recálculos automáticos de caja e integraciones precisas.</p>
                    </div>
                </div>

                <!-- Box 4: Interactive Mock POS -->
                <div class="md:col-span-2 rounded-3xl bg-gradient-to-b from-[#151515] to-[#0a0a0a] border border-white/5 overflow-hidden flex flex-col md:flex-row">
                    <div class="p-8 md:p-12 w-full md:w-1/2 flex flex-col justify-center border-b md:border-b-0 md:border-r border-white/5">
                        <h3 class="font-outfit text-2xl font-bold text-white mb-4">Mesa de Trabajo Dinámica</h3>
                        <p class="text-gray-400 leading-relaxed mb-6">Filtra ventas activas o pendientes al instante. Concede total control al cajero para pausar o reactivar pedidos en medio del tránsito continuo de clientes.</p>
                        <div class="flex gap-2">
                             <button @click="activeFilter = 'activas'" :class="['px-4 py-1.5 rounded-full text-sm font-medium transition-all', activeFilter === 'activas' ? 'bg-red-500 text-white' : 'bg-white/5 text-gray-400 hover:bg-white/10']">Activas (2)</button>
                             <button @click="activeFilter = 'pending'" :class="['px-4 py-1.5 rounded-full text-sm font-medium transition-all', activeFilter === 'pending' ? 'bg-red-500 text-white' : 'bg-white/5 text-gray-400 hover:bg-white/10']">Pendientes (1)</button>
                        </div>
                    </div>
                    <div class="w-full md:w-1/2 bg-[#0a0a0a] p-6 flex items-center justify-center relative inner-shadow">
                        <!-- POS Mock Window -->
                        <div class="w-full max-w-sm rounded-xl border border-white/10 bg-[#111] shadow-2xl overflow-hidden text-sm">
                            <div class="bg-[#1a1a1a] px-4 py-3 border-b border-white/5 flex items-center gap-2">
                                <div class="w-3 h-3 rounded-full bg-red-400 flex-shrink-0"></div>
                                <span class="text-gray-300 font-medium">Ventas Recientes</span>
                            </div>
                            <div class="p-3 hide-scrollbar h-56 overflow-y-auto space-y-2">
                                <transition-group name="list">
                                    <div v-for="sale in filteredSales" :key="sale.id" class="p-3 rounded-lg border border-white/5 bg-[#181818] flex justify-between items-center group cursor-pointer hover:border-red-500/30 transition-all">
                                        <div>
                                            <div class="font-bold text-white mb-1">{{ sale.id }} <span class="text-xs text-gray-500 ml-1 font-normal">{{ sale.time }}</span></div>
                                            <div class="text-xs text-gray-400">{{ sale.items }} items <span class="text-red-400 ml-2">{{ sale.status === 'active' ? 'Pausar' : 'Reactivar' }}</span></div>
                                        </div>
                                        <div class="text-right">
                                            <div class="font-medium text-white mb-1">{{ sale.total }}</div>
                                            <div :class="['text-[10px] uppercase font-bold px-1.5 py-0.5 rounded', sale.status === 'active' ? 'bg-green-500/20 text-green-400' : 'bg-orange-500/20 text-orange-400']">
                                                {{ sale.status }}
                                            </div>
                                        </div>
                                    </div>
                                </transition-group>
                                <div v-if="filteredSales.length === 0" class="text-center py-6 text-gray-500 text-sm">
                                    No hay ventas en este estado.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </main>

        <!-- Footer -->
        <footer class="border-t border-white/5 bg-[#030303] relative z-10">
            <div class="max-w-7xl mx-auto px-6 py-12 md:flex md:items-center md:justify-between text-gray-500 text-sm">
                <div class="flex items-center gap-3 mb-4 md:mb-0">
                    <img src="/logo.png" alt="Logo" class="h-6 w-auto opacity-50 grayscale" onerror="this.style.display='none'" />
                    <span>&copy; {{ new Date().getFullYear() }} Carnicería El Puebla. Todos los derechos reservados.</span>
                </div>
                <div class="flex gap-6">
                    <span>Soporte Técnico</span>
                    <span>Términos de Servicio</span>
                </div>
            </div>
        </footer>
    </div>
</template>

<style scoped>
@import url('https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Inter:wght@400;500;600&display=swap');

.font-outfit {
    font-family: 'Outfit', sans-serif;
}
.font-inter {
    font-family: 'Inter', sans-serif;
}

.inner-shadow {
    box-shadow: inset 0 0 50px rgba(0,0,0,0.5);
}

.hide-scrollbar::-webkit-scrollbar {
  display: none;
}
.hide-scrollbar {
  -ms-overflow-style: none;
  scrollbar-width: none;
}

/* List animations */
.list-enter-active,
.list-leave-active {
  transition: all 0.3s ease;
}
.list-enter-from,
.list-leave-to {
  opacity: 0;
  transform: translateX(-20px);
}
.list-leave-active {
  position: absolute;
}

/* Entrance Animations */
.fade-in-up {
    animation: fadeInUp 1s ease-out forwards;
}

@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
</style>
