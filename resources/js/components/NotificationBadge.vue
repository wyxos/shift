<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import { Bell } from 'lucide-vue-next';
import { onMounted, ref } from 'vue';

type NotificationData = {
    task_title?: string;
    type?: string;
    project_name?: string;
    user_name?: string;
    organisation_name?: string;
    url?: string;
    task_id?: number | string;
};

type NotificationItem = {
    id: number | string;
    type: string;
    data: NotificationData | string;
    created_at?: string;
};

const unreadCount = ref(0);
const notifications = ref<NotificationItem[]>([]);
const loading = ref(true);

const fetchNotifications = async () => {
    loading.value = true;
    try {
        const response = await axios.get(route('notifications.unread'));
        notifications.value = response.data.notifications;
        unreadCount.value = response.data.count;
    } catch (error) {
        console.error('Error fetching notifications:', error);
    } finally {
        loading.value = false;
    }
};

const markAsRead = async (id: NotificationItem['id']) => {
    try {
        await axios.post(route('notifications.mark-as-read', { id }));
        // Remove the notification from the list
        notifications.value = notifications.value.filter((notification) => notification.id !== id);
        // Decrement the unread count
        unreadCount.value--;
    } catch (error) {
        console.error('Error marking notification as read:', error);
    }
};

const markAllAsRead = async () => {
    try {
        await axios.post(route('notifications.mark-all-as-read'));
        notifications.value = [];
        unreadCount.value = 0;
    } catch (error) {
        console.error('Error marking all notifications as read:', error);
    }
};

// Fetch notifications on component mount
onMounted(() => {
    fetchNotifications();

    // Set up polling to refresh notifications every minute
    const interval = setInterval(fetchNotifications, 60000);

    // Clean up interval on component unmount
    return () => clearInterval(interval);
});

// Format notification title based on type
const getNotificationTitle = (notification: NotificationItem) => {
    const type = notification.type;
    // Handle both string and object data formats
    const data = typeof notification.data === 'string' ? JSON.parse(notification.data) : notification.data;

    switch (type) {
        case 'TaskCreationNotification':
            return `New Task: ${data.task_title}`;
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

// Get notification URL
const getNotificationUrl = (notification: NotificationItem) => {
    // Handle both string and object data formats
    const data = typeof notification.data === 'string' ? JSON.parse(notification.data) : notification.data;

    if (data.url) {
        return data.url;
    }

    switch (notification.type) {
        case 'TaskCreationNotification':
            return route('tasks.edit', { task: data.task_id });
        case 'TaskThreadUpdated':
            return data.url;
        case 'ProjectInvitationNotification':
        case 'ProjectUserRegisteredNotification':
            return route('projects.index');
        case 'OrganisationInvitationNotification':
        case 'OrganisationAccessNotification':
            return route('organisations.index');
        default:
            return '#';
    }
};
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger :as-child="true">
            <Button variant="ghost" size="icon" class="relative h-9 w-9">
                <Bell class="h-5 w-5" />
                <Badge v-if="unreadCount > 0" class="absolute -top-1 -right-1 flex h-5 w-5 items-center justify-center rounded-full p-0 text-xs">
                    {{ unreadCount > 99 ? '99+' : unreadCount }}
                </Badge>
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-80">
            <div class="flex items-center justify-between p-4 pb-2">
                <h3 class="font-medium">Notifications</h3>
                <Button v-if="unreadCount > 0" variant="ghost" size="sm" @click="markAllAsRead"> Mark all as read </Button>
            </div>

            <div v-if="loading" class="text-muted-foreground p-4 text-center text-sm">Loading notifications...</div>

            <div v-else-if="notifications.length === 0" class="text-muted-foreground p-4 text-center text-sm">No new notifications</div>

            <div v-else class="max-h-[400px] overflow-y-auto">
                <div
                    v-for="notification in notifications"
                    :key="notification.id"
                    class="group hover:bg-accent relative flex cursor-pointer flex-col gap-1 border-b p-4"
                >
                    <div class="flex items-start justify-between gap-2">
                        <Link :href="getNotificationUrl(notification)" class="flex-1 font-medium" @click="markAsRead(notification.id)">
                            {{ getNotificationTitle(notification) }}
                        </Link>
                        <Button
                            variant="ghost"
                            size="icon"
                            class="h-6 w-6 opacity-0 transition-opacity group-hover:opacity-100"
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
                    <p class="text-muted-foreground text-sm">{{ notification.created_at }}</p>
                </div>
            </div>

            <div class="border-t p-2">
                <Link :href="route('notifications.index')" class="hover:bg-accent block rounded-md p-2 text-center text-sm font-medium">
                    View all notifications
                </Link>
            </div>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
