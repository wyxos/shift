import type Pusher from 'pusher-js';
import type { route as routeFn } from 'ziggy-js';

type ShiftEchoChannel = {
    notification: (callback: (notification: Record<string, unknown>) => void) => ShiftEchoChannel;
};

declare global {
    const route: typeof routeFn;

    interface Window {
        Echo?: {
            private: (channel: string) => ShiftEchoChannel;
            leave?: (channel: string) => void;
        };
        Pusher?: typeof Pusher;
    }
}
