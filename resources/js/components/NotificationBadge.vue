<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { cn } from '@/lib/utils';
import { Link, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { Bell, ListChecks, ListRestart } from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, ref } from 'vue';

type NotificationData = {
    task_title?: string;
    type?: string;
    project_name?: string;
    user_name?: string;
    organisation_name?: string;
    url?: string;
    task_id?: number | string;
    organisation_id?: number | string | null;
};

type NotificationItem = {
    id: number | string;
    type: string;
    data: NotificationData | string;
    created_at?: string;
};

type RealtimeNotification = NotificationData & {
    id?: number | string;
    type?: string;
    data?: NotificationData | string;
    created_at?: string;
};

type PageProps = {
    auth?: {
        user?: {
            id?: number | string;
        } | null;
    };
};

const page = usePage<PageProps>();
const unreadCount = ref(0);
const totalCount = ref(0);
const notifications = ref<NotificationItem[]>([]);
const loading = ref(true);
const notificationSheetOpen = ref(false);
const bulkActionPending = ref<'read' | 'unread' | null>(null);
const hasRouteHelper = typeof route === 'function';
let channelName: string | null = null;

const unreadUrl = computed(() => (hasRouteHelper ? route('notifications.unread') : null));
const notificationsIndexUrl = computed(() => (hasRouteHelper ? route('notifications.index') : null));
const markAllAsReadUrl = computed(() => (hasRouteHelper ? route('notifications.mark-all-as-read') : null));
const markAllAsUnreadUrl = computed(() => (hasRouteHelper ? route('notifications.mark-all-as-unread') : null));
const notificationsEnabled = computed(() => Boolean(unreadUrl.value && markAllAsReadUrl.value && markAllAsUnreadUrl.value && hasRouteHelper));
const canMarkAllAsRead = computed(() => unreadCount.value > 0 && bulkActionPending.value === null);
const canMarkAllAsUnread = computed(() => totalCount.value > unreadCount.value && bulkActionPending.value === null);

function getNotificationData(notification: NotificationItem): NotificationData {
    return typeof notification.data === 'string' ? JSON.parse(notification.data) : notification.data;
}

function getMarkAsReadUrl(id: NotificationItem['id']): string | null {
    return hasRouteHelper ? route('notifications.mark-as-read', { id }) : null;
}

function getProjectNotificationsUrl(data: NotificationData): string | null {
    if (!hasRouteHelper) return null;

    if (data.organisation_id !== undefined && data.organisation_id !== null) {
        return route('organisation.projects', { organisation: data.organisation_id });
    }

    return route('dashboard');
}

function getOrganisationNotificationsUrl(): string | null {
    return hasRouteHelper ? route('organisations.index') : null;
}

function getTaskNotificationsUrl(taskId?: NotificationData['task_id']): string | null {
    if (!hasRouteHelper || taskId === undefined || taskId === null) return null;

    return route('tasks.index', { task: taskId });
}

function getAppErrorNotificationsUrl(taskId?: NotificationData['task_id']): string | null {
    if (!hasRouteHelper || taskId === undefined || taskId === null) return null;

    return route('app-errors.index', { task: taskId });
}

const fetchNotifications = async () => {
    if (!unreadUrl.value) {
        notifications.value = [];
        unreadCount.value = 0;
        totalCount.value = 0;
        loading.value = false;
        return;
    }

    loading.value = true;
    try {
        const response = await axios.get(unreadUrl.value);
        notifications.value = response.data.notifications;
        unreadCount.value = response.data.count;
        totalCount.value = response.data.total_count ?? response.data.count;
    } catch (error) {
        console.error('Error fetching notifications:', error);
    } finally {
        loading.value = false;
    }
};

function realtimeNotificationItem(notification: RealtimeNotification): NotificationItem | null {
    const id = notification.id;

    if (id === undefined || id === null) {
        return null;
    }

    return {
        id,
        type: notification.type ?? 'Notification',
        data: notification.data ?? notification,
        created_at: notification.created_at ?? 'Just now',
    };
}

