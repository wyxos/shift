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

vi.mock('@/components/ui/dropdown-menu', () => ({
    DropdownMenu: {
        render() {
            return h('div', { class: 'dropdown-menu-stub' }, this.$slots.default?.());
        },
    },
    DropdownMenuTrigger: {
        render() {
            return h('div', { class: 'dropdown-menu-trigger-stub' }, this.$slots.default?.());
        },
    },
    DropdownMenuContent: {
        render() {
            return h('div', { class: 'dropdown-menu-content-stub' }, this.$slots.default?.());
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
            },
        });

        const wrapper = mount(NotificationBadge);
        await flushPromises();

        expect(axiosGetMock).toHaveBeenCalledWith('/notifications/unread');
        expect(setIntervalSpy).not.toHaveBeenCalled();
        expect(wrapper.get('a[href="/tasks?task=42"]').text()).toContain('New Task: Broken footer');
        expect(wrapper.get('a[href="/tasks?task=84"]').text()).toContain('App Error: Backend error: RuntimeException');
        expect(wrapper.get('a[href="/notifications"]').text()).toContain('View all notifications');
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
