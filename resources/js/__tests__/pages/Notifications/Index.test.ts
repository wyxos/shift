import NotificationsIndex from '@/pages/Notifications/Index.vue';
import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const mocks = vi.hoisted(() => ({
    toastError: vi.fn(),
    toastSuccess: vi.fn(),
}));

vi.mock('@/components/AppShell.vue', async () => {
    const { defineComponent, h } = await vi.importActual<typeof import('vue')>('vue');

    return {
        default: defineComponent({
            name: 'AppShellStub',
            setup(_, { slots }) {
                return () => h('div', { 'data-testid': 'app-shell' }, slots.default?.());
            },
        }),
    };
});

vi.mock('@/components/AppContent.vue', async () => {
    const { defineComponent, h } = await vi.importActual<typeof import('vue')>('vue');

    return {
        default: defineComponent({
            name: 'AppContentStub',
            setup(_, { attrs, slots }) {
                return () => h('main', attrs, slots.default?.());
            },
        }),
    };
});

vi.mock('@/components/AppSidebar.vue', async () => {
    const { defineComponent, h } = await vi.importActual<typeof import('vue')>('vue');

    return {
        default: defineComponent({
            name: 'AppSidebarStub',
            setup: () => () => h('aside'),
        }),
    };
});

vi.mock('@/components/AppearanceTabs.vue', async () => {
    const { defineComponent, h } = await vi.importActual<typeof import('vue')>('vue');

    return {
        default: defineComponent({
            name: 'AppearanceTabsStub',
            setup: () => () => h('div'),
        }),
    };
});

vi.mock('@/components/NotificationBadge.vue', async () => {
    const { defineComponent, h } = await vi.importActual<typeof import('vue')>('vue');

    return {
        default: defineComponent({
            name: 'NotificationBadgeStub',
            setup: () => () => h('div'),
        }),
    };
});

vi.mock('@/components/ui/sidebar', async () => {
    const { defineComponent, h } = await vi.importActual<typeof import('vue')>('vue');

    return {
        SidebarTrigger: defineComponent({
            name: 'SidebarTriggerStub',
            setup: () => () => h('button', { type: 'button' }, 'Toggle sidebar'),
        }),
    };
});

vi.mock('@/components/ui/breadcrumb', async () => {
    const { defineComponent, h } = await vi.importActual<typeof import('vue')>('vue');
    const passthrough = (name: string, tag: string, attributes: Record<string, string> = {}) =>
        defineComponent({
            name,
            inheritAttrs: false,
            setup(_, { slots }) {
                return () => h(tag, attributes, slots.default?.());
            },
        });

    return {
        Breadcrumb: passthrough('BreadcrumbStub', 'nav', { 'data-testid': 'breadcrumbs' }),
        BreadcrumbItem: passthrough('BreadcrumbItemStub', 'li'),
        BreadcrumbLink: passthrough('BreadcrumbLinkStub', 'span'),
        BreadcrumbList: passthrough('BreadcrumbListStub', 'ol'),
        BreadcrumbPage: passthrough('BreadcrumbPageStub', 'span', { 'data-testid': 'breadcrumb-page' }),
        BreadcrumbSeparator: defineComponent({
            name: 'BreadcrumbSeparatorStub',
            setup: () => () => h('span', { 'aria-hidden': 'true' }, '/'),
        }),
    };
});

vi.mock('@/components/ui/button', async () => {
    const { defineComponent, h } = await vi.importActual<typeof import('vue')>('vue');

    return {
        Button: defineComponent({
            name: 'ButtonStub',
            inheritAttrs: false,
            props: {
                disabled: Boolean,
                size: String,
                type: String,
                variant: String,
            },
            setup(props, { attrs, slots }) {
                return () =>
                    h(
                        'button',
                        {
                            ...attrs,
                            disabled: props.disabled,
                            type: props.type ?? 'button',
                        },
                        slots.default?.(),
                    );
            },
        }),
    };
});

vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent, h } = await vi.importActual<typeof import('vue')>('vue');

    return {
        Head: defineComponent({
            name: 'HeadStub',
            props: {
                title: {
                    type: String,
                    required: true,
                },
            },
            setup(props) {
                return () => h('title', { 'data-testid': 'head-title' }, props.title);
            },
        }),
        Link: defineComponent({
            name: 'LinkStub',
            inheritAttrs: false,
            props: {
                href: {
                    type: String,
                    required: true,
                },
            },
            setup(props, { attrs, slots }) {
                return () => h('a', { ...attrs, href: props.href }, slots.default?.());
            },
        }),
    };
});

vi.mock('axios', () => ({
    default: {
        post: vi.fn(),
    },
}));

vi.mock('vue-sonner', () => ({
    toast: {
        error: mocks.toastError,
        success: mocks.toastSuccess,
    },
}));

type NotificationRecord = {
    id: string;
    type: string;
    data: Record<string, unknown> | string;
    created_at: string;
    read_at: string | null;
};

const createNotification = (overrides: Partial<NotificationRecord> = {}): NotificationRecord => ({
    id: 'notification-1',
    type: 'App\\Notifications\\TaskCreationNotification',
    data: {
        task_title: 'Prepare release notes',
        task_id: 84,
        project_name: 'Portal Refresh',
    },
    created_at: '2026-06-27T12:00:00Z',
    read_at: null,
    ...overrides,
});

const createPaginator = (data: NotificationRecord[]) => ({
    data,
    from: data.length ? 1 : null,
    to: data.length || null,
    total: data.length,
    prev_page_url: null,
    next_page_url: null,
});

const mountPage = (data: NotificationRecord[]) =>
    mount(NotificationsIndex, {
        props: {
            notifications: createPaginator(data),
        },
    });

