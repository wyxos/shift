/* eslint-disable max-lines */
import IndexV2 from '@/pages/Tasks/IndexV2.vue';
import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { h } from 'vue';

const axiosGetMock = vi.fn();
const axiosPutMock = vi.fn();
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
        post: vi.fn(),
        delete: vi.fn(),
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
            return h(
                'div',
                { ...this.$attrs, class: 'shift-editor-stub' },
                h(
                    'button',
                    {
                        type: 'button',
                        'data-testid': 'stub-send',
                        onClick: () => this.$emit('send', { html: this.modelValue ?? '<p>hello</p>', attachments: [] }),
                    },
                    'send',
                ),
            );
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
}));

describe('Tasks/IndexV2.vue', () => {
    beforeEach(() => {
        sonnerMocks.toastLoadingMock.mockClear();
        sonnerMocks.toastSuccessMock.mockClear();
        sonnerMocks.toastErrorMock.mockClear();
        sonnerMocks.toastDismissMock.mockClear();
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

        const wrapper = mount(IndexV2, {
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
        expect(wrapper.text()).toContain('Tasks V2');
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

        const wrapper = mount(IndexV2, {
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

    it('uses distinct status badge colors for each status', () => {
        axiosGetMock.mockReset();

        const wrapper = mount(IndexV2, {
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

        const wrapper = mount(IndexV2, {
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

        const wrapper = mount(IndexV2, {
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

    it('shows task created timestamp in the edit sheet', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-02-10T18:00:00'));

        (globalThis as any).route = vi.fn((name: string) => `/${name}`);
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

        const wrapper = mount(IndexV2, {
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

        (globalThis as any).route = vi.fn((name: string) => `/${name}`);
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
                    description: '',
                    is_owner: false,
                    submitter: { name: 'Taylor Brown', email: 'someone@example.com' },
                    attachments: [],
                },
            })
            .mockResolvedValueOnce({ data: { external: [] } });

        const wrapper = mount(IndexV2, {
            props: {
                tasks: makeTasksPage([{ id: 1, title: 'Auth issue', status: 'pending', priority: 'high' }]),
                filters: { status: ['pending', 'in-progress', 'awaiting-feedback'], priority: ['low', 'medium', 'high'], search: '' },
            },
        });

        await wrapper.find('button[title="Edit"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[data-testid="edit-task-environment"]').text()).toContain('Staging');
        expect(wrapper.get('[data-testid="edit-task-created-by"]').text()).toContain('Taylor Brown');
        expect(wrapper.get('[data-testid="task-status-pending"]').classes()).toContain('bg-amber-100');

        wrapper.unmount();
        vi.useRealTimers();
    });

    it('allows any user to change task status from the V2 edit sheet', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-02-10T18:00:00'));

        (globalThis as any).route = vi.fn((name: string) => `/${name}`);
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

        const wrapper = mount(IndexV2, {
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
        expect(sonnerMocks.toastLoadingMock).toHaveBeenCalledWith('Saving task changes...');
        expect(sonnerMocks.toastSuccessMock).toHaveBeenCalledWith('Task changes saved', expect.objectContaining({ id: 'autosave-toast' }));

        wrapper.unmount();
        vi.useRealTimers();
    });

    it('allows the comment owner to edit their comment', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-02-10T18:00:00'));

        (globalThis as any).route = vi.fn((name: string) => `/${name}`);
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

        const wrapper = mount(IndexV2, {
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

    it('cancels comment edit on Escape', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-02-10T18:00:00'));

        (globalThis as any).route = vi.fn((name: string) => `/${name}`);
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

        const wrapper = mount(IndexV2, {
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
});
