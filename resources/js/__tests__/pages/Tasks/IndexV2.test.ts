import IndexV2 from '@/pages/Tasks/IndexV2.vue';
import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { h } from 'vue';

const axiosGetMock = vi.fn();
const axiosPutMock = vi.fn();

vi.mock('axios', () => ({
    default: {
        get: (...args: any[]) => axiosGetMock(...args),
        put: (...args: any[]) => axiosPutMock(...args),
        post: vi.fn(),
        delete: vi.fn(),
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
            return h('span', { class: `badge ${this.variant || ''}` }, this.$slots.default?.());
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

vi.mock('@/components/ui/select', () => ({
    Select: {
        props: ['modelValue'],
        emits: ['update:modelValue'],
        render() {
            return h(
                'select',
                {
                    value: this.modelValue,
                    onChange: (e) => this.$emit('update:modelValue', (e.target as HTMLSelectElement).value),
                },
                this.$slots.default?.(),
            );
        },
    },
}));

vi.mock('@/components/ShiftEditor.vue', () => ({
    default: {
        props: ['modelValue'],
        emits: ['update:modelValue', 'send', 'uploading'],
        render() {
            return h('div', { class: 'shift-editor-stub' });
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
    },
}));

describe('Tasks/IndexV2.vue', () => {
    it('renders header + task rows', () => {
        axiosGetMock.mockReset();

        const wrapper = mount(IndexV2, {
            props: {
                tasks: [
                    { id: 1, title: 'Auth issue', status: 'pending', priority: 'high' },
                    { id: 2, title: 'UI polish', status: 'in-progress', priority: 'medium' },
                ],
                filters: {
                    status: ['pending', 'in-progress', 'awaiting-feedback'],
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
    });

    it('has filter controls', () => {
        axiosGetMock.mockReset();

        const wrapper = mount(IndexV2, {
            props: {
                tasks: [{ id: 1, title: 'Auth issue', status: 'pending', priority: 'high' }],
                filters: {
                    status: ['pending', 'in-progress', 'awaiting-feedback'],
                },
            },
        });

        expect(wrapper.find('input[placeholder="Search by title"]').exists()).toBe(true);
        expect(wrapper.findAll('input[type="checkbox"]').length).toBeGreaterThanOrEqual(4);
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
                tasks: [{ id: 1, title: 'Auth issue', status: 'pending', priority: 'high' }],
                filters: { status: ['pending', 'in-progress', 'awaiting-feedback'] },
            },
        });

        await wrapper.find('button[title="Edit"]').trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('Created');
        expect(wrapper.text()).toContain('17:40');
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
                tasks: [{ id: 1, title: 'Auth issue', status: 'pending', priority: 'high' }],
                filters: { status: ['pending', 'in-progress', 'awaiting-feedback'] },
            },
        });

        await wrapper.find('button[title="Edit"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-testid="comment-edit-11"]').exists()).toBe(true);
        await wrapper.get('[data-testid="comment-edit-11"]').trigger('click');
        await wrapper.vm.$nextTick();

        await wrapper.get('[data-testid="comment-save-11"]').trigger('click');
        await flushPromises();

        expect(axiosPutMock).toHaveBeenCalledWith(
            '/task-threads.update',
            expect.objectContaining({ content: '<p>Second</p>', temp_identifier: expect.any(String) }),
        );
        expect(wrapper.text()).toContain('Edited');
    });
});
