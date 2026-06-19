import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

const key = import.meta.env.VITE_REVERB_APP_KEY;

if (typeof window !== 'undefined' && typeof key === 'string' && key !== '') {
    const scheme = String(import.meta.env.VITE_REVERB_SCHEME || window.location.protocol.replace(':', '') || 'https');
    const host = String(import.meta.env.VITE_REVERB_HOST || window.location.hostname);
    const port = Number(import.meta.env.VITE_REVERB_PORT || (scheme === 'https' ? 443 : 80));
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