function prependRealtimeNotification(notification: RealtimeNotification) {
    const item = realtimeNotificationItem(notification);

    if (!item || notifications.value.some((existing) => existing.id === item.id)) {
        return;
    }

    notifications.value = [item, ...notifications.value].slice(0, 15);
    unreadCount.value += 1;
    totalCount.value += 1;
}

function subscribeToRealtimeNotifications() {
    const userId = page.props.auth?.user?.id;
    const echo = window.Echo;

    if (!userId || !echo?.private) {
        return;
    }

    channelName = `App.Models.User.${userId}`;
    echo.private(channelName).notification((notification: RealtimeNotification) => {
        prependRealtimeNotification(notification);
    });
}

const markAsRead = async (id: NotificationItem['id']) => {
    const url = getMarkAsReadUrl(id);
    if (!url) return;

    try {
        await axios.post(url);
        notifications.value = notifications.value.filter((notification) => notification.id !== id);
        unreadCount.value = Math.max(0, unreadCount.value - 1);
    } catch (error) {
        console.error('Error marking notification as read:', error);
    }
};

const markAllAsRead = async () => {
    if (!markAllAsReadUrl.value || !canMarkAllAsRead.value) return;

    bulkActionPending.value = 'read';
    try {
        await axios.post(markAllAsReadUrl.value);
        notifications.value = [];
        unreadCount.value = 0;
    } catch (error) {
        console.error('Error marking all notifications as read:', error);
    } finally {
        bulkActionPending.value = null;
    }
};

const markAllAsUnread = async () => {
    if (!markAllAsUnreadUrl.value || !canMarkAllAsUnread.value) return;

    bulkActionPending.value = 'unread';
    try {
        await axios.post(markAllAsUnreadUrl.value);
        await fetchNotifications();
    } catch (error) {
        console.error('Error marking all notifications as unread:', error);
    } finally {
        bulkActionPending.value = null;
    }
};

onMounted(() => {
    if (!notificationsEnabled.value) {
        loading.value = false;
        return;
    }

    fetchNotifications();
    subscribeToRealtimeNotifications();
});

onUnmounted(() => {
    if (channelName && window.Echo?.leave) {
        window.Echo.leave(channelName);
    }
});

const getNotificationTitle = (notification: NotificationItem) => {
    const type = notification.type;
    const data = getNotificationData(notification);

    switch (type) {
        case 'TaskCreationNotification':
            return `New Task: ${data.task_title}`;
        case 'AppErrorReportedNotification':
            return data.task_title ?? 'Application error';
        case 'TaskThreadUpdated':
            return `New reply in ${data.type} thread for ${data.task_title}`;
        case 'ProjectInvitationNotification':
            return `Invited to project: ${data.project_name}`;
        case 'ProjectUserRegisteredNotification':
            return `New user registered: ${data.user_name}`;
        case 'OrganisationInvitationNotification':
            return `Invited to organisation: ${data.organisation_name}`;
        case 'OrganisationAccessNotification':
            return `Access granted to: ${data.organisation_name}`;
        default:
            return 'New notification';
    }
};

const isAppErrorNotification = (notification: NotificationItem) => notification.type === 'AppErrorReportedNotification';

const getNotificationUrl = (notification: NotificationItem) => {
    const data = getNotificationData(notification);

    if (data.url) {
        return data.url;
    }

    switch (notification.type) {
        case 'TaskCreationNotification':
            return getTaskNotificationsUrl(data.task_id) ?? '#';
        case 'AppErrorReportedNotification':
            return getAppErrorNotificationsUrl(data.task_id) ?? '#';
        case 'TaskThreadUpdated':
            return data.url ?? '#';
        case 'ProjectInvitationNotification':
        case 'ProjectUserRegisteredNotification':
            return getProjectNotificationsUrl(data) ?? '#';
        case 'OrganisationInvitationNotification':
        case 'OrganisationAccessNotification':
            return getOrganisationNotificationsUrl() ?? '#';
        default:
            return '#';
    }
};
</script>

