import type { SharedData } from '@/types';
import { createInertiaApp } from '@inertiajs/vue3';
import createServer from '@inertiajs/vue3/server';
import { renderToString } from '@vue/server-renderer';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import type { DefineComponent } from 'vue';
import { createSSRApp, h } from 'vue';
import { route as ziggyRoute } from 'ziggy-js';

const appName = import.meta.env.VITE_APP_NAME === 'Laravel' ? 'SHIFT' : import.meta.env.VITE_APP_NAME || 'SHIFT';

createServer((page) =>
    createInertiaApp({
        page,
        render: renderToString,
        title: (title) => `${title} - ${appName}`,
        resolve: (name) => resolvePageComponent(`./pages/${name}.vue`, import.meta.glob<DefineComponent>('./pages/**/*.vue')),
        setup({ App, props, plugin }) {
            const app = createSSRApp({ render: () => h(App, props) });
            const ziggy = page.props.ziggy as SharedData['ziggy'];

            // Configure Ziggy for SSR...
            const ziggyConfig = {
                ...ziggy,
                location: new URL(ziggy.location),
            };

            // Create route function...
            const route = ((name?: string, params?: any, absolute?: boolean) =>
                ziggyRoute(name as string, params, absolute, ziggyConfig)) as typeof ziggyRoute;

            // Make route function available globally...
            app.config.globalProperties.route = route;

            // Make route function available globally for SSR...
            if (typeof window === 'undefined') {
                (globalThis as typeof globalThis & { route: typeof ziggyRoute }).route = route;
            }

            app.use(plugin);

            return app;
        },
    }),
);
