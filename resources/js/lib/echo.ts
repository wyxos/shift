import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

const key = typeof document === 'undefined' ? undefined : document.querySelector<HTMLMetaElement>('meta[name="reverb-key"]')?.content;

if (typeof window !== 'undefined' && typeof key === 'string' && key !== '') {
    const scheme = document.querySelector<HTMLMetaElement>('meta[name="reverb-scheme"]')?.content || window.location.protocol.replace(':', '');
    const host = document.querySelector<HTMLMetaElement>('meta[name="reverb-host"]')?.content || window.location.hostname;
    const port = Number(document.querySelector<HTMLMetaElement>('meta[name="reverb-port"]')?.content || (scheme === 'https' ? 443 : 80));
    const PusherConstructor = ((Pusher as unknown as { Pusher?: typeof Pusher; default?: typeof Pusher }).Pusher ??
        (Pusher as unknown as { default?: typeof Pusher }).default ??
        Pusher) as typeof Pusher;

    window.Pusher = PusherConstructor;
    window.Echo = new Echo({
        broadcaster: 'reverb',
        key,
        wsHost: host,
        wsPort: port,
        wssPort: port,
        forceTLS: scheme === 'https',
        enabledTransports: ['ws', 'wss'],
    }) as unknown as Window['Echo'];
}
