import { type NavItem } from '@/types';
import { Briefcase, Bug, Folder, Inbox, LayoutGrid } from 'lucide-vue-next';

export const mainNavItems: NavItem[] = [
    { title: 'Dashboard', href: '/dashboard', icon: LayoutGrid },
    { title: 'Tasks', href: '/tasks', icon: Folder },
    { title: 'App errors', href: '/error-reports', icon: Bug },
    { title: 'Requirements', href: '/requirements', icon: Inbox },
    { title: 'Projects', href: '/projects', icon: Briefcase },
];
