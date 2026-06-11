<script lang="ts" setup>
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupAction,
    SidebarGroupContent,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { type NavItem, type SharedData, type SidebarOrganisation } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Briefcase, Folder, Inbox, LayoutGrid, ListTodo, Network, Plus, Settings, Users } from 'lucide-vue-next';
import { computed } from 'vue';
import AppLogo from './AppLogo.vue';

const page = usePage<SharedData>();

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
        icon: LayoutGrid,
    },
    {
        title: 'Tasks',
        href: '/tasks',
        icon: Folder,
    },
    {
        title: 'Requirements',
        href: '/requirements',
        icon: Inbox,
    },
];

const footerNavItems: NavItem[] = [];

const organisations = computed(() => page.props.sidebarOrganisations ?? []);
const hasMoreOrganisations = computed(() => page.props.sidebarOrganisationsHasMore ?? false);

const routeSelectedOrganisationId = computed(() => {
    const current = new URL(page.url, 'https://shift.test');
    const routeOrganisationId = organisationIdFromScopedPath(current);

    if (routeOrganisationId !== null) {
        return routeOrganisationId;
    }

    const id =
        current.searchParams.get('organisation_id') ??
        current.searchParams.get('team') ??
        current.searchParams.get('manage') ??
        current.searchParams.get('settings');

    return id === null ? null : Number(id);
});

const activeOrganisation = computed(() => {
    const id = routeSelectedOrganisationId.value;

    if (!Number.isFinite(id)) {
        return null;
    }

    return organisations.value.find((organisation) => organisation.id === id) ?? null;
});

const organisationNavItems = computed(() => {
    const organisation = activeOrganisation.value;

    if (!organisation) {
        return [];
    }

    return [
        {
            title: 'Dashboard',
            href: organisationPageHref(organisation, 'dashboard'),
            icon: LayoutGrid,
            isVisible: true,
        },
        {
            title: 'Tasks',
            href: organisationPageHref(organisation, 'tasks'),
            icon: ListTodo,
            isVisible: true,
        },
        {
            title: 'Requirements',
            href: organisationPageHref(organisation, 'requirements'),
            icon: Inbox,
            isVisible: true,
        },
        {
            title: 'Clients',
            href: organisationPageHref(organisation, 'clients'),
            icon: Briefcase,
            isVisible: canManageOrgAccess(organisation),
        },
        {
            title: 'Projects',
            href: organisationPageHref(organisation, 'projects'),
            icon: Folder,
            isVisible: true,
        },
        {
            title: 'Team',
            href: organisationTeamHref(organisation),
            icon: Users,
            isVisible: canManageOrgAccess(organisation),
        },
        {
            title: 'Settings',
            href: organisationSettingsHref(organisation),
            icon: Settings,
            isVisible: canManageOrgAccess(organisation),
        },
    ].filter((item) => item.isVisible);
});

function canManageOrgAccess(organisation: SidebarOrganisation) {
    return organisation.can_manage_org_access ?? organisation.isOwner;
}

function organisationTeamHref(organisation: SidebarOrganisation) {
    return organisationPageHref(organisation, 'team');
}

function organisationSettingsHref(organisation: SidebarOrganisation) {
    return organisationPageHref(organisation, 'settings');
}

function organisationPageHref(organisation: SidebarOrganisation, page: string) {
    return `/organisation/${organisation.id}/${page}`;
}

function isOrganisationItemActive(organisation: SidebarOrganisation) {
    return activeOrganisation.value?.id === organisation.id;
}

function isOrganisationNavActive(href: string) {
    const target = new URL(href, 'https://shift.test');
    const current = new URL(page.url, 'https://shift.test');
    const currentState = organisationRouteState(current);
    const targetState = organisationRouteState(target);

    return currentState !== 'none' && currentState === targetState;
}

function organisationIdFromScopedPath(url: URL) {
    const match = url.pathname.match(/^\/organisation\/(\d+)(?:\/|$)/);

    if (!match) {
        return null;
    }

    const id = Number(match[1]);

    return Number.isFinite(id) ? id : null;
}

