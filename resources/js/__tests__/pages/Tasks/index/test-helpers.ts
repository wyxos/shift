import Index from '@/pages/Tasks/Index.vue';
import { router } from '@inertiajs/vue3';
import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, vi } from 'vitest';
import { h } from 'vue';

const axiosGetMock = vi.fn();
const axiosPutMock = vi.fn();
const axiosPostMock = vi.fn();
const axiosPatchMock = vi.fn();
const axiosDeleteMock = vi.fn();
const sonnerMocks = vi.hoisted(() => ({
    toastLoadingMock: vi.fn(() => 'autosave-toast'),
    toastSuccessMock: vi.fn(),
    toastErrorMock: vi.fn(),
    toastDismissMock: vi.fn(),
}));

vi.mock('axios', () => ({
    default: {
        get: (...args: any[]) => axiosGetMock(...args),
        put: (...args: any[]) => axiosPutMock(...args),
        post: (...args: any[]) => axiosPostMock(...args),
        patch: (...args: any[]) => axiosPatchMock(...args),
        delete: (...args: any[]) => axiosDeleteMock(...args),
    },
}));

vi.mock('vue-sonner', () => ({
    toast: {
        loading: sonnerMocks.toastLoadingMock,
        success: sonnerMocks.toastSuccessMock,
        error: sonnerMocks.toastErrorMock,
        dismiss: sonnerMocks.toastDismissMock,
    },
}));

vi.mock('@/layouts/AppLayout.vue', () => ({
    default: {
        props: ['breadcrumbs'],
        render() {
            return h('div', { class: 'app-layout' }, this.$slots.default?.());
        },
    },
}));

vi.mock('@/components/ui/button', () => ({
    Button: {
        props: ['variant', 'disabled', 'size'],
        render() {
            return h(
                'button',
                {
                    ...this.$attrs,
                    class: `button ${this.variant || ''} ${this.size || ''}`,
                    disabled: this.disabled,
                },
                this.$slots.default?.(),
            );
        },
    },
}));

vi.mock('@/components/ui/card', () => ({
    Card: {
        render() {
            return h('div', { class: 'card' }, this.$slots.default?.());
        },
    },
    CardHeader: {
        render() {
            return h('div', { class: 'card-header' }, this.$slots.default?.());
        },
    },
    CardTitle: {
        render() {
            return h('div', { class: 'card-title' }, this.$slots.default?.());
        },
    },
    CardContent: {
        render() {
            return h('div', { class: 'card-content' }, this.$slots.default?.());
        },
    },
}));

vi.mock('@/components/ui/badge', () => ({
    Badge: {
        props: ['variant'],
        render() {
            const attrs = this.$attrs as Record<string, unknown>;
            return h(
                'span',
                {
                    ...attrs,
                    class: `badge ${this.variant || ''} ${String(attrs.class ?? '')}`.trim(),
                },
                this.$slots.default?.(),
            );
        },
    },
}));

vi.mock('@/components/ui/input', () => ({
    Input: {
        props: ['modelValue', 'placeholder'],
        emits: ['update:modelValue'],
        render() {
            return h('input', {
                value: this.modelValue,
                placeholder: this.placeholder,
                onInput: (e) => this.$emit('update:modelValue', (e.target as HTMLInputElement).value),
            });
        },
    },
}));

vi.mock('@/components/ui/label', () => ({
    Label: {
        render() {
            return h('label', {}, this.$slots.default?.());
        },
    },
}));

vi.mock('@/components/ui/sheet', () => ({
    Sheet: {
        render() {
            return h('div', { class: 'sheet' }, this.$slots.default?.());
        },
    },
    SheetTrigger: {
        render() {
            return h('div', { class: 'sheet-trigger' }, this.$slots.default?.());
        },
    },
    SheetContent: {
        render() {
            return h('div', { class: 'sheet-content' }, this.$slots.default?.());
        },
    },
    SheetHeader: {
        render() {
            return h('div', { class: 'sheet-header' }, this.$slots.default?.());
        },
    },
    SheetTitle: {
        render() {
            return h('div', { class: 'sheet-title' }, this.$slots.default?.());
        },
    },
    SheetDescription: {
        render() {
            return h('div', { class: 'sheet-description' }, this.$slots.default?.());
        },
    },
    SheetFooter: {
        render() {
            return h('div', { class: 'sheet-footer' }, this.$slots.default?.());
        },
    },
}));

vi.mock('@/components/ui/button-group', () => ({
    ButtonGroup: {
        props: ['modelValue', 'options', 'disabled', 'testIdPrefix', 'columns', 'ariaLabel', 'class'],
        emits: ['update:modelValue'],
        render() {
            const options = Array.isArray((this as any).options) ? (this as any).options : [];
            const columnsClass = (this as any).columns === 2 ? 'grid-cols-2' : (this as any).columns === 4 ? 'grid-cols-4' : 'grid-cols-3';

            return h(
                'div',
                {
                    class: ['button-group-stub', 'grid', columnsClass, (this as any).class],
                    'aria-label': (this as any).ariaLabel,
                },
                options.map((option: any) =>
                    h(
                        'button',
                        {
                            type: 'button',
                            class: (this as any).modelValue === option.value ? (option.selectedClass ?? '') : (option.unselectedClass ?? ''),
                            disabled: (this as any).disabled,
                            'data-testid': (this as any).testIdPrefix ? `${(this as any).testIdPrefix}-${option.value}` : undefined,
                            onClick: () => (this as any).$emit('update:modelValue', option.value),
                        },
                        option.label,
                    ),
                ),
            );
        },
    },
}));