const createDeferredRequest = () => {
    let resolve!: () => void;
    let reject!: (reason?: unknown) => void;
    const promise = new Promise<void>((requestResolve, requestReject) => {
        resolve = requestResolve;
        reject = requestReject;
    });

    return { promise, reject, resolve };
};

describe('Notifications/Index.vue', () => {
    let originalRoute: unknown;

    beforeEach(() => {
        vi.mocked(axios.post).mockReset();
        mocks.toastError.mockReset();
        mocks.toastSuccess.mockReset();

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
                case 'app-errors.index':
                    return `/app-errors?task=${params?.task}`;
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

    it('renders the document title and breadcrumb titles through the real layout chain', () => {
        const wrapper = mountPage([]);
        const breadcrumbs = wrapper.get('[data-testid="breadcrumbs"]');

        expect(wrapper.get('[data-testid="head-title"]').text()).toBe('Notifications');
        expect(breadcrumbs.get('a[href="/dashboard"]').text()).toBe('Dashboard');
        expect(breadcrumbs.get('[data-testid="breadcrumb-page"]').text()).toBe('Notifications');
    });

    it('maps app error notifications to the matching task link', () => {
        const wrapper = mountPage([
            createNotification({
                type: 'App\\Notifications\\AppErrorReportedNotification',
                data: {
                    task_title: 'Backend error: RuntimeException',
                    task_id: 84,
                    project_name: 'Portal Refresh',
                },
            }),
        ]);

        expect(wrapper.get('a[href="/app-errors?task=84"]').text()).toContain('App Error: Backend error: RuntimeException');
        expect(wrapper.text()).toContain('App error reported in project: Portal Refresh');
    });

    it('maps project notifications to the scoped organisation projects list', () => {
        const wrapper = mountPage([
            createNotification({
                type: 'App\\Notifications\\ProjectInvitationNotification',
                data: {
                    project_name: 'Atlas Billing Console',
                    organisation_id: 3,
                },
            }),
        ]);

        expect(wrapper.get('a[href="/organisation/3/projects"]').text()).toContain('Invited to project: Atlas Billing Console');
    });

    it('shows a disabled loading state and confirms a successful read mutation', async () => {
        const request = createDeferredRequest();
        vi.mocked(axios.post).mockReturnValueOnce(request.promise as never);
        const wrapper = mountPage([createNotification()]);

        await wrapper.get('[data-testid="notification-action-notification-1"]').trigger('click');

        const pendingAction = wrapper.get('[data-testid="notification-action-notification-1"]');
        expect(pendingAction.text()).toContain('Marking as read...');
        expect(pendingAction.attributes('disabled')).toBeDefined();

        request.resolve();
        await flushPromises();

        expect(axios.post).toHaveBeenCalledWith('/notifications/notification-1/mark-as-read');
        expect(wrapper.get('[data-testid="notification-action-notification-1"]').text()).toContain('Mark as unread');
        expect(mocks.toastSuccess).toHaveBeenCalledWith('Notification marked as read');
    });

    it('rolls back a failed read mutation and shows retryable error feedback', async () => {
        vi.mocked(axios.post).mockRejectedValueOnce(new Error('Request failed'));
        const wrapper = mountPage([createNotification()]);

        await wrapper.get('[data-testid="notification-action-notification-1"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[data-testid="notification-action-notification-1"]').text()).toContain('Mark as read');
        expect(wrapper.find('[title="Unread notification"]').exists()).toBe(true);
        expect(mocks.toastError).toHaveBeenCalledWith('Could not mark notification as read', {
            description: 'The previous status was restored. Please try again.',
        });
    });

    it('rolls back a failed unread mutation and shows retryable error feedback', async () => {
        vi.mocked(axios.post).mockRejectedValueOnce(new Error('Request failed'));
        const wrapper = mountPage([createNotification({ read_at: '2026-06-27T12:30:00Z' })]);

        await wrapper.get('[data-testid="notification-action-notification-1"]').trigger('click');
        await flushPromises();

        expect(axios.post).toHaveBeenCalledWith('/notifications/notification-1/mark-as-unread');
        expect(wrapper.get('[data-testid="notification-action-notification-1"]').text()).toContain('Mark as unread');
        expect(wrapper.find('[title="Read notification"]').exists()).toBe(true);
        expect(mocks.toastError).toHaveBeenCalledWith('Could not mark notification as unread', {
            description: 'The previous status was restored. Please try again.',
        });
    });

    it('keeps mark-all loading visible and restores every status after failure', async () => {
        const request = createDeferredRequest();
        vi.mocked(axios.post).mockReturnValueOnce(request.promise as never);
        const wrapper = mountPage([createNotification(), createNotification({ id: 'notification-2', read_at: '2026-06-27T12:30:00Z' })]);

        await wrapper.get('[data-testid="mark-all-as-read"]').trigger('click');

        const pendingAction = wrapper.get('[data-testid="mark-all-as-read"]');
        expect(pendingAction.text()).toContain('Marking all as read...');
        expect(pendingAction.attributes('disabled')).toBeDefined();

        request.reject(new Error('Request failed'));
        await flushPromises();

        expect(axios.post).toHaveBeenCalledWith('/notifications/mark-all-as-read');
        expect(wrapper.get('[data-testid="mark-all-as-read"]').text()).toContain('Mark all as read');
        expect(wrapper.get('[data-testid="notification-action-notification-1"]').text()).toContain('Mark as read');
        expect(wrapper.get('[data-testid="notification-action-notification-2"]').text()).toContain('Mark as unread');
        expect(mocks.toastError).toHaveBeenCalledWith('Could not mark all notifications as read', {
            description: 'The previous statuses were restored. Please try again.',
        });
    });
});
