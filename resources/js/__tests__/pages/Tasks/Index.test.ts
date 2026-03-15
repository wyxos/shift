/* eslint-disable max-lines */
import Index from '@/pages/Tasks/Index.vue';
import { router } from '@inertiajs/vue3';
import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { h } from 'vue';

const axiosGetMock = vi.fn();
const axiosPutMock = vi.fn();
const axiosPostMock = vi.fn();
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
        props: ['modelValue', 'options', 'disabled', 'testIdPrefix'],
        emits: ['update:modelValue'],
        render() {
            const options = Array.isArray((this as any).options) ? (this as any).options : [];
            return h(
                'div',
                { class: 'button-group-stub' },
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
            return h('div', { ...this.$attrs, class: 'shift-editor-stub' }, [
                h('textarea', {
                    'data-testid': 'stub-editor-input',
                    value: this.modelValue,
                    onInput: (e: Event) => this.$emit('update:modelValue', (e.target as HTMLTextAreaElement).value),
                }),
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
            return h(
                'div',
                { ...this.$attrs, class: 'shift-editor-stub' },
                h('textarea', {
                    'data-testid': 'stub-editor-input',
                    value: this.modelValue,
                    onInput: (e: Event) => this.$emit('update:modelValue', (e.target as HTMLTextAreaElement).value),
                }),
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

describe('Tasks/Index.vue', () => {
    beforeEach(() => {
        sonnerMocks.toastLoadingMock.mockClear();
        sonnerMocks.toastSuccessMock.mockClear();
        sonnerMocks.toastErrorMock.mockClear();
        sonnerMocks.toastDismissMock.mockClear();
        window.history.replaceState({}, '', '/tasks');
        (globalThis as any).route = vi.fn((name: string) => `/${name}`);
        axiosPostMock.mockReset();
        axiosDeleteMock.mockReset();
        (router.get as any).mockClear();
        (router.reload as any).mockClear();
    });

    function makeTasksPage(tasks: any[]) {
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

    it('renders header + task rows', () => {
        axiosGetMock.mockReset();

        const wrapper = mount(Index, {
            props: {
                tasks: makeTasksPage([
                    { id: 1, title: 'Auth issue', status: 'pending', priority: 'high' },
                    { id: 2, title: 'UI polish', status: 'in-progress', priority: 'medium' },
                ]),
                filters: {
                    status: ['pending', 'in-progress', 'awaiting-feedback'],
                    priority: ['low', 'medium', 'high'],
                    search: '',
                },
            },
        });

        expect(wrapper.find('.app-layout').exists()).toBe(true);
        expect(wrapper.text()).toContain('Tasks');
        expect(wrapper.find('[data-testid="filters-trigger"]').exists()).toBe(true);

        const rows = wrapper.findAll('[data-testid="task-row"]');
        expect(rows).toHaveLength(2);
        expect(wrapper.text()).toContain('Auth issue');
        expect(wrapper.text()).toContain('UI polish');

        for (const row of rows) {
            expect(row.find('button[title="Edit"]').exists()).toBe(true);
            expect(row.find('button[title="Delete"]').exists()).toBe(true);
        }

        wrapper.unmount();
    });

    it('has filter controls', () => {
        axiosGetMock.mockReset();

        const wrapper = mount(Index, {
            props: {
                tasks: makeTasksPage([{ id: 1, title: 'Auth issue', status: 'pending', priority: 'high' }]),
                filters: {
                    status: ['pending', 'in-progress', 'awaiting-feedback'],
                    priority: ['low', 'medium', 'high'],
                    search: '',
                },
            },
        });

        expect(wrapper.find('input[placeholder="Search by title"]').exists()).toBe(true);
        expect(wrapper.findAll('input[type="checkbox"]').length).toBeGreaterThanOrEqual(4);

        wrapper.unmount();
    });

    it('creates a task from the V2 sheet and reloads the list', async () => {
        axiosGetMock.mockReset();
        axiosGetMock.mockResolvedValue({
            data: {
                internal: [{ id: 91, name: 'Jane Doe', email: 'jane@example.com' }],
                external: [{ id: 'client-7', name: 'Client User', email: 'client@example.com' }],
                external_available: true,
                external_error: null,
            },
        });
        axiosPostMock.mockResolvedValueOnce({
            data: {
                data: {
                    id: 7,
                    title: 'Created from UI',
                },
            },
        });

        const wrapper = mount(Index, {
            props: {
                tasks: makeTasksPage([]),
                projects: [{ id: 42, name: 'Portal', environments: [{ key: 'staging', label: 'Staging', url: 'https://portal.test' }] }],
                filters: {
                    status: ['pending', 'in-progress', 'awaiting-feedback'],
                    priority: ['low', 'medium', 'high'],
                    search: '',
                },
            },
        });

        await wrapper.get('[data-testid="open-create-task"]').trigger('click');
        await wrapper.get('[data-testid="create-task-title"]').setValue('Created from UI');
        await wrapper.get('[data-testid="create-description-editor"] [data-testid="stub-editor-input"]').setValue('<p>Details</p>');
        await wrapper.get('[data-testid="set-task-environment"]').trigger('click');
        await wrapper.get('[data-testid="set-task-collaborators"]').trigger('click');
        await wrapper.get('[data-testid="create-task-form"]').trigger('submit');
        await flushPromises();

        expect(axiosPostMock).toHaveBeenCalledWith(
            '/tasks.v2.store',
            expect.objectContaining({
                title: 'Created from UI',
                description: '<p>Details</p>',
                priority: 'medium',
                project_id: 42,
                environment: 'staging',
                internal_collaborator_ids: [91],
                external_collaborators: [{ id: 'client-7', name: 'Client User', email: 'client@example.com' }],
            }),
        );
        expect((router.reload as any).mock.calls).toHaveLength(1);
        expect(sonnerMocks.toastSuccessMock).toHaveBeenCalledWith('Task created', {
            description: 'Your task has been added to the queue.',
        });

        wrapper.unmount();
    });

    it('uses distinct status badge colors for each status', () => {
        axiosGetMock.mockReset();

        const wrapper = mount(Index, {
            props: {
                tasks: makeTasksPage([
                    { id: 1, title: 'A', status: 'pending', priority: 'low' },
                    { id: 2, title: 'B', status: 'in-progress', priority: 'medium' },
                    { id: 3, title: 'C', status: 'awaiting-feedback', priority: 'high' },
                    { id: 4, title: 'D', status: 'completed', priority: 'low' },
                ]),
                filters: {
                    status: ['pending', 'in-progress', 'awaiting-feedback', 'completed'],
                    priority: ['low', 'medium', 'high'],
                    search: '',
                },
            },
        });

        expect(wrapper.get('[data-testid="task-status-badge-1"]').classes()).toContain('bg-amber-100');
        expect(wrapper.get('[data-testid="task-status-badge-2"]').classes()).toContain('bg-sky-100');
        expect(wrapper.get('[data-testid="task-status-badge-3"]').classes()).toContain('bg-indigo-100');
        expect(wrapper.get('[data-testid="task-status-badge-4"]').classes()).toContain('bg-emerald-100');

        wrapper.unmount();
    });

    it('uses distinct priority badge colors for each priority', () => {
        axiosGetMock.mockReset();

        const wrapper = mount(Index, {
            props: {
                tasks: makeTasksPage([
                    { id: 1, title: 'A', status: 'pending', priority: 'low' },
                    { id: 2, title: 'B', status: 'in-progress', priority: 'medium' },
                    { id: 3, title: 'C', status: 'awaiting-feedback', priority: 'high' },
                ]),
                filters: {
                    status: ['pending', 'in-progress', 'awaiting-feedback'],
                    priority: ['low', 'medium', 'high'],
                    search: '',
                },
            },
        });

        expect(wrapper.get('[data-testid="task-priority-badge-1"]').classes()).toContain('bg-cyan-100');
        expect(wrapper.get('[data-testid="task-priority-badge-2"]').classes()).toContain('bg-fuchsia-100');
        expect(wrapper.get('[data-testid="task-priority-badge-3"]').classes()).toContain('bg-rose-100');

        wrapper.unmount();
    });

    it('shows environment badges in list rows', () => {
        axiosGetMock.mockReset();

        const wrapper = mount(Index, {
            props: {
                tasks: makeTasksPage([
                    { id: 1, title: 'A', status: 'pending', priority: 'low', environment: 'staging' },
                    { id: 2, title: 'B', status: 'in-progress', priority: 'medium', environment: null },
                ]),
                filters: {
                    status: ['pending', 'in-progress', 'awaiting-feedback'],
                    priority: ['low', 'medium', 'high'],
                    search: '',
                },
            },
        });

        expect(wrapper.get('[data-testid="task-environment-badge-1"]').text()).toContain('Staging');
        expect(wrapper.get('[data-testid="task-environment-badge-2"]').text()).toContain('Unknown');

        wrapper.unmount();
    });

    it('syncs task id in URL when opening and closing the edit sheet', async () => {
        axiosGetMock.mockReset();
        const pushStateSpy = vi.spyOn(window.history, 'pushState');

        axiosGetMock
            .mockResolvedValueOnce({
                data: {
                    id: 1,
                    title: 'Auth issue',
                    priority: 'high',
                    status: 'pending',
                    created_at: '2026-02-10T17:40:00',
                    description: '',
                    is_owner: false,
                    submitter: { email: 'someone@example.com' },
                    attachments: [],
                },
            })
            .mockResolvedValueOnce({ data: { external: [] } });

        const wrapper = mount(Index, {
            props: {
                tasks: makeTasksPage([{ id: 1, title: 'Auth issue', status: 'pending', priority: 'high' }]),
                filters: { status: ['pending', 'in-progress', 'awaiting-feedback'], priority: ['low', 'medium', 'high'], search: '' },
            },
        });

        await wrapper.find('button[title="Edit"]').trigger('click');
        await flushPromises();

        expect(window.location.search).toContain('task=1');
        expect(pushStateSpy.mock.calls.some(([, , next]) => next === '/tasks?task=1')).toBe(true);

        (wrapper.vm as any).closeEditNow();
        await flushPromises();

        expect(window.location.search).toBe('');
        expect(pushStateSpy.mock.calls.some(([, , next]) => next === '/tasks')).toBe(true);
        wrapper.unmount();
        pushStateSpy.mockRestore();
    });

    it('auto-opens the edit sheet from task URL query', async () => {
        axiosGetMock.mockReset();
        window.history.replaceState({}, '', '/tasks?task=1');

        axiosGetMock
            .mockResolvedValueOnce({
                data: {
                    id: 1,
                    title: 'Auth issue',
                    priority: 'high',
                    status: 'pending',
                    created_at: '2026-02-10T17:40:00',
                    description: '',
                    is_owner: false,
                    submitter: { email: 'someone@example.com' },
                    attachments: [],
                },
            })
            .mockResolvedValueOnce({ data: { external: [] } });

        const wrapper = mount(Index, {
            props: {
                tasks: makeTasksPage([{ id: 1, title: 'Auth issue', status: 'pending', priority: 'high' }]),
                filters: { status: ['pending', 'in-progress', 'awaiting-feedback'], priority: ['low', 'medium', 'high'], search: '' },
            },
        });

        await flushPromises();

        expect(axiosGetMock).toHaveBeenCalledWith('/tasks.v2.show');
        expect(axiosGetMock).toHaveBeenCalledWith('/task-threads.index');

        wrapper.unmount();
    });

    it('handles browser popstate navigation for task deep links', async () => {
        axiosGetMock.mockReset();

        axiosGetMock
            .mockResolvedValueOnce({
                data: {
                    id: 1,
                    title: 'Auth issue',
                    priority: 'high',
                    status: 'pending',
                    created_at: '2026-02-10T17:40:00',
                    description: '',
                    is_owner: false,
                    submitter: { email: 'someone@example.com' },
                    attachments: [],
                },
            })
            .mockResolvedValueOnce({ data: { external: [] } });

        const wrapper = mount(Index, {
            props: {
                tasks: makeTasksPage([{ id: 1, title: 'Auth issue', status: 'pending', priority: 'high' }]),
                filters: { status: ['pending', 'in-progress', 'awaiting-feedback'], priority: ['low', 'medium', 'high'], search: '' },
            },
        });

        window.history.replaceState({}, '', '/tasks?task=1');
        window.dispatchEvent(new PopStateEvent('popstate'));
        await flushPromises();

        expect(axiosGetMock).toHaveBeenCalledWith('/tasks.v2.show');

        window.history.replaceState({}, '', '/tasks');
        window.dispatchEvent(new PopStateEvent('popstate'));
        await flushPromises();

        expect(window.location.search).toBe('');
        expect((wrapper.vm as any).editOpen).toBe(false);

        wrapper.unmount();
    });

    it('shows task created timestamp in the edit sheet', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-02-10T18:00:00'));
        axiosGetMock.mockReset();

        axiosGetMock
            .mockResolvedValueOnce({
                data: {
                    id: 1,
                    title: 'Auth issue',
                    priority: 'high',
                    status: 'pending',
                    created_at: '2026-02-10T17:40:00',
                    description: '',
                    is_owner: false,
                    submitter: { email: 'someone@example.com' },
                    attachments: [],
                },
            })
            .mockResolvedValueOnce({ data: { external: [] } });

        const wrapper = mount(Index, {
            props: {
                tasks: makeTasksPage([{ id: 1, title: 'Auth issue', status: 'pending', priority: 'high' }]),
                filters: { status: ['pending', 'in-progress', 'awaiting-feedback'], priority: ['low', 'medium', 'high'], search: '' },
            },
        });

        await wrapper.find('button[title="Edit"]').trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('Created');
        expect(wrapper.text()).toContain('17:40');

        wrapper.unmount();
        vi.useRealTimers();
    });

    it('shows task creator and environment in the edit sheet', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-02-10T18:00:00'));
        axiosGetMock.mockReset();

        axiosGetMock
            .mockResolvedValueOnce({
                data: {
                    id: 1,
                    title: 'Auth issue',
                    priority: 'high',
                    status: 'pending',
                    environment: 'staging',
                    created_at: '2026-02-10T17:40:00',
                    updated_at: '2026-02-10T17:55:00',
                    description: '',
                    is_owner: false,
                    submitter: { name: 'Taylor Brown', email: 'someone@example.com' },
                    attachments: [],
                },
            })
            .mockResolvedValueOnce({ data: { external: [] } });

        const wrapper = mount(Index, {
            props: {
                tasks: makeTasksPage([{ id: 1, title: 'Auth issue', status: 'pending', priority: 'high' }]),
                filters: { status: ['pending', 'in-progress', 'awaiting-feedback'], priority: ['low', 'medium', 'high'], search: '' },
            },
        });

        await wrapper.find('button[title="Edit"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[data-testid="edit-task-environment"]').text()).toContain('Staging');
        expect(wrapper.get('[data-testid="edit-task-created-by"]').text()).toContain('Taylor Brown');
        expect(wrapper.get('[data-testid="edit-task-updated-at"]').text()).toContain('Updated');
        expect(wrapper.get('[data-testid="task-status-pending"]').classes()).toContain('bg-amber-100');

        wrapper.unmount();
        vi.useRealTimers();
    });

    it('allows any user to change task status from the V2 edit sheet', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-02-10T18:00:00'));
        axiosGetMock.mockReset();
        axiosPutMock.mockReset();

        axiosGetMock
            .mockResolvedValueOnce({
                data: {
                    id: 1,
                    title: 'Auth issue',
                    priority: 'high',
                    status: 'pending',
                    created_at: '2026-02-10T17:40:00',
                    description: '',
                    is_owner: false,
                    submitter: { email: 'someone@example.com' },
                    attachments: [],
                },
            })
            .mockResolvedValueOnce({ data: { external: [] } });

        axiosPutMock.mockResolvedValueOnce({ data: { ok: true } });

        const wrapper = mount(Index, {
            props: {
                tasks: makeTasksPage([{ id: 1, title: 'Auth issue', status: 'pending', priority: 'high' }]),
                filters: { status: ['pending', 'in-progress', 'awaiting-feedback'], priority: ['low', 'medium', 'high'], search: '' },
            },
        });

        await wrapper.find('button[title="Edit"]').trigger('click');
        await flushPromises();

        await wrapper.get('[data-testid="task-status-in-progress"]').trigger('click');
        await flushPromises();
        expect(wrapper.get('[data-testid="task-status-in-progress"]').classes()).toContain('bg-sky-100');

        vi.advanceTimersByTime(800);
        await flushPromises();

        expect(axiosPutMock).toHaveBeenCalledWith('/tasks.v2.update', expect.objectContaining({ status: 'in-progress' }));
        expect(router.reload).toHaveBeenCalledWith({
            only: ['tasks'],
            preserveScroll: true,
            preserveState: true,
        });
        expect(sonnerMocks.toastLoadingMock).toHaveBeenCalledWith('Saving task changes...');
        expect(sonnerMocks.toastSuccessMock).toHaveBeenCalledWith('Task changes saved', expect.objectContaining({ id: 'autosave-toast' }));

        wrapper.unmount();
        vi.useRealTimers();
    });

    it('allows the comment owner to edit their comment', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-02-10T18:00:00'));
        axiosGetMock.mockReset();
        axiosPutMock.mockReset();

        axiosGetMock
            .mockResolvedValueOnce({
                data: {
                    id: 1,
                    title: 'Auth issue',
                    priority: 'high',
                    status: 'pending',
                    created_at: '2026-02-10T17:40:00',
                    description: '',
                    is_owner: false,
                    submitter: { email: 'someone@example.com' },
                    attachments: [],
                },
            })
            .mockResolvedValueOnce({
                data: {
                    external: [
                        {
                            id: 11,
                            sender_name: 'You',
                            is_current_user: true,
                            content: '<p>Second</p>',
                            created_at: '2026-02-09T12:01:00Z',
                            attachments: [],
                        },
                    ],
                },
            });

        axiosPutMock.mockResolvedValueOnce({
            data: {
                thread: {
                    id: 11,
                    sender_name: 'You',
                    is_current_user: true,
                    content: '<p>Edited</p>',
                    created_at: '2026-02-09T12:01:00Z',
                    attachments: [],
                },
            },
        });

        const wrapper = mount(Index, {
            props: {
                tasks: makeTasksPage([{ id: 1, title: 'Auth issue', status: 'pending', priority: 'high' }]),
                filters: { status: ['pending', 'in-progress', 'awaiting-feedback'], priority: ['low', 'medium', 'high'], search: '' },
            },
        });

        await wrapper.find('button[title="Edit"]').trigger('click');
        await flushPromises();

        await wrapper.get('[data-testid="comment-bubble-11"]').trigger('dblclick');
        await wrapper.vm.$nextTick();

        const commentsEditor = wrapper.get('[data-testid="comments-editor"]');
        await commentsEditor.get('[data-testid="stub-send"]').trigger('click');
        await flushPromises();

        expect(axiosPutMock).toHaveBeenCalledWith(
            '/task-threads.update',
            expect.objectContaining({ content: '<p>Second</p>', temp_identifier: expect.any(String) }),
        );
        expect(wrapper.text()).toContain('Edited');

        wrapper.unmount();
        vi.useRealTimers();
    });

    it('includes grouped collaborator payloads in owner autosaves', async () => {
        vi.useFakeTimers();
        axiosGetMock.mockReset();
        axiosPutMock.mockReset();

        axiosGetMock
            .mockResolvedValueOnce({
                data: {
                    id: 1,
                    project_id: 42,
                    title: 'Owner task',
                    environment: 'staging',
                    priority: 'high',
                    status: 'pending',
                    created_at: '2026-02-10T17:40:00',
                    description: '',
                    is_owner: true,
                    submitter: { email: 'owner@example.com' },
                    attachments: [],
                    internal_collaborators: [],
                    external_collaborators: [],
                },
            })
            .mockResolvedValueOnce({ data: { external: [] } })
            .mockResolvedValueOnce({
                data: {
                    internal: [{ id: 91, name: 'Jane Doe', email: 'jane@example.com' }],
                    external: [{ id: 'client-7', name: 'Client User', email: 'client@example.com' }],
                    external_available: true,
                    external_error: null,
                },
            });

        axiosPutMock.mockResolvedValueOnce({
            data: {
                ok: true,
                task: {
                    id: 1,
                    title: 'Owner task',
                    environment: 'staging',
                    priority: 'high',
                    status: 'pending',
                    description: '',
                    attachments: [],
                    internal_collaborators: [{ id: 91, name: 'Jane Doe', email: 'jane@example.com' }],
                    external_collaborators: [{ id: 'client-7', name: 'Client User', email: 'client@example.com' }],
                },
            },
        });

        const wrapper = mount(Index, {
            props: {
                tasks: makeTasksPage([{ id: 1, title: 'Owner task', status: 'pending', priority: 'high' }]),
                projects: [{ id: 42, name: 'Portal', environments: [{ key: 'staging', label: 'Staging', url: 'https://portal.test' }] }],
                filters: { status: ['pending', 'in-progress', 'awaiting-feedback'], priority: ['low', 'medium', 'high'], search: '' },
            },
        });

        await wrapper.find('button[title="Edit"]').trigger('click');
        await flushPromises();

        const collaboratorButtons = wrapper.findAll('[data-testid="set-task-collaborators"]');
        expect(collaboratorButtons).toHaveLength(2);

        await collaboratorButtons[1].trigger('click');
        await flushPromises();
        expect((wrapper.vm as any).editForm.collaborators).toEqual({
            internal: [{ id: 91, name: 'Jane Doe', email: 'jane@example.com' }],
            external: [{ id: 'client-7', name: 'Client User', email: 'client@example.com' }],
        });

        await wrapper.get('[data-testid="task-priority-medium"]').trigger('click');
        await flushPromises();

        vi.advanceTimersByTime(800);
        await flushPromises();

        expect(axiosPutMock).toHaveBeenCalledWith(
            '/tasks.v2.update',
            expect.objectContaining({
                environment: 'staging',
                priority: 'medium',
                internal_collaborator_ids: [91],
                external_collaborators: [{ id: 'client-7', name: 'Client User', email: 'client@example.com' }],
            }),
        );

        wrapper.unmount();
        vi.useRealTimers();
    });

    it('renders markdown list comments as list HTML', async () => {
        axiosGetMock.mockReset();

        axiosGetMock
            .mockResolvedValueOnce({
                data: {
                    id: 1,
                    title: 'Auth issue',
                    priority: 'high',
                    status: 'pending',
                    created_at: '2026-02-10T17:40:00',
                    description: '',
                    is_owner: false,
                    submitter: { email: 'someone@example.com' },
                    attachments: [],
                },
            })
            .mockResolvedValueOnce({
                data: {
                    external: [
                        {
                            id: 11,
                            sender_name: 'You',
                            is_current_user: true,
                            content: '- first\n- second',
                            created_at: '2026-02-09T12:01:00Z',
                            attachments: [],
                        },
                    ],
                },
            });

        const wrapper = mount(Index, {
            props: {
                tasks: makeTasksPage([{ id: 1, title: 'Auth issue', status: 'pending', priority: 'high' }]),
                filters: { status: ['pending', 'in-progress', 'awaiting-feedback'], priority: ['low', 'medium', 'high'], search: '' },
            },
        });

        await wrapper.find('button[title="Edit"]').trigger('click');
        await flushPromises();

        const commentHtml = wrapper.get('[data-testid="comment-bubble-11"]').html();
        expect(commentHtml).toContain('<ul>');
        expect(commentHtml).toMatch(/<li>first<\/li>/i);
        expect(commentHtml).toMatch(/<li>second<\/li>/i);

        wrapper.unmount();
    });

    it('normalizes legacy list HTML comments with br-separated markers', async () => {
        axiosGetMock.mockReset();

        axiosGetMock
            .mockResolvedValueOnce({
                data: {
                    id: 1,
                    title: 'Auth issue',
                    priority: 'high',
                    status: 'pending',
                    created_at: '2026-02-10T17:40:00',
                    description: '',
                    is_owner: false,
                    submitter: { email: 'someone@example.com' },
                    attachments: [],
                },
            })
            .mockResolvedValueOnce({
                data: {
                    external: [
                        {
                            id: 11,
                            sender_name: 'You',
                            is_current_user: true,
                            content: '<ul><li><p>test<br>- test</p></li></ul>',
                            created_at: '2026-02-09T12:01:00Z',
                            attachments: [],
                        },
                    ],
                },
            });

        const wrapper = mount(Index, {
            props: {
                tasks: makeTasksPage([{ id: 1, title: 'Auth issue', status: 'pending', priority: 'high' }]),
                filters: { status: ['pending', 'in-progress', 'awaiting-feedback'], priority: ['low', 'medium', 'high'], search: '' },
            },
        });

        await wrapper.find('button[title="Edit"]').trigger('click');
        await flushPromises();

        const commentHtml = wrapper.get('[data-testid="comment-bubble-11"]').html();
        const liMatches = commentHtml.match(/<li>/g) ?? [];
        expect(commentHtml).toContain('<ul>');
        expect(liMatches.length).toBe(2);

        wrapper.unmount();
    });

    it('renders inline code in comments for backtick-wrapped text', async () => {
        axiosGetMock.mockReset();

        axiosGetMock
            .mockResolvedValueOnce({
                data: {
                    id: 1,
                    title: 'Auth issue',
                    priority: 'high',
                    status: 'pending',
                    created_at: '2026-02-10T17:40:00',
                    description: '',
                    is_owner: false,
                    submitter: { email: 'someone@example.com' },
                    attachments: [],
                },
            })
            .mockResolvedValueOnce({
                data: {
                    external: [
                        {
                            id: 11,
                            sender_name: 'You',
                            is_current_user: true,
                            content: 'Use `this quote` text',
                            created_at: '2026-02-09T12:01:00Z',
                            attachments: [],
                        },
                    ],
                },
            });

        const wrapper = mount(Index, {
            props: {
                tasks: makeTasksPage([{ id: 1, title: 'Auth issue', status: 'pending', priority: 'high' }]),
                filters: { status: ['pending', 'in-progress', 'awaiting-feedback'], priority: ['low', 'medium', 'high'], search: '' },
            },
        });

        await wrapper.find('button[title="Edit"]').trigger('click');
        await flushPromises();

        const commentHtml = wrapper.get('[data-testid="comment-bubble-11"]').html();
        expect(commentHtml).toContain('<code>');
        expect(commentHtml).toContain('this quote');

        wrapper.unmount();
    });

    it('cancels comment edit on Escape', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-02-10T18:00:00'));
        axiosGetMock.mockReset();
        axiosPutMock.mockReset();

        axiosGetMock
            .mockResolvedValueOnce({
                data: {
                    id: 1,
                    title: 'Auth issue',
                    priority: 'high',
                    status: 'pending',
                    created_at: '2026-02-10T17:40:00',
                    description: '',
                    is_owner: false,
                    submitter: { email: 'someone@example.com' },
                    attachments: [],
                },
            })
            .mockResolvedValueOnce({
                data: {
                    external: [
                        {
                            id: 11,
                            sender_name: 'You',
                            is_current_user: true,
                            content: '<p>Second</p>',
                            created_at: '2026-02-09T12:01:00Z',
                            attachments: [],
                        },
                    ],
                },
            });

        const wrapper = mount(Index, {
            props: {
                tasks: makeTasksPage([{ id: 1, title: 'Auth issue', status: 'pending', priority: 'high' }]),
                filters: { status: ['pending', 'in-progress', 'awaiting-feedback'], priority: ['low', 'medium', 'high'], search: '' },
            },
        });

        await wrapper.find('button[title="Edit"]').trigger('click');
        await flushPromises();

        await wrapper.get('[data-testid="comment-bubble-11"]').trigger('dblclick');
        await wrapper.vm.$nextTick();

        const editor = wrapper.get('[data-testid="comments-editor"]');
        expect(editor.attributes('placeholder')).toBe('Edit your comment...');

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        await wrapper.vm.$nextTick();

        expect(editor.attributes('placeholder')).toBe('Write a comment...');

        wrapper.unmount();
        vi.useRealTimers();
    });

    it('copies the full text of a non-author comment', async () => {
        axiosGetMock.mockReset();
        const writeTextMock = vi.fn().mockResolvedValue(undefined);
        Object.defineProperty(navigator, 'clipboard', {
            value: { writeText: writeTextMock },
            configurable: true,
        });

        axiosGetMock
            .mockResolvedValueOnce({
                data: {
                    id: 1,
                    title: 'Auth issue',
                    priority: 'high',
                    status: 'pending',
                    created_at: '2026-02-10T17:40:00',
                    description: '',
                    is_owner: false,
                    submitter: { email: 'someone@example.com' },
                    attachments: [],
                },
            })
            .mockResolvedValueOnce({
                data: {
                    external: [
                        {
                            id: 10,
                            sender_name: 'Alice',
                            is_current_user: false,
                            content: '<p>Hello <strong>team</strong></p>',
                            created_at: '2026-02-09T12:00:00Z',
                            attachments: [],
                        },
                    ],
                },
            });

        const wrapper = mount(Index, {
            props: {
                tasks: makeTasksPage([{ id: 1, title: 'Auth issue', status: 'pending', priority: 'high' }]),
                filters: { status: ['pending', 'in-progress', 'awaiting-feedback'], priority: ['low', 'medium', 'high'], search: '' },
            },
        });

        await wrapper.find('button[title="Edit"]').trigger('click');
        await flushPromises();

        const message = (wrapper.vm as any).threadMessages.find((item: any) => item.id === 10);
        await (wrapper.vm as any).copyEntireMessage(message);

        expect(writeTextMock).toHaveBeenCalledWith('Hello team');
        expect(sonnerMocks.toastSuccessMock).toHaveBeenCalledWith('Message copied');

        wrapper.unmount();
    });

    it('only enables copy selection when the selection belongs to that comment', async () => {
        axiosGetMock.mockReset();
        const writeTextMock = vi.fn().mockResolvedValue(undefined);
        Object.defineProperty(navigator, 'clipboard', {
            value: { writeText: writeTextMock },
            configurable: true,
        });

        axiosGetMock
            .mockResolvedValueOnce({
                data: {
                    id: 1,
                    title: 'Auth issue',
                    priority: 'high',
                    status: 'pending',
                    created_at: '2026-02-10T17:40:00',
                    description: '',
                    is_owner: false,
                    submitter: { email: 'someone@example.com' },
                    attachments: [],
                },
            })
            .mockResolvedValueOnce({
                data: {
                    external: [
                        {
                            id: 10,
                            sender_name: 'Alice',
                            is_current_user: false,
                            content: '<p>Hello <strong>team</strong></p>',
                            created_at: '2026-02-09T12:00:00Z',
                            attachments: [],
                        },
                    ],
                },
            });

        const wrapper = mount(Index, {
            props: {
                tasks: makeTasksPage([{ id: 1, title: 'Auth issue', status: 'pending', priority: 'high' }]),
                filters: { status: ['pending', 'in-progress', 'awaiting-feedback'], priority: ['low', 'medium', 'high'], search: '' },
            },
        });

        await wrapper.find('button[title="Edit"]').trigger('click');
        await flushPromises();

        const message = (wrapper.vm as any).threadMessages.find((item: any) => item.id === 10);
        (wrapper.vm as any).contextMenuMessageId = 10;
        (wrapper.vm as any).contextMenuSelectionText = 'Hello';
        expect((wrapper.vm as any).shouldShowCopySelection(message)).toBe(true);

        await (wrapper.vm as any).copySelectedMessage();
        expect(writeTextMock).toHaveBeenCalledWith('Hello');
        expect(sonnerMocks.toastSuccessMock).toHaveBeenCalledWith('Selection copied');

        (wrapper.vm as any).contextMenuSelectionText = '';
        expect((wrapper.vm as any).shouldShowCopySelection(message)).toBe(false);
        wrapper.unmount();
    });

    it('replies to a comment by quoting and linking back to the original message', async () => {
        axiosGetMock.mockReset();
        axiosPostMock.mockReset();

        axiosGetMock
            .mockResolvedValueOnce({
                data: {
                    id: 1,
                    title: 'Auth issue',
                    priority: 'high',
                    status: 'pending',
                    created_at: '2026-02-10T17:40:00',
                    description: '',
                    is_owner: false,
                    submitter: { email: 'someone@example.com' },
                    attachments: [],
                },
            })
            .mockResolvedValueOnce({
                data: {
                    external: [
                        {
                            id: 10,
                            sender_name: 'Alice',
                            is_current_user: false,
                            content: '<p>Original message</p>',
                            created_at: '2026-02-09T12:00:00Z',
                            attachments: [],
                        },
                    ],
                },
            });

        axiosPostMock.mockResolvedValueOnce({
            data: {
                thread: {
                    id: 12,
                    sender_name: 'You',
                    is_current_user: true,
                    content: '<p>Sent reply</p>',
                    created_at: '2026-02-09T12:03:00Z',
                    attachments: [],
                },
            },
        });

        const wrapper = mount(Index, {
            props: {
                tasks: makeTasksPage([{ id: 1, title: 'Auth issue', status: 'pending', priority: 'high' }]),
                filters: { status: ['pending', 'in-progress', 'awaiting-feedback'], priority: ['low', 'medium', 'high'], search: '' },
            },
        });

        await wrapper.find('button[title="Edit"]').trigger('click');
        await flushPromises();

        const message = (wrapper.vm as any).threadMessages.find((item: any) => item.id === 10);
        (wrapper.vm as any).startReplyToMessage(message);
        await flushPromises();

        const composerHtml = (wrapper.vm as any).threadComposerHtml as string;
        expect(composerHtml).toContain('class="shift-reply"');
        expect(composerHtml).toContain('data-reply-to="10"');

        const commentsEditor = wrapper.get('[data-testid="comments-editor"]');
        await commentsEditor.get('[data-testid="stub-send"]').trigger('click');
        await flushPromises();

        expect(axiosPostMock).toHaveBeenCalledWith(
            '/task-threads.store',
            expect.objectContaining({
                content: expect.stringContaining('data-reply-to="10"'),
            }),
        );

        wrapper.unmount();
    });

    it('appends multiple replies into the same draft instead of replacing previous content', async () => {
        axiosGetMock.mockReset();

        axiosGetMock
            .mockResolvedValueOnce({
                data: {
                    id: 1,
                    title: 'Auth issue',
                    priority: 'high',
                    status: 'pending',
                    created_at: '2026-02-10T17:40:00',
                    description: '',
                    is_owner: false,
                    submitter: { email: 'someone@example.com' },
                    attachments: [],
                },
            })
            .mockResolvedValueOnce({
                data: {
                    external: [
                        {
                            id: 10,
                            sender_name: 'Alice',
                            is_current_user: false,
                            content: '<p>First message</p>',
                            created_at: '2026-02-09T12:00:00Z',
                            attachments: [],
                        },
                        {
                            id: 13,
                            sender_name: 'Bob',
                            is_current_user: false,
                            content: '<p>Second message</p>',
                            created_at: '2026-02-09T12:02:00Z',
                            attachments: [],
                        },
                    ],
                },
            });

        const wrapper = mount(Index, {
            props: {
                tasks: makeTasksPage([{ id: 1, title: 'Auth issue', status: 'pending', priority: 'high' }]),
                filters: { status: ['pending', 'in-progress', 'awaiting-feedback'], priority: ['low', 'medium', 'high'], search: '' },
            },
        });

        await wrapper.find('button[title="Edit"]').trigger('click');
        await flushPromises();

        const firstMessage = (wrapper.vm as any).threadMessages.find((item: any) => item.id === 10);
        const secondMessage = (wrapper.vm as any).threadMessages.find((item: any) => item.id === 13);

        (wrapper.vm as any).startReplyToMessage(firstMessage);
        await flushPromises();
        (wrapper.vm as any).threadComposerHtml = `${(wrapper.vm as any).threadComposerHtml}<p>stuff</p>`;

        (wrapper.vm as any).startReplyToMessage(secondMessage);
        await flushPromises();

        const composerHtml = (wrapper.vm as any).threadComposerHtml as string;
        const replyMatches = composerHtml.match(/data-reply-to="/g) ?? [];

        expect(replyMatches.length).toBe(2);
        expect(composerHtml).toContain('data-reply-to="10"');
        expect(composerHtml).toContain('data-reply-to="13"');
        expect(composerHtml.indexOf('data-reply-to="10"')).toBeLessThan(composerHtml.indexOf('data-reply-to="13"'));
        expect(composerHtml).toContain('<p>stuff</p>');

        wrapper.unmount();
    });

    it('scrolls and highlights the original comment when clicking a reply quote reference', async () => {
        axiosGetMock.mockReset();

        axiosGetMock
            .mockResolvedValueOnce({
                data: {
                    id: 1,
                    title: 'Auth issue',
                    priority: 'high',
                    status: 'pending',
                    created_at: '2026-02-10T17:40:00',
                    description: '',
                    is_owner: false,
                    submitter: { email: 'someone@example.com' },
                    attachments: [],
                },
            })
            .mockResolvedValueOnce({
                data: {
                    external: [
                        {
                            id: 10,
                            sender_name: 'Alice',
                            is_current_user: false,
                            content: '<p>Original message</p>',
                            created_at: '2026-02-09T12:00:00Z',
                            attachments: [],
                        },
                        {
                            id: 11,
                            sender_name: 'Bob',
                            is_current_user: false,
                            content:
                                '<blockquote class="shift-reply" data-reply-to="10"><p>Replying to Alice</p><p>Original message</p></blockquote><p>Follow up</p>',
                            created_at: '2026-02-09T12:03:00Z',
                            attachments: [],
                        },
                    ],
                },
            });

        const wrapper = mount(Index, {
            props: {
                tasks: makeTasksPage([{ id: 1, title: 'Auth issue', status: 'pending', priority: 'high' }]),
                filters: { status: ['pending', 'in-progress', 'awaiting-feedback'], priority: ['low', 'medium', 'high'], search: '' },
            },
        });

        await wrapper.find('button[title="Edit"]').trigger('click');
        await flushPromises();

        const originBubble = wrapper.get('[data-testid="comment-bubble-10"]').element as HTMLElement;
        const originalScrollIntoView = (HTMLElement.prototype as any).scrollIntoView;
        const scrollIntoViewMock = vi.fn();
        Object.defineProperty(HTMLElement.prototype, 'scrollIntoView', {
            value: scrollIntoViewMock,
            configurable: true,
            writable: true,
        });

        const quoteElement = wrapper.get('[data-testid="comment-bubble-11"] blockquote[data-reply-to]').element as HTMLElement;
        const preventDefault = vi.fn();
        const stopPropagation = vi.fn();

        (wrapper.vm as any).onGlobalClickCapture({
            target: quoteElement,
            preventDefault,
            stopPropagation,
        } as MouseEvent);
        await flushPromises();

        expect(scrollIntoViewMock).toHaveBeenCalledWith({
            behavior: 'smooth',
            block: 'center',
            inline: 'nearest',
        });
        expect(originBubble.classList.contains('shift-reply-target')).toBe(true);
        expect(preventDefault).toHaveBeenCalled();
        expect(stopPropagation).toHaveBeenCalled();

        if (originalScrollIntoView) {
            Object.defineProperty(HTMLElement.prototype, 'scrollIntoView', {
                value: originalScrollIntoView,
                configurable: true,
                writable: true,
            });
        } else {
            delete (HTMLElement.prototype as any).scrollIntoView;
        }

        wrapper.unmount();
    });
});