vi.mock('@/components/ui/dialog', () => ({
    Dialog: {
        render() {
            return h('div', { class: 'dialog-stub' }, this.$slots.default?.());
        },
    },
    DialogContent: {
        render() {
            return h('div', { class: 'dialog-content-stub' }, this.$slots.default?.());
        },
    },
}));

vi.mock('@/components/ShiftEditor.vue', () => ({
    default: {
        props: ['modelValue'],
        emits: ['update:modelValue', 'send', 'uploading'],
        render() {
            const previewText = String(this.modelValue ?? '').replace(/<[^>]+>/g, '');
            return h('div', { ...this.$attrs, class: 'shift-editor-stub' }, [
                h('textarea', {
                    'data-testid': 'stub-editor-input',
                    value: this.modelValue,
                    onInput: (e: Event) => this.$emit('update:modelValue', (e.target as HTMLTextAreaElement).value),
                }),
                h('div', { 'data-testid': 'stub-editor-preview' }, previewText),
                h(
                    'button',
                    {
                        type: 'button',
                        'data-testid': 'stub-send',
                        onClick: () => this.$emit('send', { html: this.modelValue ?? '<p>hello</p>', attachments: [] }),
                    },
                    'send',
                ),
            ]);
        },
    },
}));

vi.mock('@shared/components/ShiftEditor.vue', () => ({
    default: {
        props: ['modelValue'],
        emits: ['update:modelValue', 'send', 'uploading'],
        render() {
            const previewText = String(this.modelValue ?? '').replace(/<[^>]+>/g, '');
            return h(
                'div',
                { ...this.$attrs, class: 'shift-editor-stub' },
                [
                    h('textarea', {
                        'data-testid': 'stub-editor-input',
                        value: this.modelValue,
                        onInput: (e: Event) => this.$emit('update:modelValue', (e.target as HTMLTextAreaElement).value),
                    }),
                    h('div', { 'data-testid': 'stub-editor-preview' }, previewText),
                ],
            );
        },
    },
}));

vi.mock('@/components/tasks/TaskCollaboratorField.vue', () => ({
    default: {
        props: ['modelValue', 'projectId', 'environment', 'readOnly', 'disabled'],
        emits: ['update:modelValue'],
        render() {
            return h('div', { class: 'task-collaborator-field-stub' }, [
                h(
                    'button',
                    {
                        type: 'button',
                        'data-testid': 'set-task-collaborators',
                        disabled: this.disabled || this.readOnly,
                        onClick: () =>
                            this.$emit('update:modelValue', {
                                internal: [{ id: 91, name: 'Jane Doe', email: 'jane@example.com' }],
                                external: [{ id: 'client-7', name: 'Client User', email: 'client@example.com' }],
                            }),
                    },
                    'set collaborators',
                ),
            ]);
        },
    },
}));

vi.mock('@/components/tasks/TaskEnvironmentField.vue', () => ({
    default: {
        props: ['modelValue', 'projectId', 'projects', 'disabled'],
        emits: ['update:modelValue'],
        render() {
            return h('div', { class: 'task-environment-field-stub' }, [
                h(
                    'button',
                    {
                        type: 'button',
                        'data-testid': 'set-task-environment',
                        disabled: this.disabled || this.projectId == null,
                        onClick: () => this.$emit('update:modelValue', 'staging'),
                    },
                    'set environment',
                ),
            ]);
        },
    },
}));

vi.mock('@/components/ui/image-lightbox', () => ({
    ImageLightbox: {
        render() {
            return h('div', { class: 'image-lightbox-stub' });
        },
    },
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: {
        render: () => null,
    },
    router: {
        get: vi.fn(),
        reload: vi.fn(),
    },
    usePage: () => ({
        props: {
            shift: {
                ai_enabled: false,
            },
        },
    }),
}));


beforeEach(() => {
    sonnerMocks.toastLoadingMock.mockClear();
    sonnerMocks.toastSuccessMock.mockClear();
    sonnerMocks.toastErrorMock.mockClear();
    sonnerMocks.toastDismissMock.mockClear();
    window.history.replaceState({}, '', '/tasks');
    (globalThis as any).route = vi.fn((name: string) => `/${name}`);
    axiosPostMock.mockReset();
    axiosPatchMock.mockReset();
    axiosDeleteMock.mockReset();
    (router.get as any).mockClear();
    (router.reload as any).mockClear();
});

export function makeTasksPage(tasks: any[]) {
    const total = tasks.length;
    return {
        data: tasks,
        total,
        current_page: 1,
        last_page: 1,
        from: total ? 1 : 0,
        to: total,
    };
}

export { Index, axiosDeleteMock, axiosGetMock, axiosPatchMock, axiosPostMock, axiosPutMock, flushPromises, mount, router, sonnerMocks };
