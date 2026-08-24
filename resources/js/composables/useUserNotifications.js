import { computed, onMounted, onUnmounted, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';

/**
 * Avisos personales del usuario: los que se guardan en base de datos y además
 * llegan por WebSocket.
 *
 * El evento avisa al instante; la fila garantiza que quien no estaba conectado
 * lo encuentre al volver. Por eso al montar SIEMPRE se lee por HTTP: el socket
 * no reenvía nada de lo que ocurrió antes de conectarse.
 *
 * Escucha el canal `App.Models.User.{id}`, que es el que Laravel usa para las
 * notificaciones con canal `broadcast` y que ya estaba declarado y autorizado en
 * `routes/channels.php` sin que nadie lo usara.
 */
export function useUserNotifications() {
    const page = usePage();
    const userId = computed(() => page.props.auth?.user?.id ?? null);

    const items = ref([]);
    const unreadCount = ref(0);
    const loading = ref(false);

    let channel = null;
    let handler = null;

    async function load() {
        if (!userId.value) return;
        loading.value = true;
        try {
            const res = await fetch(route('notifications.index'), {
                headers: { Accept: 'application/json' },
            });
            if (!res.ok) return;
            const data = await res.json();
            items.value = data.notifications ?? [];
            unreadCount.value = data.unread_count ?? 0;
        } catch {
            /* silencioso: degradación elegante, como la campana de agenda */
        } finally {
            loading.value = false;
        }
    }

    async function patch(url) {
        try {
            const res = await fetch(url, {
                method: 'PATCH',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
                },
            });
            if (!res.ok) return null;
            return await res.json();
        } catch {
            return null;
        }
    }

    async function markRead(id) {
        const target = items.value.find((n) => n.id === id);
        if (!target || target.read_at) return;

        // Optimista: el badge baja ya, y la lectura de vuelta lo corrige si el
        // servidor dijo otra cosa.
        target.read_at = new Date().toISOString();
        unreadCount.value = Math.max(0, unreadCount.value - 1);

        const data = await patch(route('notifications.read', id));
        if (data) unreadCount.value = data.unread_count;
    }

    async function markAllRead() {
        const now = new Date().toISOString();
        items.value.forEach((n) => {
            if (!n.read_at) n.read_at = now;
        });
        unreadCount.value = 0;

        await patch(route('notifications.read-all'));
    }

    onMounted(() => {
        load();

        if (!userId.value || !window.Echo) return;

        channel = window.Echo.private(`App.Models.User.${userId.value}`);
        handler = (payload) => {
            // Laravel entrega el `data` de la notificación más su id y tipo.
            items.value.unshift({
                id: payload.id,
                read_at: null,
                created_at: new Date().toISOString(),
                ...payload,
            });
            unreadCount.value++;
        };
        channel.notification(handler);
    });

    onUnmounted(() => {
        if (!channel) return;
        try {
            channel.stopListeningForNotification(handler);
            window.Echo.leave(`App.Models.User.${userId.value}`);
        } catch {
            /* el socket ya podía estar cerrado */
        }
        channel = null;
    });

    return { items, unreadCount, loading, load, markRead, markAllRead };
}
