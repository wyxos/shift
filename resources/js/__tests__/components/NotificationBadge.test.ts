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
}));

describe('NotificationBadge', () => {
    let originalRoute: unknown;

    beforeEach(() => {
        originalRoute = (globalThis as any).route;
        axiosGetMock.mockReset();
        axiosPostMock.mockReset();
    });

    afterEach(() => {
        if (originalRoute === undefined) {
            delete (globalThis as any).route;
            return;
        }

        (globalThis as any).route = originalRoute;
    });

    it('does not render or fetch when the Ziggy route helper is unavailable', async () => {
        delete (globalThis as any).route;

        const wrapper = mount(NotificationBadge);
        await flushPromises();

        expect(axiosGetMock).not.toHaveBeenCalled();
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
                case 'projects.index':
                    return '/projects';
                case 'organisations.index':
                    return '/organisations';
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
                ],
                count: 1,
            },
        });

        const wrapper = mount(NotificationBadge);
        await flushPromises();

        expect(axiosGetMock).toHaveBeenCalledWith('/notifications/unread');
        expect(wrapper.get('a[href="/tasks?task=42"]').text()).toContain('New Task: Broken footer');
        expect(wrapper.get('a[href="/notifications"]').text()).toContain('View all notifications');
    });
});
