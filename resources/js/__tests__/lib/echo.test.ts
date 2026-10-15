import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const { echoConstructor } = vi.hoisted(() => ({ echoConstructor: vi.fn() }));

vi.mock('laravel-echo', () => ({ default: echoConstructor }));
vi.mock('pusher-js', () => ({ default: class Pusher {} }));

function setConnectionMeta(values: Record<string, string>) {
    for (const [name, content] of Object.entries(values)) {
        const meta = document.createElement('meta');
        meta.name = `reverb-${name}`;
        meta.content = content;
        document.head.append(meta);
    }
}

describe('Echo connection configuration', () => {
    beforeEach(() => {
        vi.resetModules();
        echoConstructor.mockClear();
        vi.stubEnv('VITE_REVERB_APP_KEY', 'local-build-key');
        vi.stubEnv('VITE_REVERB_HOST', 'localhost');
        vi.stubEnv('VITE_REVERB_PORT', '8080');
        vi.stubEnv('VITE_REVERB_SCHEME', 'http');
    });

    afterEach(() => {
        document.head.querySelectorAll('meta[name^="reverb-"]').forEach((meta) => meta.remove());
        delete window.Echo;
        delete window.Pusher;
        vi.unstubAllEnvs();
    });

    it('uses the serving application settings even when built with local development settings', async () => {
        setConnectionMeta({ key: 'production-key', host: 'portal.example.com', port: '443', scheme: 'https' });

        await import('../../lib/echo');

        expect(echoConstructor).toHaveBeenCalledWith(
            expect.objectContaining({
                key: 'production-key',
                wsHost: 'portal.example.com',
                wsPort: 443,
                wssPort: 443,
                forceTLS: true,
            }),
        );
    });

    it('does not connect when the server has not enabled Reverb', async () => {
        await import('../../lib/echo');

        expect(echoConstructor).not.toHaveBeenCalled();
    });

    it('supports the serving application local development endpoint', async () => {
        setConnectionMeta({ key: 'development-key', host: 'localhost', port: '8080', scheme: 'http' });

        await import('../../lib/echo');

        expect(echoConstructor).toHaveBeenCalledWith(
            expect.objectContaining({
                key: 'development-key',
                wsHost: 'localhost',
                wsPort: 8080,
                forceTLS: false,
            }),
        );
    });
});
