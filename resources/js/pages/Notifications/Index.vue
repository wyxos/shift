<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { cn } from '@/lib/utils';
import RequestButton from '@/shared/components/RequestButton.vue';
import type { BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, reactive, ref } from 'vue';
import { toast } from 'vue-sonner';

type NotificationData = {
    content?: string;
    organisation_id?: number;
    organisation_name?: string;
    project_name?: string;
    task_id?: number | string;
    task_title?: string;
    type?: string;
    url?: string;
    user_email?: string;
    user_name?: string;
    [key: string]: unknown;
};

type Notification = {
    id: string;
    type: string;
    data: NotificationData | string;
    created_at: string;
    read_at: string | null;
};

type NotificationPaginator = {
    data: Notification[];
    from: number | null;
    to: number | null;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

type NotificationMutation = 'read' | 'unread';

const props = defineProps<{
    notifications: NotificationPaginator;
}>();

const localNotifications = reactive<NotificationPaginator>({
    ...props.notifications,
    data: props.notifications.data.map((notification) => ({ ...notification })),
});

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: route('dashboard') },
    { title: 'Notifications', href: route('notifications.index') },
];

const pendingNotificationActions = reactive(new Map<string, NotificationMutation>());
const isMarkAllPending = ref(false);
const hasUnreadNotifications = computed(() => localNotifications.data.some((notification) => !notification.read_at));
const hasPendingNotificationAction = computed(() => pendingNotificationActions.size > 0);

const getNotificationData = (notification: Notification): NotificationData => {
    if (typeof notification.data !== 'string') {
        return notification.data;
    }

    try {
        const parsed: unknown = JSON.parse(notification.data);

        return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? (parsed as NotificationData) : {};
    } catch {
        return {};
    }
};

const updateNotificationReadState = async (id: string, action: NotificationMutation): Promise<void> => {
    if (pendingNotificationActions.has(id) || isMarkAllPending.value) {
        return;
    }

    const notification = localNotifications.data.find((item) => item.id === id);

    if (!notification) {
        return;
    }

    const previousReadAt = notification.read_at;
    const isMarkingRead = action === 'read';

    pendingNotificationActions.set(id, action);
    notification.read_at = isMarkingRead ? new Date().toISOString() : null;

    try {
        await axios.post(route(isMarkingRead ? 'notifications.mark-as-read' : 'notifications.mark-as-unread', { id }));
        toast.success(isMarkingRead ? 'Notification marked as read' : 'Notification marked as unread');
    } catch {
        notification.read_at = previousReadAt;
        toast.error(isMarkingRead ? 'Could not mark notification as read' : 'Could not mark notification as unread', {
            description: 'The previous status was restored. Please try again.',
        });
    } finally {
        pendingNotificationActions.delete(id);
    }
};

const markAsRead = (id: string): Promise<void> => updateNotificationReadState(id, 'read');
const markAsUnread = (id: string): Promise<void> => updateNotificationReadState(id, 'unread');

const markAllAsRead = async (): Promise<void> => {
    if (isMarkAllPending.value || hasPendingNotificationAction.value) {
        return;
    }

    const previousReadStates = new Map(localNotifications.data.map((notification) => [notification.id, notification.read_at]));
    const now = new Date().toISOString();

    isMarkAllPending.value = true;
    localNotifications.data.forEach((notification) => {
        notification.read_at = now;
    });

    try {
        await axios.post(route('notifications.mark-all-as-read'));
        toast.success('All notifications marked as read');
    } catch {
        localNotifications.data.forEach((notification) => {
            notification.read_at = previousReadStates.get(notification.id) ?? null;
        });
        toast.error('Could not mark all notifications as read', {
            description: 'The previous statuses were restored. Please try again.',
        });
    } finally {
        isMarkAllPending.value = false;
    }
};

const getNotificationTitle = (notification: Notification): string => {
    const type = notification.type;
    const data = getNotificationData(notification);

    switch (type) {
        case 'App\\Notifications\\TaskCreationNotification':
            return `New Task: ${data.task_title}`;
        case 'App\\Notifications\\AppErrorReportedNotification':
            return data.task_title ?? 'Application error';
        case 'App\\Notifications\\TaskThreadUpdated':
            return `New reply in ${data.type} thread for ${data.task_title}`;
        case 'App\\Notifications\\ProjectInvitationNotification':
            return `Invited to project: ${data.project_name}`;
        case 'App\\Notifications\\ProjectUserRegisteredNotification':
            return `New user registered: ${data.user_name}`;
        case 'App\\Notifications\\OrganisationInvitationNotification':
            return `Invited to organisation: ${data.organisation_name}`;
        case 'App\\Notifications\\OrganisationAccessNotification':
            return `Access granted to: ${data.organisation_name}`;
        default:
            return 'New notification';
    }
};

const isAppErrorNotification = (notification: Notification): boolean => notification.type === 'App\\Notifications\\AppErrorReportedNotification';

const getNotificationUrl = (notification: Notification): string => {
    const data = getNotificationData(notification);

    if (data.url) {
        return data.url;
    }

    const type = notification.type;

    switch (type) {
        case 'App\\Notifications\\TaskCreationNotification':
            return route('tasks.index', { task: data.task_id });
        case 'App\\Notifications\\AppErrorReportedNotification':
            return route('app-errors.index', { task: data.task_id });
        case 'App\\Notifications\\TaskThreadUpdated':
            return data.url ?? '#';
        case 'App\\Notifications\\ProjectInvitationNotification':
        case 'App\\Notifications\\ProjectUserRegisteredNotification':
            return data.organisation_id ? route('organisation.projects', { organisation: data.organisation_id }) : route('dashboard');
        case 'App\\Notifications\\OrganisationInvitationNotification':
        case 'App\\Notifications\\OrganisationAccessNotification':
            return route('organisations.index');
        default:
            return '#';
    }
};

