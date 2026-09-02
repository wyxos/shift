import NotificationBadge from '@/components/NotificationBadge.vue';
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { h } from 'vue';

const axiosGetMock = vi.fn();
const axiosPostMock = vi.fn();

vi.mock('axios', () => ({
    default: {
        get: (...args: any[]) => axiosGetMock(...args),
        post: (...args: any[]) => axiosPostMock(...args),
    },
}));

vi.mock('@/components/ui/badge', () => ({
    Badge: {
        render() {
            return h('span', { class: 'badge-stub' }, this.$slots.default?.());
        },
    },
}));

vi.mock('@/components/ui/button', () => ({
    Button: {
        props: ['variant', 'size'],
        render() {
            return h(
                'button',
                {
                    ...this.$attrs,
                    class: `button-stub ${this.variant ?? ''} ${this.size ?? ''}`.trim(),
                },
                this.$slots.default?.(),
            );
        },
    },
}));

vi.mock('@/components/ui/sheet', () => ({
    Sheet: {
        render() {
            return h('div', { class: 'sheet-stub' }, this.$slots.default?.());
        },
    },
    SheetTrigger: {
        render() {
            return h('div', { class: 'sheet-trigger-stub' }, this.$slots.default?.());
        },
    },
    SheetContent: {
        render() {
            return h('aside', { class: 'sheet-content-stub' }, this.$slots.default?.());
        },
    },
    SheetHeader: {
        render() {
            return h('header', { class: 'sheet-header-stub' }, this.$slots.default?.());
        },
    },
    SheetTitle: {
        render() {
            return h('h2', { class: 'sheet-title-stub' }, this.$slots.default?.());
        },
    },
}));

vi.mock('@inertiajs/vue3', () => ({
    Link: {
        props: ['href'],
        render() {
            return h('a', { href: this.href }, this.$slots.default?.());
        },
    },
    usePage: () => ({
        props: {
            auth: {
                user: {
                    id: 1,
                },
            },
        },
    }),
}));

