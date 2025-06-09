<script setup lang="ts">
import { ref, reactive } from 'vue';
import axios from 'axios';
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';

const props = defineProps({
  notifications: Object,
});

// Create a reactive copy of the notifications data
const localNotifications = reactive({
  data: [...(props.notifications?.data || [])],
  from: props.notifications?.from,
  to: props.notifications?.to,
  total: props.notifications?.total,
  prev_page_url: props.notifications?.prev_page_url,
  next_page_url: props.notifications?.next_page_url,
});

const breadcrumbs = ref([
  { name: 'Dashboard', href: route('dashboard') },
  { name: 'Notifications', href: route('notifications.index') },
]);

// Mark a notification as read
const markAsRead = async (id) => {
  try {
    await axios.post(route('notifications.mark-as-read', { id }));

    // Update the local state
    const index = localNotifications.data.findIndex(n => n.id === id);
    if (index !== -1) {
      localNotifications.data[index].read_at = new Date().toISOString();
    }
  } catch (error) {
    console.error('Error marking notification as read:', error);
  }
};

// Mark a notification as unread
const markAsUnread = async (id) => {
  try {
    await axios.post(route('notifications.mark-as-unread', { id }));

    // Update the local state
    const index = localNotifications.data.findIndex(n => n.id === id);
    if (index !== -1) {
      localNotifications.data[index].read_at = null;
    }
  } catch (error) {
    console.error('Error marking notification as unread:', error);
  }
};

// Mark all notifications as read
const markAllAsRead = async () => {
  try {
    await axios.post(route('notifications.mark-all-as-read'));

    // Update the local state
    const now = new Date().toISOString();
    localNotifications.data.forEach(notification => {
      notification.read_at = now;
    });
  } catch (error) {
    console.error('Error marking all notifications as read:', error);
  }
};

// Format notification title based on type
const getNotificationTitle = (notification) => {
  const type = notification.type;
  // Handle both string and object data formats
  const data = typeof notification.data === 'string'
    ? JSON.parse(notification.data)
    : notification.data;

  switch (type) {
    case 'App\\Notifications\\TaskCreationNotification':
      return `New Task: ${data.task_title}`;
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

// Get notification URL
const getNotificationUrl = (notification) => {
  // Handle both string and object data formats
  const data = typeof notification.data === 'string'
    ? JSON.parse(notification.data)
    : notification.data;

  if (data.url) {
    return data.url;
  }

  const type = notification.type;

  switch (type) {
    case 'App\\Notifications\\TaskCreationNotification':
      return route('tasks.edit', { task: data.task_id });
    case 'App\\Notifications\\TaskThreadUpdated':
      return data.url;
    case 'App\\Notifications\\ProjectInvitationNotification':
    case 'App\\Notifications\\ProjectUserRegisteredNotification':
      return route('projects.index');
    case 'App\\Notifications\\OrganisationInvitationNotification':
    case 'App\\Notifications\\OrganisationAccessNotification':
      return route('organisations.index');
    default:
      return '#';
  }
};

// Format date
const formatDate = (dateString) => {
  const date = new Date(dateString);
  return date.toLocaleString();
};

// Get notification description
const getNotificationDescription = (notification) => {
  // Handle both string and object data formats
  const data = typeof notification.data === 'string'
    ? JSON.parse(notification.data)
    : notification.data;
  const type = notification.type;

  switch (type) {
    case 'App\\Notifications\\TaskCreationNotification':
      return `Task created in project: ${data.project_name}`;
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
  <AppLayout :breadcrumbs="breadcrumbs">
    <div class="container mx-auto py-6">
      <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-bold">Notifications</h1>
        <Button
          v-if="localNotifications.data.some(n => !n.read_at)"
          @click="markAllAsRead"
        >
          Mark all as read
        </Button>
      </div>

      <div class="rounded-lg border bg-card">
        <div v-if="localNotifications.data.length === 0" class="p-6 text-center text-muted-foreground">
          No notifications found
        </div>

        <div v-else>
          <div class="divide-y">
            <div
              v-for="notification in localNotifications.data"
              :key="notification.id"
              class="group flex items-start gap-4 p-6 hover:bg-accent/50"
              :class="{ 'bg-accent/20': !notification.read_at }"
            >
              <div v-if="!notification.read_at" class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-primary" title="Unread notification"></div>
              <div v-else class="mt-1.5 h-2 w-2 shrink-0 rounded-full border border-muted-foreground/30" title="Read notification"></div>
              <div class="flex-1">
                <div class="mb-1 flex items-center justify-between">
                  <Link
                    :href="getNotificationUrl(notification)"
                    class="text-lg font-medium"
                    :class="{ 'font-bold': !notification.read_at }"
                    @click="!notification.read_at && markAsRead(notification.id)"
                  >
                    {{ getNotificationTitle(notification) }}
                  </Link>
                  <div class="flex items-center gap-2">
                    <span class="text-sm text-muted-foreground">{{ formatDate(notification.created_at) }}</span>
                    <Button
                      v-if="!notification.read_at"
                      variant="ghost"
                      size="sm"
                      @click="markAsRead(notification.id)"
                    >
                      Mark as read
                    </Button>
                    <Button
                      v-if="notification.read_at"
                      variant="ghost"
                      size="sm"
                      @click="markAsUnread(notification.id)"
                    >
                      Mark as unread
                    </Button>
                  </div>
                </div>
                <p class="text-muted-foreground">{{ getNotificationDescription(notification) }}</p>
              </div>
            </div>
          </div>

          <!-- Pagination -->
          <div class="flex items-center justify-between border-t p-4">
            <div class="text-sm text-muted-foreground">
              Showing {{ localNotifications.from }} to {{ localNotifications.to }} of {{ localNotifications.total }} notifications
            </div>
            <div class="flex items-center gap-2">
              <Link
                v-if="localNotifications.prev_page_url"
                :href="localNotifications.prev_page_url"
                class="rounded-md border px-3 py-2 text-sm hover:bg-accent"
              >
                Previous
              </Link>
              <Link
                v-if="localNotifications.next_page_url"
                :href="localNotifications.next_page_url"
                class="rounded-md border px-3 py-2 text-sm hover:bg-accent"
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
