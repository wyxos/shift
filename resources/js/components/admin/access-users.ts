export type AccessUserCandidate = {
    id: number;
    name: string;
    email: string;
};

export type ManagedAccessUser = {
    id: number;
    user_id?: number | null;
    user_name?: string | null;
    user_email?: string | null;
    registration_status?: string | null;
};

export function deriveNameFromEmail(email: string) {
    const localPart = email.split('@')[0]?.trim();

    if (!localPart) {
        return '';
    }

    return localPart
        .split(/[._-]+/)
        .filter(Boolean)
        .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
        .join(' ');
}

export function accessUserDisplayName(user: ManagedAccessUser) {
    const name = user.user_name?.trim() || 'Unknown user';
    const email = user.user_email?.trim();

    return email ? `${name} (${email})` : name;
}

export function accessStatus(user: ManagedAccessUser) {
    if (user.registration_status) {
        return user.registration_status;
    }

    return user.user_id ? 'registered' : 'pending';
}

export function accessStatusLabel(user: ManagedAccessUser) {
    return accessStatus(user) === 'registered' ? 'Registered' : 'Pending invitation';
}

export function accessStatusBadgeClass(user: ManagedAccessUser) {
    return accessStatus(user) === 'registered'
        ? ''
        : 'border-transparent bg-amber-100 text-amber-900 hover:bg-amber-100 dark:bg-amber-500/15 dark:text-amber-200';
}
