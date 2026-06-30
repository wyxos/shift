import NotificationsIndex from '@/pages/Notifications/Index.vue';
import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { h } from 'vue';

vi.mock('@/layouts/AppLayout.vue', () => ({
    default: {
        props: ['breadcrumbs'],
        render() {
            return h('div', { class: 'app-layout' }, this.$slots.default?.());
        },
    },
}));

vi.mock('@/components/ui/button', () => ({
    Button: {
        props: ['variant', 'size'],
        render() {
            return h('button', this.$attrs, this.$slots.default?.());
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
}));

vi.mock('axios', () => ({
    default: {
        post: vi.fn(),
    },
}));

describe('Notifications/Index.vue', () => {
    let originalRoute: unknown;

    beforeEach(() => {
        originalRoute = (globalThis as any).route;
        (globalThis as any).route = vi.fn((name: string, params?: Record<string, unknown>) => {
            switch (name) {
                case 'dashboard':
                    return '/dashboard';
                case 'notifications.index':
                    return '/notifications';
                case 'notifications.mark-as-read':
                    return `/notifications/${params?.id}/mark-as-read`;
                case 'notifications.mark-as-unread':
                    return `/notifications/${params?.id}/mark-as-unread`;
                case 'notifications.mark-all-as-read':
                    return '/notifications/mark-all-as-read';
                case 'tasks.index':
                    return `/tasks?task=${params?.task}`;
                case 'organisation.projects':
                    return `/organisation/${params?.organisation}/projects`;
                default:
                    return `/${name}`;
            }
        });
    });

    afterEach(() => {
        if (originalRoute === undefined) {
            delete (globalThis as any).route;
        } else {
            (globalThis as any).route = originalRoute;
        }
    });

    it('maps app error notifications to the matching task link', () => {
        const wrapper = mount(NotificationsIndex, {
            props: {
                notifications: {
                    data: [
                        {
                            id: 'notification-1',
                            type: 'App\\Notifications\\AppErrorReportedNotification',
                            data: {
                                task_title: 'Backend error: RuntimeException',
                                task_id: 84,
                                project_name: 'Portal Refresh',
                            },
                            created_at: '2026-06-27T12:00:00Z',
                            read_at: null,
                        },
                    ],
                    from: 1,
                    to: 1,
                    total: 1,
                    prev_page_url: null,
                    next_page_url: null,
                },
            },
        });

        expect(wrapper.get('a[href="/tasks?task=84"]').text()).toContain('App Error: Backend error: RuntimeException');
        expect(wrapper.text()).toContain('App error reported in project: Portal Refresh');
    });

    it('maps project notifications to the scoped organisation projects list', () => {
        const wrapper = mount(NotificationsIndex, {
            props: {
                notifications: {
                    data: [
                        {
                            id: 'notification-1',
                            type: 'App\\Notifications\\ProjectInvitationNotification',
                            data: {
                                project_name: 'Atlas Billing Console',
                                organisation_id: 3,
                            },
                            created_at: '2026-06-27T12:00:00Z',
                            read_at: null,
                        },
                    ],
                    from: 1,
                    to: 1,
                    total: 1,
                    prev_page_url: null,
                    next_page_url: null,
                },
            },
        });

        expect(wrapper.get('a[href="/organisation/3/projects"]').text()).toContain('Invited to project: Atlas Billing Console');
    });
});