<template>
    <Sheet v-if="notificationsEnabled" v-model:open="notificationSheetOpen">
        <Button
            variant="ghost"
            size="icon"
            class="relative h-9 w-9 focus-visible:ring-0 focus-visible:ring-offset-0"
            aria-label="Open notifications"
            @click="notificationSheetOpen = true"
        >
            <Bell class="h-5 w-5" />
            <Badge v-if="unreadCount > 0" class="absolute -top-1 -right-1 flex h-5 w-5 items-center justify-center rounded-full p-0 text-xs">
                {{ unreadCount > 99 ? '99+' : unreadCount }}
            </Badge>
        </Button>
        <SheetContent :show-close="false" side="right" class="gap-0 p-0">
            <SheetHeader class="flex-row items-center justify-between gap-2 border-b px-6 py-3 text-left">
                <SheetTitle>Notifications</SheetTitle>
                <div class="flex items-center gap-1" data-testid="notification-bulk-actions">
                    <Button
                        variant="ghost"
                        size="icon"
                        class="focus-visible:bg-accent h-8 w-8 focus-visible:ring-0 focus-visible:ring-offset-0"
                        :disabled="!canMarkAllAsRead"
                        aria-label="Mark all notifications as read"
                        title="Mark all as read"
                        @click="markAllAsRead"
                    >
                        <ListChecks class="h-4 w-4" aria-hidden="true" />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="focus-visible:bg-accent h-8 w-8 focus-visible:ring-0 focus-visible:ring-offset-0"
                        :disabled="!canMarkAllAsUnread"
                        aria-label="Mark all notifications as unread"
                        title="Mark all as unread"
                        @click="markAllAsUnread"
                    >
                        <ListRestart class="h-4 w-4" aria-hidden="true" />
                    </Button>
                </div>
            </SheetHeader>

            <div v-if="loading" class="text-muted-foreground flex flex-1 items-center justify-center p-6 text-center text-sm">
                Loading notifications...
            </div>

            <div v-else-if="notifications.length === 0" class="text-muted-foreground flex flex-1 items-center justify-center p-6 text-center text-sm">
                No new notifications
            </div>

            <div v-else class="shift-scrollbar min-h-0 flex-1 overflow-y-auto">
                <div
                    v-for="notification in notifications"
                    :key="notification.id"
                    class="group hover:bg-accent relative flex cursor-pointer items-center gap-2 border-b px-6 py-3"
                >
                    <div class="flex min-w-0 flex-1 flex-col gap-1">
                        <Link
                            :href="getNotificationUrl(notification)"
                            :class="cn('flex-1 text-sm font-medium', isAppErrorNotification(notification) && 'text-destructive')"
                            @click="markAsRead(notification.id)"
                        >
                            {{ getNotificationTitle(notification) }}
                        </Link>
                        <p class="text-muted-foreground text-left text-xs">{{ notification.created_at }}</p>
                    </div>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="focus-visible:bg-accent h-6 w-6 shrink-0 opacity-0 transition-opacity group-hover:opacity-100 focus-visible:opacity-100 focus-visible:ring-0 focus-visible:ring-offset-0"
                        @click="markAsRead(notification.id)"
                    >
                        <span class="sr-only">Mark as read</span>
                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            width="16"
                            height="16"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            class="lucide lucide-check"
                        >
                            <path d="M20 6 9 17l-5-5" />
                        </svg>
                    </Button>
                </div>
            </div>

            <div v-if="notificationsIndexUrl" class="shrink-0 border-t p-0" data-testid="notification-footer">
                <Link
                    :href="notificationsIndexUrl"
                    class="hover:bg-accent focus-visible:bg-accent block rounded-none p-3 text-center text-sm font-medium focus-visible:outline-none"
                >
                    View all notifications
                </Link>
            </div>
        </SheetContent>
    </Sheet>
</template>
