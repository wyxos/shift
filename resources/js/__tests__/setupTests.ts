import { vi } from 'vitest';
import { h } from 'vue';

// Create a global reactive form state that can be modified during tests
// Make it global so it's accessible in all test files
global.formState = {
    // Common form fields
    email: '',
    password: '',
    password_confirmation: '',
    current_password: '',
    name: '',
    remember: false,

    // Form state
    errors: {},
    processing: false,
    recentlySuccessful: false,

    // Reset the form state to defaults
    reset() {
        this.email = '';
        this.password = '';
        this.password_confirmation = '';
        this.current_password = '';
        this.name = '';
        this.remember = false;
        this.errors = {};
        this.processing = false;
        this.recentlySuccessful = false;
    },
};

// Mock ziggy-js module
vi.mock('ziggy-js', () => {
    const routeFn = vi.fn((name) => `/${name}`);
    return {
        route: routeFn,
        ZiggyVue: {
            install: (app) => {
                app.config.globalProperties.route = routeFn;
            },
        },
    };
});

// Add global route function
global.route = vi.fn((name) => `/${name}`);

// Global mock for Inertia.js
vi.mock('@inertiajs/vue3', () => {
    const useFormMock = vi.fn(() => ({
        ...global.formState,
        post: vi.fn(),
        put: vi.fn(),
        patch: vi.fn(),
        delete: vi.fn(),
        reset: vi.fn(() => global.formState.reset()),
    }));

    return {
        Head: {
            render: () => {},
        },
        Link: {
            props: ['href'],
            render() {
                return h('a', { href: this.href }, this.$slots.default?.());
            },
        },
        router: {
            get: vi.fn(),
            post: vi.fn(),
            put: vi.fn(),
            patch: vi.fn(),
            delete: vi.fn(),
            visit: vi.fn(),
        },
        useForm: useFormMock,
        // Export the form state so tests can modify it
        _formState: global.formState,
        // Add route function mock
        route: vi.fn((name) => `/${name}`),
    };
});
