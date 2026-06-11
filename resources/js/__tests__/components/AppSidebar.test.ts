import AppSidebar from '@/components/AppSidebar.vue';
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const { mockPage } = vi.hoisted(() => ({
    mockPage: {
        url: '/organisations',
        props: {
            sidebarOrganisations: [
                { id: 3, name: 'Northwind Organisation', isOwner: true },
                { id: 4, name: 'Northwind Studio', isOwner: false },
            ],
            sidebarOrganisationsHasMore: false,
        },
    },
}));

const fetchMock = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent, h } = await import('vue');

    return {
        Link: defineComponent({
            props: {
                href: {
                    type: String,
                    required: true,
                },
            },
            setup(props, { slots }) {
                return () => h('a', { href: props.href }, slots.default?.());
            },
        }),
        usePage: () => mockPage,
    };
});

vi.mock('@/components/AppLogo.vue', async () => {
    const { defineComponent, h } = await import('vue');

    return {
        default: defineComponent({
            setup() {
                return () => h('div', 'SHIFT');
            },
        }),
    };
});

vi.mock('@/components/NavFooter.vue', async () => {
    const { defineComponent, h } = await import('vue');

    return {
        default: defineComponent({
            setup() {
                return () => h('nav', { 'data-testid': 'nav-footer' });
            },
        }),
    };
});

vi.mock('@/components/NavUser.vue', async () => {
    const { defineComponent, h } = await import('vue');

    return {
        default: defineComponent({
            setup() {
                return () => h('div', { 'data-testid': 'nav-user' });
            },
        }),
    };
});

vi.mock('@/components/ui/sidebar', async () => {
    const { defineComponent, h } = await import('vue');
    const passthrough = (tag = 'div') =>
        defineComponent({
            setup(_, { slots }) {
                return () => h(tag, slots.default?.());
            },
        });

    return {
        Sidebar: passthrough('aside'),
        SidebarContent: passthrough(),
        SidebarFooter: passthrough('footer'),
        SidebarGroup: passthrough('section'),
        SidebarGroupAction: passthrough(),
        SidebarGroupContent: passthrough(),
        SidebarGroupLabel: passthrough(),
        SidebarHeader: passthrough('header'),
        SidebarInput: defineComponent({
            props: {
                modelValue: String,
                placeholder: String,
            },
            emits: ['update:modelValue'],
            setup(props, { attrs, emit }) {
                return () =>
                    h('input', {
                        ...attrs,
                        value: props.modelValue,
                        placeholder: props.placeholder,
                        onInput: (event: Event) => emit('update:modelValue', (event.target as HTMLInputElement).value),
                    });
            },
        }),
        SidebarMenu: passthrough('ul'),
        SidebarMenuButton: defineComponent({
            props: {
                asChild: Boolean,
                disabled: Boolean,
                isActive: Boolean,
                tooltip: String,
                type: String,
            },
            setup(props, { attrs, slots }) {
                return () =>
                    h(
                        props.asChild ? 'span' : 'button',
                        {
                            type: props.asChild ? undefined : (props.type ?? 'button'),
                            disabled: props.disabled,
                            'data-active': String(Boolean(props.isActive)),
                            ...attrs,
                        },
                        slots.default?.(),
                    );
            },
        }),
        SidebarMenuItem: passthrough('li'),
    };
});