const formatDate = (dateString: string): string => {
    const date = new Date(dateString);
    return date.toLocaleString();
};

const getNotificationDescription = (notification: Notification): string => {
    const data = getNotificationData(notification);
    const type = notification.type;

    switch (type) {
        case 'App\\Notifications\\TaskCreationNotification':
            return `Task created in project: ${data.project_name}`;
        case 'App\\Notifications\\AppErrorReportedNotification':
            return `App error reported in project: ${data.project_name}`;
        case 'App\\Notifications\\TaskThreadUpdated':
            return data.content ? `${data.content.substring(0, 100)}${data.content.length > 100 ? '...' : ''}` : '';
        case 'App\\Notifications\\ProjectInvitationNotification':
            return `You have been invited to join the project: ${data.project_name}`;
        case 'App\\Notifications\\ProjectUserRegisteredNotification':
            return `${data.user_name} (${data.user_email}) has registered for your project: ${data.project_name}`;
        case 'App\\Notifications\\OrganisationInvitationNotification':
            return `You have been invited to join the organisation: ${data.organisation_name}`;
        case 'App\\Notifications\\OrganisationAccessNotification':
            return `You have been granted access to the organisation: ${data.organisation_name}`;
        default:
            return '';
    }
};
</script>

<template>
    <Head title="Notifications" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <h1 class="text-2xl font-semibold tracking-tight">Notifications</h1>
                <RequestButton
                    v-if="hasUnreadNotifications || isMarkAllPending"
                    data-testid="mark-all-as-read"
                    :disabled="hasPendingNotificationAction"
                    :loading="isMarkAllPending"
                    loading-label="Marking all as read..."
                    @click="markAllAsRead"
                >
                    Mark all as read
                </RequestButton>
            </div>

            <div class="bg-card rounded-lg border">
                <div v-if="localNotifications.data.length === 0" class="text-muted-foreground p-6 text-center">No notifications found</div>

                <div v-else>
                    <div class="divide-y">
                        <div
                            v-for="notification in localNotifications.data"
                            :key="notification.id"
                            class="group hover:bg-accent/50 flex items-start gap-4 p-6"
                            :class="{ 'bg-accent/20': !notification.read_at }"
                        >
                            <div
                                v-if="!notification.read_at"
                                class="bg-primary mt-1.5 h-2 w-2 shrink-0 rounded-full"
                                title="Unread notification"
                            ></div>
                            <div
                                v-else
                                class="border-muted-foreground/30 mt-1.5 h-2 w-2 shrink-0 rounded-full border"
                                title="Read notification"
                            ></div>
                            <div class="min-w-0 flex-1">
                                <div class="mb-1 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                    <Link
                                        :href="getNotificationUrl(notification)"
                                        :class="
                                            cn(
                                                'min-w-0 text-lg font-medium break-words',
                                                !notification.read_at && 'font-bold',
                                                isAppErrorNotification(notification) && 'text-destructive',
                                            )
                                        "
                                        @click="!notification.read_at && markAsRead(notification.id)"
                                    >
                                        {{ getNotificationTitle(notification) }}
                                    </Link>
                                    <div class="flex flex-wrap items-center gap-2 sm:justify-end">
                                        <span class="text-muted-foreground text-sm whitespace-nowrap">{{ formatDate(notification.created_at) }}</span>
                                        <RequestButton
                                            v-if="pendingNotificationActions.get(notification.id) === 'read'"
                                            :data-testid="`notification-action-${notification.id}`"
                                            loading
                                            loading-label="Marking as read..."
                                            size="sm"
                                            variant="ghost"
                                        />
                                        <RequestButton
                                            v-else-if="pendingNotificationActions.get(notification.id) === 'unread'"
                                            :data-testid="`notification-action-${notification.id}`"
                                            loading
                                            loading-label="Marking as unread..."
                                            size="sm"
                                            variant="ghost"
                                        />
                                        <RequestButton
                                            v-else-if="!notification.read_at"
                                            :data-testid="`notification-action-${notification.id}`"
                                            :disabled="isMarkAllPending"
                                            size="sm"
                                            variant="ghost"
                                            @click="markAsRead(notification.id)"
                                        >
                                            Mark as read
                                        </RequestButton>
                                        <RequestButton
                                            v-else
                                            :data-testid="`notification-action-${notification.id}`"
                                            :disabled="isMarkAllPending"
                                            size="sm"
                                            variant="ghost"
                                            @click="markAsUnread(notification.id)"
                                        >
                                            Mark as unread
                                        </RequestButton>
                                    </div>
                                </div>
                                <p class="text-muted-foreground break-words">{{ getNotificationDescription(notification) }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- Pagination -->
                    <div class="flex flex-col gap-3 border-t p-4 sm:flex-row sm:items-center sm:justify-between">
                        <div class="text-muted-foreground text-sm">
                            Showing {{ localNotifications.from }} to {{ localNotifications.to }} of {{ localNotifications.total }} notifications
                        </div>
                        <div class="flex items-center gap-2">
                            <Link
                                v-if="localNotifications.prev_page_url"
                                :href="localNotifications.prev_page_url"
                                class="hover:bg-accent rounded-md border px-3 py-2 text-sm"
                            >
                                Previous
                            </Link>
                            <Link
                                v-if="localNotifications.next_page_url"
                                :href="localNotifications.next_page_url"
                                class="hover:bg-accent rounded-md border px-3 py-2 text-sm"
                            >
                                Next
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