describe('NotificationBadge', () => {
    let originalRoute: unknown;
    let originalEcho: unknown;
    let setIntervalSpy: ReturnType<typeof vi.spyOn>;

    beforeEach(() => {
        originalRoute = (globalThis as any).route;
        originalEcho = (globalThis as any).Echo;
        setIntervalSpy = vi.spyOn(globalThis, 'setInterval').mockImplementation(() => 1 as any);
        axiosGetMock.mockReset();
        axiosPostMock.mockReset();
    });

    afterEach(() => {
        setIntervalSpy.mockRestore();

        if (originalRoute === undefined) {
            delete (globalThis as any).route;
        } else {
            (globalThis as any).route = originalRoute;
        }

        if (originalEcho === undefined) {
            delete (globalThis as any).Echo;
        } else {
            (globalThis as any).Echo = originalEcho;
        }
    });

    it('does not render or fetch when the Ziggy route helper is unavailable', async () => {
        delete (globalThis as any).route;

        const wrapper = mount(NotificationBadge);
        await flushPromises();

        expect(axiosGetMock).not.toHaveBeenCalled();
        expect(setIntervalSpy).not.toHaveBeenCalled();
        expect(wrapper.find('button').exists()).toBe(false);
        expect(wrapper.text()).toBe('');
    });

    it('fetches notifications and renders links when the route helper is available', async () => {
        (globalThis as any).route = vi.fn((name: string, params?: Record<string, unknown>) => {
            switch (name) {
                case 'notifications.unread':
                    return '/notifications/unread';
                case 'notifications.index':
                    return '/notifications';
                case 'notifications.mark-all-as-read':
                    return '/notifications/mark-all-as-read';
                case 'notifications.mark-all-as-unread':
                    return '/notifications/mark-all-as-unread';
                case 'notifications.mark-as-read':
                    return `/notifications/${params?.id}/mark-as-read`;
                case 'tasks.index':
                    return `/tasks?task=${params?.task}`;
                case 'app-errors.index':
                    return `/error-reports?task=${params?.task}`;
                case 'organisation.projects':
                    return `/organisation/${params?.organisation}/projects`;
                case 'organisations.index':
                    return '/organisations';
                case 'dashboard':
                    return '/dashboard';
                default:
                    return `/${name}`;
            }
        });

        axiosGetMock.mockResolvedValue({
            data: {
                notifications: [
                    {
                        id: 'notification-1',
                        type: 'TaskCreationNotification',
                        data: {
                            task_title: 'Broken footer',
                            task_id: 42,
                        },
                        created_at: '1 minute ago',
                    },
                    {
                        id: 'notification-2',
                        type: 'AppErrorReportedNotification',
                        data: {
                            task_title: 'Backend error: RuntimeException',
                            task_id: 84,
                        },
                        created_at: 'just now',
                    },
                ],
                count: 2,
                total_count: 3,
            },
        });

        const wrapper = mount(NotificationBadge);
        await flushPromises();

        expect(axiosGetMock).toHaveBeenCalledWith('/notifications/unread');
        expect(setIntervalSpy).not.toHaveBeenCalled();
        expect(wrapper.find('.sheet-content-stub').exists()).toBe(true);
        wrapper.get('[aria-label="Open notifications"]');
        expect(wrapper.get('button[aria-label="Mark all notifications as read"]').attributes('title')).toBe('Mark all as read');
        expect(wrapper.get('button[aria-label="Mark all notifications as unread"]').attributes('title')).toBe('Mark all as unread');
        expect(wrapper.get('[data-testid="notification-bulk-actions"]').classes()).toContain('items-center');
        expect(wrapper.get('[data-testid="notification-bulk-actions"]').classes()).not.toContain('flex-col');
        expect(wrapper.get('.sheet-header-stub').classes()).toContain('flex-row');
        expect(wrapper.get('.shift-scrollbar').classes()).toContain('overflow-y-auto');
        expect(wrapper.get('.sheet-title-stub').text()).toBe('Notifications');
        expect(wrapper.get('.group.relative').classes()).toContain('py-3');
        expect(wrapper.get('a[href="/tasks?task=42"]').classes()).toContain('text-sm');
        expect(wrapper.get('a[href="/tasks?task=42"]').text()).toContain('New Task: Broken footer');
        expect(wrapper.get('p.text-muted-foreground').classes()).toEqual(expect.arrayContaining(['text-left', 'text-xs']));
        expect(wrapper.get('a[href="/error-reports?task=84"]').text()).toBe('Backend error: RuntimeException');
        expect(wrapper.get('a[href="/error-reports?task=84"]').classes()).toContain('text-destructive');
        expect(wrapper.get('a[href="/notifications"]').text()).toContain('View all notifications');
        expect(wrapper.get('a[href="/notifications"]').classes()).toContain('rounded-none');
        expect(wrapper.get('[data-testid="notification-footer"]').classes()).toContain('p-0');
    });

    it('toggles the bulk read controls between enabled and disabled states', async () => {
        (globalThis as any).route = vi.fn((name: string, params?: Record<string, unknown>) => {
            switch (name) {
                case 'notifications.unread':
                    return '/notifications/unread';
                case 'notifications.index':
                    return '/notifications';
                case 'notifications.mark-all-as-read':
                    return '/notifications/mark-all-as-read';
                case 'notifications.mark-all-as-unread':
                    return '/notifications/mark-all-as-unread';
                case 'notifications.mark-as-read':
                    return `/notifications/${params?.id}/mark-as-read`;
                case 'tasks.index':
                    return `/tasks?task=${params?.task}`;
                default:
                    return `/${name}`;
            }
        });

        axiosGetMock.mockResolvedValue({
            data: {
                notifications: [
                    {
                        id: 'notification-1',
                        type: 'TaskCreationNotification',
                        data: {
                            task_title: 'Review release notes',
                            task_id: 42,
                        },
                        created_at: 'just now',
                    },
                ],
                count: 1,
                total_count: 1,
            },
        });
        axiosPostMock.mockResolvedValue({ data: { success: true } });

        const wrapper = mount(NotificationBadge);
        await flushPromises();

        const markAllRead = wrapper.get('button[aria-label="Mark all notifications as read"]');
        const markAllUnread = wrapper.get('button[aria-label="Mark all notifications as unread"]');

        expect(markAllRead.attributes('disabled')).toBeUndefined();
        expect(markAllUnread.attributes('disabled')).toBeDefined();

        await markAllRead.trigger('click');
        await flushPromises();

        expect(axiosPostMock).toHaveBeenCalledWith('/notifications/mark-all-as-read');
        expect(markAllRead.attributes('disabled')).toBeDefined();
        expect(markAllUnread.attributes('disabled')).toBeUndefined();

        await markAllUnread.trigger('click');
        await flushPromises();

        expect(axiosPostMock).toHaveBeenCalledWith('/notifications/mark-all-as-unread');
        expect(axiosGetMock).toHaveBeenCalledTimes(2);
        expect(markAllRead.attributes('disabled')).toBeUndefined();
        expect(markAllUnread.attributes('disabled')).toBeDefined();
    });

    it('prepends realtime notifications from Echo and leaves the channel on unmount', async () => {
        (globalThis as any).route = vi.fn((name: string, params?: Record<string, unknown>) => {
            switch (name) {
                case 'notifications.unread':
                    return '/notifications/unread';
                case 'notifications.index':
                    return '/notifications';
                case 'notifications.mark-all-as-read':
                    return '/notifications/mark-all-as-read';
                case 'notifications.mark-as-read':
                    return `/notifications/${params?.id}/mark-as-read`;
                case 'tasks.index':
                    return `/tasks?task=${params?.task}`;
                case 'organisation.projects':
                    return `/organisation/${params?.organisation}/projects`;
                case 'organisations.index':
                    return '/organisations';
                case 'dashboard':
                    return '/dashboard';
                default:
                    return `/${name}`;
            }
        });

        axiosGetMock.mockResolvedValue({
            data: {
                notifications: [],
                count: 0,
            },
        });

        let callback: ((notification: Record<string, unknown>) => void) | null = null;
        const notificationMock = vi.fn((handler: typeof callback) => {
            callback = handler;

            return channel;
        });
        const channel = {
            notification: notificationMock,
        };
        const privateMock = vi.fn(() => channel);
        const leaveMock = vi.fn();
        (globalThis as any).Echo = {
            private: privateMock,
            leave: leaveMock,
        };

        const wrapper = mount(NotificationBadge);
        await flushPromises();

        expect(privateMock).toHaveBeenCalledWith('App.Models.User.1');
        expect(notificationMock).toHaveBeenCalledOnce();
        expect(callback).not.toBeNull();

        callback?.({
            id: 'realtime-1',
            type: 'TaskCreationNotification',
            task_title: 'Realtime failure',
            task_id: 99,
            created_at: 'just now',
        });
        await flushPromises();

        expect(wrapper.text()).toContain('1');
        expect(wrapper.get('a[href="/tasks?task=99"]').text()).toContain('New Task: Realtime failure');

        wrapper.unmount();

        expect(leaveMock).toHaveBeenCalledWith('App.Models.User.1');
    });

    it('links project notifications to the scoped organisation projects list', async () => {
        (globalThis as any).route = vi.fn((name: string, params?: Record<string, unknown>) => {
            switch (name) {
                case 'notifications.unread':
                    return '/notifications/unread';
                case 'notifications.index':
                    return '/notifications';
                case 'notifications.mark-all-as-read':
                    return '/notifications/mark-all-as-read';
                case 'notifications.mark-as-read':
                    return `/notifications/${params?.id}/mark-as-read`;
                case 'organisation.projects':
                    return `/organisation/${params?.organisation}/projects`;
                case 'dashboard':
                    return '/dashboard';
                default:
                    return `/${name}`;
            }
        });

        axiosGetMock.mockResolvedValue({
            data: {
                notifications: [
                    {
                        id: 'notification-1',
                        type: 'ProjectInvitationNotification',
                        data: {
                            project_name: 'Atlas Billing Console',
                            organisation_id: 3,
                        },
                        created_at: 'just now',
                    },
                ],
                count: 1,
            },
        });

        const wrapper = mount(NotificationBadge);
        await flushPromises();

        expect(wrapper.get('a[href="/organisation/3/projects"]').text()).toContain('Invited to project: Atlas Billing Console');
    });

    it('uses the dashboard as the project notification fallback when no organisation is present', async () => {
        (globalThis as any).route = vi.fn((name: string, params?: Record<string, unknown>) => {
            switch (name) {
                case 'notifications.unread':
                    return '/notifications/unread';
                case 'notifications.index':
                    return '/notifications';
                case 'notifications.mark-all-as-read':
                    return '/notifications/mark-all-as-read';
                case 'notifications.mark-as-read':
                    return `/notifications/${params?.id}/mark-as-read`;
                case 'organisation.projects':
                    return `/organisation/${params?.organisation}/projects`;
                case 'dashboard':
                    return '/dashboard';
                default:
                    return `/${name}`;
            }
        });

        axiosGetMock.mockResolvedValue({
            data: {
                notifications: [
                    {
                        id: 'notification-1',
                        type: 'ProjectUserRegisteredNotification',
                        data: {
                            project_name: 'Standalone Project',
                            user_name: 'New User',
                        },
                        created_at: 'just now',
                    },
                ],
                count: 1,
            },
        });

        const wrapper = mount(NotificationBadge);
        await flushPromises();

        expect(wrapper.get('a[href="/dashboard"]').text()).toContain('New user registered: New User');
    });
});