function organisationRouteState(url: URL) {
    const scopedMatch = url.pathname.match(/^\/organisation\/(\d+)\/(dashboard|tasks|requirements|clients|projects|team|settings)$/);

    if (scopedMatch) {
        return `${scopedMatch[2]}:${scopedMatch[1]}`;
    }

    const organisationId = url.searchParams.get('organisation_id');

    if (organisationId) {
        const page = url.pathname.replace(/^\//, '');

        return `${page}:${organisationId}`;
    }

    const teamId = url.searchParams.get('team') ?? url.searchParams.get('manage');

    if (teamId) {
        return `team:${teamId}`;
    }

    const settingsId = url.searchParams.get('settings');

    if (settingsId) {
        return `settings:${settingsId}`;
    }

    return 'none';
}
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton as-child size="lg">
                        <Link :href="route('dashboard')">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <Transition mode="out-in" name="sidebar-nav-slide">
                <div v-if="activeOrganisation" :key="`organisation-${activeOrganisation.id}`">
                    <SidebarGroup class="px-2 py-0">
                        <SidebarGroupContent class="pb-1">
                            <SidebarMenu>
                                <SidebarMenuItem>
                                    <SidebarMenuButton as-child size="sm" tooltip="Back">
                                        <Link href="/dashboard">
                                            <ArrowLeft />
                                            <span>Back</span>
                                        </Link>
                                    </SidebarMenuButton>
                                </SidebarMenuItem>
                            </SidebarMenu>
                        </SidebarGroupContent>
                        <SidebarGroupLabel class="h-auto min-h-8 flex-col items-start justify-center gap-0.5 py-1.5 leading-tight">
                            <span class="text-sidebar-foreground block max-w-full truncate">{{ activeOrganisation.name }}</span>
                            <span v-if="!activeOrganisation.isOwner" class="text-sidebar-foreground/60 text-[11px] leading-none font-normal">
                                shared
                            </span>
                        </SidebarGroupLabel>
                        <SidebarGroupContent>
                            <SidebarMenu>
                                <SidebarMenuItem v-for="item in organisationNavItems" :key="item.title">
                                    <SidebarMenuButton as-child :is-active="isOrganisationNavActive(item.href)" :tooltip="item.title">
                                        <Link :href="item.href">
                                            <component :is="item.icon" />
                                            <span>{{ item.title }}</span>
                                        </Link>
                                    </SidebarMenuButton>
                                </SidebarMenuItem>
                            </SidebarMenu>
                        </SidebarGroupContent>
                    </SidebarGroup>
                </div>

                <div v-else key="sidebar-root">
                    <NavMain :items="mainNavItems" />

                    <SidebarGroup class="px-2 py-0">
                        <SidebarGroupLabel>Organisation</SidebarGroupLabel>
                        <SidebarGroupAction as-child class="top-1.5">
                            <Link aria-label="Add organisation" href="/organisations?create=1" title="Add organisation">
                                <Plus />
                            </Link>
                        </SidebarGroupAction>
                        <SidebarGroupContent>
                            <SidebarMenu>
                                <SidebarMenuItem v-for="organisation in organisations" :key="organisation.id">
                                    <SidebarMenuButton as-child :is-active="isOrganisationItemActive(organisation)" :tooltip="organisation.name">
                                        <Link :href="organisationPageHref(organisation, 'dashboard')">
                                            <Network />
                                            <span class="min-w-0">
                                                <span class="block truncate">{{ organisation.name }}</span>
                                                <span
                                                    v-if="!organisation.isOwner"
                                                    class="text-muted-foreground mt-0.5 block text-[11px] leading-none"
                                                >
                                                    shared
                                                </span>
                                            </span>
                                        </Link>
                                    </SidebarMenuButton>
                                </SidebarMenuItem>
                                <SidebarMenuItem v-if="organisations.length === 0">
                                    <SidebarMenuButton as-child tooltip="Add organisation">
                                        <Link href="/organisations?create=1">
                                            <Plus />
                                            <span>Add organisation</span>
                                        </Link>
                                    </SidebarMenuButton>
                                </SidebarMenuItem>
                                <SidebarMenuItem v-if="hasMoreOrganisations">
                                    <Link
                                        class="text-muted-foreground hover:text-foreground focus-visible:ring-sidebar-ring block rounded-sm px-2 py-1 pl-8 text-xs leading-5 underline-offset-4 transition group-data-[collapsible=icon]:hidden hover:underline focus-visible:ring-2 focus-visible:outline-hidden"
                                        href="/organisations"
                                    >
                                        Show more
                                    </Link>
                                </SidebarMenuItem>
                            </SidebarMenu>
                        </SidebarGroupContent>
                    </SidebarGroup>
                </div>
            </Transition>
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>

<style scoped>
.sidebar-nav-slide-enter-active,
.sidebar-nav-slide-leave-active {
    transition:
        opacity 160ms ease,
        transform 160ms ease;
}

.sidebar-nav-slide-enter-from {
    opacity: 0;
    transform: translateX(0.75rem);
}

.sidebar-nav-slide-leave-to {
    opacity: 0;
    transform: translateX(-0.75rem);
}

@media (prefers-reduced-motion: reduce) {
    .sidebar-nav-slide-enter-active,
    .sidebar-nav-slide-leave-active {
        transition: none;
    }
}
</style>
