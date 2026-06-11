import { beforeEach, describe, expect, it, vi } from 'vitest';

const mocks = vi.hoisted(() => ({
    createApp: vi.fn(),
    createInertiaApp: vi.fn(),
    initializeTheme: vi.fn(),
    resolvePageComponent: vi.fn(),
    toaster: { name: 'ToasterStub' },
    ziggyVue: { install: vi.fn() },
}));

vi.mock('@inertiajs/vue3', () => ({
    createInertiaApp: mocks.createInertiaApp,
}));

vi.mock('laravel-vite-plugin/inertia-helpers', () => ({
    resolvePageComponent: mocks.resolvePageComponent,
}));

vi.mock('vue', async () => {
    const actual = await vi.importActual<typeof import('vue')>('vue');

    return {
        ...actual,
        createApp: mocks.createApp,
    };
});

vi.mock('vue-sonner', () => ({
    Toaster: mocks.toaster,
}));

vi.mock('ziggy-js', () => ({
    ZiggyVue: mocks.ziggyVue,
}));

vi.mock('../composables/useAppearance', () => ({
    initializeTheme: mocks.initializeTheme,
}));

describe('app bootstrap', () => {
    beforeEach(() => {
        vi.resetModules();

        mocks.createApp.mockReset();
        mocks.createInertiaApp.mockReset();
        mocks.initializeTheme.mockReset();
        mocks.resolvePageComponent.mockReset();
        mocks.ziggyVue.install.mockReset();

        mocks.createInertiaApp.mockImplementation((options) => {
            options.setup({
                el: document.createElement('div'),
                App: { name: 'InertiaAppStub' },
                props: { initialPage: true },
                plugin: { install: vi.fn() },
            });
        });

        mocks.createApp.mockReturnValue({
            use: vi.fn().mockReturnThis(),
            mount: vi.fn(),
        });
    });

    it('mounts bottom-center toast notifications', async () => {
        await import('../app');

        const rootComponent = mocks.createApp.mock.calls[0][0];
        const rendered = rootComponent.render();
        const toaster = rendered.children.find((child: any) => child.type === mocks.toaster);

        expect(toaster.props).toMatchObject({
            richColors: true,
            position: 'bottom-center',
        });
    });
});
