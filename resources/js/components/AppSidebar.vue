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
    SidebarInput,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { type NavItem, type SharedData, type SidebarOrganisation } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Briefcase, Folder, Inbox, LayoutGrid, ListTodo, LoaderCircle, Network, Plus, Settings, Users } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
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

const organisations = ref<SidebarOrganisation[]>([]);
const hasMoreOrganisations = ref(false);
const canSearchOrganisations = ref(false);
const organisationSearch = ref('');
const organisationsLoading = ref(false);
const organisationsError = ref<string | null>(null);
let organisationRequestId = 0;
let organisationSearchTimeout: ReturnType<typeof setTimeout> | null = null;

watch(
    () => [page.props.sidebarOrganisations, page.props.sidebarOrganisationsHasMore] as const,
    ([items, hasMore]) => {
        organisations.value = [...(items ?? [])];
        hasMoreOrganisations.value = Boolean(hasMore);
        canSearchOrganisations.value = Boolean(hasMore);
        organisationSearch.value = '';
        organisationsError.value = null;
    },
    { immediate: true },
);

watch(organisationSearch, (value) => {
    if (organisationSearchTimeout) {
        clearTimeout(organisationSearchTimeout);
        organisationSearchTimeout = null;
    }

    const search = value.trim();

    if (!search) {
        organisationRequestId += 1;
        organisations.value = [...(page.props.sidebarOrganisations ?? [])];
        hasMoreOrganisations.value = Boolean(page.props.sidebarOrganisationsHasMore);
        organisationsLoading.value = false;
        organisationsError.value = null;

        return;
    }

    organisationSearchTimeout = setTimeout(() => {
        void loadOrganisations({
            append: false,
            limit: 10,
            search,
        });
    }, 300);
});

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

async function showMoreOrganisations() {
    await loadOrganisations({
        append: true,
        limit: organisationSearch.value.trim() ? 10 : 5,
        offset: organisations.value.length,
        search: organisationSearch.value.trim(),
    });
}

async function loadOrganisations({ append, limit, offset = 0, search = '' }: { append: boolean; limit: number; offset?: number; search?: string }) {
    const requestId = ++organisationRequestId;
    organisationsLoading.value = true;
    organisationsError.value = null;

    try {
        const response = await fetch(sidebarOrganisationsUrl({ limit, offset, search }), {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            throw new Error('Failed to load organisations.');
        }

        const payload = (await response.json()) as {
            items?: SidebarOrganisation[];
            hasMore?: boolean;
        };

        if (requestId !== organisationRequestId) {
            return;
        }

        const nextItems = payload.items ?? [];
        organisations.value = append ? mergeOrganisations(organisations.value, nextItems) : nextItems;
        hasMoreOrganisations.value = Boolean(payload.hasMore);
    } catch {
        if (requestId === organisationRequestId) {
            organisationsError.value = 'Unable to load organisations.';
        }
    } finally {
        if (requestId === organisationRequestId) {
            organisationsLoading.value = false;
        }
    }
}

function sidebarOrganisationsUrl({ limit, offset, search }: { limit: number; offset: number; search: string }) {
    const params = new URLSearchParams();

    if (search) {
        params.set('search', search);
    }

    if (offset > 0) {
        params.set('offset', String(offset));
    }

    params.set('limit', String(limit));

    return `/organisations/sidebar?${params.toString()}`;
}

function mergeOrganisations(existing: SidebarOrganisation[], incoming: SidebarOrganisation[]) {
    const seen = new Set(existing.map((organisation) => organisation.id));

    return [
        ...existing,
        ...incoming.filter((organisation) => {
            if (seen.has(organisation.id)) {
                return false;
            }

            seen.add(organisation.id);

            return true;
        }),
    ];
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
                                <SidebarMenuItem v-if="canSearchOrganisations" class="group-data-[collapsible=icon]:hidden">
                                    <SidebarInput
                                        v-model="organisationSearch"
                                        data-testid="sidebar-organisations-search"
                                        placeholder="Search organisations"
                                        type="search"
                                    />
                                </SidebarMenuItem>
                                <TransitionGroup name="sidebar-organisation-list">
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
                                </TransitionGroup>
                                <SidebarMenuItem v-if="organisations.length === 0">
                                    <SidebarMenuButton as-child tooltip="Add organisation">
                                        <Link href="/organisations?create=1">
                                            <Plus />
                                            <span>Add organisation</span>
                                        </Link>
                                    </SidebarMenuButton>
                                </SidebarMenuItem>
                                <SidebarMenuItem v-if="organisationsError">
                                    <p class="text-destructive px-2 py-1 pl-8 text-xs leading-5 group-data-[collapsible=icon]:hidden">
                                        {{ organisationsError }}
                                    </p>
                                </SidebarMenuItem>
                                <SidebarMenuItem v-if="hasMoreOrganisations">
                                    <button
                                        class="text-muted-foreground hover:text-foreground focus-visible:ring-sidebar-ring flex w-full items-center gap-1 rounded-sm px-2 py-1 pl-8 text-left text-xs leading-5 underline-offset-4 transition group-data-[collapsible=icon]:hidden hover:underline focus-visible:ring-2 focus-visible:outline-hidden disabled:pointer-events-none disabled:opacity-70"
                                        data-testid="sidebar-organisations-show-more"
                                        type="button"
                                        :disabled="organisationsLoading"
                                        @click="showMoreOrganisations"
                                    >
                                        <LoaderCircle v-if="organisationsLoading" class="h-3 w-3 animate-spin" />
                                        {{ organisationsLoading ? 'Loading...' : 'Show more' }}
                                    </button>
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
.sidebar-nav-slide-leave-active,
.sidebar-organisation-list-enter-active,
.sidebar-organisation-list-leave-active {
    transition:
        opacity 160ms ease,
        transform 160ms ease;
}

.sidebar-nav-slide-enter-from,
.sidebar-organisation-list-enter-from {
    opacity: 0;
    transform: translateX(0.75rem);
}

.sidebar-nav-slide-leave-to,
.sidebar-organisation-list-leave-to {
    opacity: 0;
    transform: translateX(-0.75rem);
}

@media (prefers-reduced-motion: reduce) {
    .sidebar-nav-slide-enter-active,
    .sidebar-nav-slide-leave-active,
    .sidebar-organisation-list-enter-active,
    .sidebar-organisation-list-leave-active {
        transition: none;
    }
}
</style>
