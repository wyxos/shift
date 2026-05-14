import AppSidebar from '@/components/AppSidebar.vue';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { mockPage } = vi.hoisted(() => ({
    mockPage: {
        url: '/organisations',
        props: {
            sidebarOrganisations: [
                { id: 3, name: 'Northwind Organisation', isOwner: true },
                { id: 4, name: 'Northwind Studio', isOwner: false },
            ],
        },
    },
}));

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
        SidebarMenu: passthrough('ul'),
        SidebarMenuButton: defineComponent({
            props: {
                asChild: Boolean,
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
        mockPage.url = '/organisations';
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
        expect(wrapper.text()).toContain('Northwind Organisation');
        expect(wrapper.text()).toContain('Northwind Studio');
        expect(wrapper.text()).toContain('shared');
        expect(wrapper.find('a[href="/dashboard?organisation_id=3"]').exists()).toBe(true);
        expect(wrapper.find('a[href="/dashboard?organisation_id=4"]').exists()).toBe(true);
    });

    it('shows owned organisation navigation from a scoped route', () => {
        mockPage.url = '/dashboard?organisation_id=3';
        const wrapper = mountSidebar();

        expect(wrapper.text()).toContain('Northwind Organisation');
        expect(wrapper.text()).toContain('Dashboard');
        expect(wrapper.text()).toContain('Tasks');
        expect(wrapper.text()).toContain('Clients');
        expect(wrapper.text()).toContain('Projects');
        expect(wrapper.text()).toContain('Team');
        expect(wrapper.text()).toContain('Settings');
        expect(wrapper.text()).not.toContain('Navigation');

        expect(wrapper.find('a[href="/dashboard?organisation_id=3"]').exists()).toBe(true);
        expect(wrapper.find('a[href="/tasks?organisation_id=3"]').exists()).toBe(true);
        expect(wrapper.find('a[href="/clients?organisation_id=3"]').exists()).toBe(true);
        expect(wrapper.find('a[href="/projects?organisation_id=3"]').exists()).toBe(true);
        expect(wrapper.find('a[href="/organisations?search=Northwind%20Organisation&manage=3"]').exists()).toBe(true);
        expect(wrapper.find('a[href="/organisations?search=Northwind%20Organisation&settings=3"]').exists()).toBe(true);
    });

    it('shows only shared organisation links available to non-owners', () => {
        mockPage.url = '/dashboard?organisation_id=4';
        const wrapper = mountSidebar();

        expect(wrapper.text()).toContain('shared');
        expect(wrapper.text()).toContain('Dashboard');
        expect(wrapper.text()).toContain('Tasks');
        expect(wrapper.text()).toContain('Projects');
        expect(wrapper.text()).not.toContain('Clients');
        expect(wrapper.text()).not.toContain('Team');
        expect(wrapper.text()).not.toContain('Settings');
        expect(wrapper.find('a[href="/dashboard?organisation_id=4"]').exists()).toBe(true);
        expect(wrapper.find('a[href="/tasks?organisation_id=4"]').exists()).toBe(true);
        expect(wrapper.find('a[href="/projects?organisation_id=4"]').exists()).toBe(true);
    });
});