describe('AppSidebar', () => {
    beforeEach(() => {
        vi.useRealTimers();
        mockPage.url = '/organisations';
        mockPage.props.sidebarOrganisations = [
            { id: 3, name: 'Northwind Organisation', isOwner: true },
            { id: 4, name: 'Northwind Studio', isOwner: false },
        ];
        mockPage.props.sidebarOrganisationsHasMore = false;
        fetchMock.mockReset();
        vi.stubGlobal('fetch', fetchMock);
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.unstubAllGlobals();
    });

    function mountSidebar() {
        return mount(AppSidebar, {
            global: {
                config: {
                    globalProperties: {
                        route: vi.fn(() => '/dashboard'),
                    },
                },
            },
        });
    }

    it('links organisations to their scoped dashboard from the root sidebar', () => {
        const wrapper = mountSidebar();

        expect(wrapper.text()).toContain('Dashboard');
        expect(wrapper.text()).toContain('Requirements');
        expect(wrapper.text()).toContain('Northwind Organisation');
        expect(wrapper.text()).toContain('Northwind Studio');
        expect(wrapper.text()).toContain('shared');
        expect(wrapper.find('a[href="/requirements"]').exists()).toBe(true);
        expect(wrapper.find('a[href="/organisation/3/dashboard"]').exists()).toBe(true);
        expect(wrapper.find('a[href="/organisation/4/dashboard"]').exists()).toBe(true);
    });

    it('hides the organisation show more link when the sidebar list is complete', () => {
        const wrapper = mountSidebar();

        expect(wrapper.text()).not.toContain('Show more');
        expect(wrapper.find('a[href="/organisations"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="sidebar-organisations-search"]').exists()).toBe(false);
    });

    it('loads more sidebar organisations inline instead of linking to the organisations page', async () => {
        mockPage.props.sidebarOrganisations = [
            { id: 1, name: 'Atlas Commerce', isOwner: true },
            { id: 2, name: 'Cedar Labs', isOwner: false },
            { id: 3, name: 'Northwind Organisation', isOwner: true },
            { id: 4, name: 'Northwind Studio', isOwner: true },
            { id: 5, name: 'QA Org', isOwner: true },
        ];
        mockPage.props.sidebarOrganisationsHasMore = true;
        let resolveFetch: (value: Response) => void = () => {};
        fetchMock.mockReturnValue(
            new Promise((resolve) => {
                resolveFetch = resolve;
            }),
        );
        const wrapper = mountSidebar();

        expect(wrapper.find('a[href="/organisations"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="sidebar-organisations-search"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('Show more');

        await wrapper.get('[data-testid="sidebar-organisations-show-more"]').trigger('click');

        expect(fetchMock).toHaveBeenCalledWith(
            '/organisations/sidebar?offset=5&limit=5',
            expect.objectContaining({ headers: { Accept: 'application/json' } }),
        );
        expect(wrapper.text()).toContain('Loading...');

        resolveFetch({
            ok: true,
            json: async () => ({
                items: [{ id: 6, name: 'Zephyr Console', isOwner: true }],
                hasMore: false,
            }),
        } as Response);
        await flushPromises();

        expect(wrapper.find('a[href="/organisation/6/dashboard"]').exists()).toBe(true);
        expect(wrapper.text()).not.toContain('Show more');
    });

    it('debounces sidebar organisation search through the backend with a larger page size', async () => {
        vi.useFakeTimers();
        mockPage.props.sidebarOrganisationsHasMore = true;
        fetchMock.mockResolvedValue({
            ok: true,
            json: async () => ({
                items: [{ id: 8, name: 'Cedar Labs', isOwner: false }],
                hasMore: false,
            }),
        });
        const wrapper = mountSidebar();

        await wrapper.get('[data-testid="sidebar-organisations-search"]').setValue('cedar');
        await vi.advanceTimersByTimeAsync(300);
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith(
            '/organisations/sidebar?search=cedar&limit=10',
            expect.objectContaining({ headers: { Accept: 'application/json' } }),
        );
        expect(wrapper.find('a[href="/organisation/8/dashboard"]').exists()).toBe(true);
        expect(wrapper.find('a[href="/organisation/3/dashboard"]').exists()).toBe(false);
    });

    it('shows owned organisation navigation from a scoped route', () => {
        mockPage.url = '/organisation/3/dashboard';
        const wrapper = mountSidebar();

        expect(wrapper.text()).toContain('Back');
        expect(wrapper.text()).toContain('Northwind Organisation');
        expect(wrapper.text()).toContain('Dashboard');
        expect(wrapper.text()).toContain('Tasks');
        expect(wrapper.text()).toContain('Requirements');
        expect(wrapper.text()).toContain('Clients');
        expect(wrapper.text()).toContain('Projects');
        expect(wrapper.text()).toContain('Team');
        expect(wrapper.text()).toContain('Settings');
        expect(wrapper.text()).not.toContain('Navigation');

        expect(wrapper.find('a[href="/dashboard"]').exists()).toBe(true);
        expect(wrapper.find('a[href="/organisation/3/dashboard"]').exists()).toBe(true);
        expect(wrapper.find('a[href="/organisation/3/tasks"]').exists()).toBe(true);
        expect(wrapper.find('a[href="/organisation/3/requirements"]').exists()).toBe(true);
        expect(wrapper.find('a[href="/organisation/3/clients"]').exists()).toBe(true);
        expect(wrapper.find('a[href="/organisation/3/projects"]').exists()).toBe(true);
        expect(wrapper.find('a[href="/organisation/3/team"]').exists()).toBe(true);
        expect(wrapper.find('a[href="/organisation/3/settings"]').exists()).toBe(true);
    });

    it('keeps organisation team and settings active states distinct', () => {
        mockPage.url = '/organisation/3/team';
        const teamWrapper = mountSidebar();

        const teamLink = teamWrapper.get('a[href="/organisation/3/team"]');
        const settingsLink = teamWrapper.get('a[href="/organisation/3/settings"]');

        expect(teamLink.element.parentElement?.getAttribute('data-active')).toBe('true');
        expect(settingsLink.element.parentElement?.getAttribute('data-active')).toBe('false');

        mockPage.url = '/organisation/3/settings';
        const settingsWrapper = mountSidebar();

        const activeTeamLink = settingsWrapper.get('a[href="/organisation/3/team"]');
        const activeSettingsLink = settingsWrapper.get('a[href="/organisation/3/settings"]');

        expect(activeTeamLink.element.parentElement?.getAttribute('data-active')).toBe('false');
        expect(activeSettingsLink.element.parentElement?.getAttribute('data-active')).toBe('true');
    });

    it('shows only shared organisation links available to non-owners', () => {
        mockPage.url = '/organisation/4/dashboard';
        const wrapper = mountSidebar();

        expect(wrapper.text()).toContain('shared');
        expect(wrapper.text()).toContain('Dashboard');
        expect(wrapper.text()).toContain('Tasks');
        expect(wrapper.text()).toContain('Requirements');
        expect(wrapper.text()).toContain('Projects');
        expect(wrapper.text()).not.toContain('Clients');
        expect(wrapper.text()).not.toContain('Team');
        expect(wrapper.text()).not.toContain('Settings');
        expect(wrapper.find('a[href="/organisation/4/dashboard"]').exists()).toBe(true);
        expect(wrapper.find('a[href="/organisation/4/tasks"]').exists()).toBe(true);
        expect(wrapper.find('a[href="/organisation/4/requirements"]').exists()).toBe(true);
        expect(wrapper.find('a[href="/organisation/4/projects"]').exists()).toBe(true);
    });
});
