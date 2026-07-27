const navigationBaseUrl = 'https://shift.local';

export function navigationPath(url: string) {
    const pathname = new URL(url, navigationBaseUrl).pathname.replace(/\/+$/, '');

    return pathname || '/';
}

export function isNavigationPathActive(currentUrl: string, href: string) {
    return navigationPath(currentUrl) === navigationPath(href);
}
