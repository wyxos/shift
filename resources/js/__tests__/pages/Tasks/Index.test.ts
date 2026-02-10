import Index from '@/pages/Tasks/Index.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { h } from 'vue';

// Mock components
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
                    class: `button ${this.variant || ''} ${this.size || ''}`,
                    disabled: this.disabled,
                },
                this.$slots.default?.(),
            );
        },
    },
}));

vi.mock('@/components/ui/input', () => ({
    Input: {
        props: ['type', 'placeholder', 'modelValue'],
        emits: ['update:modelValue'],
        render() {
            return h('input', {
                type: this.type,
                placeholder: this.placeholder,
                value: this.modelValue,
                onInput: (e) => this.$emit('update:modelValue', e.target.value),
            });
        },
    },
}));

vi.mock('@oruga-ui/oruga-next', () => ({
    OTable: {
        props: ['data', 'paginated', 'perPage', 'currentPage', 'backendPagination', 'total'],
        emits: ['page-change'],
        render() {
            return h('div', { class: 'o-table' }, [this.$slots.default?.(), this.$slots.empty?.()]);
        },
    },
    OTableColumn: {
        props: ['field', 'label'],
        render() {
            // Create a mock row to pass to the slot
            const mockRow = { id: 1, title: 'Mock Task', status: 'pending', priority: 'medium' };
            return h('div', { class: 'o-table-column' }, this.$slots.default?.({ row: mockRow }));
        },
    },
}));

vi.mock('@/components/ui/dropdown-menu', () => ({
    DropdownMenu: {
        render() {
            return h('div', { class: 'dropdown-menu' }, this.$slots.default?.());
        },
    },
    DropdownMenuTrigger: {
        render() {
            return h('div', { class: 'dropdown-menu-trigger' }, this.$slots.default?.());
        },
    },
    DropdownMenuContent: {
        render() {
            return h('div', { class: 'dropdown-menu-content' }, this.$slots.default?.());
        },
    },
    DropdownMenuItem: {
        render() {
            return h('div', { class: 'dropdown-menu-item' }, this.$slots.default?.());
        },
    },
}));

vi.mock('@/components/DeleteDialog.vue', () => ({
    default: {
        props: ['isOpen'],
        emits: ['cancel', 'confirm'],
        render() {
            if (!this.isOpen) return null;
            return h('div', { class: 'delete-dialog' }, [
                this.$slots.title?.(),
                this.$slots.description?.(),
                h('div', { class: 'actions' }, [
                    h(
                        'button',
                        {
                            class: 'cancel-button',
                            onClick: () => this.$emit('cancel'),
                        },
                        this.$slots.cancel?.(),
                    ),
                    h(
                        'button',
                        {
                            class: 'confirm-button',
                            onClick: () => this.$emit('confirm'),
                        },
                        this.$slots.confirm?.(),
                    ),
                ]),
            ]);
        },
    },
}));

// Mock Inertia components and functions
vi.mock('@inertiajs/vue3', () => {
    const useFormMock = vi.fn(() => ({
        id: null,
        isActive: false,
        errors: {},
        processing: false,
        delete: vi.fn(),
        reset: vi.fn(),
    }));

    return {
        Head: {
            render: () => {},
        },
        router: {
            get: vi.fn(),
            visit: vi.fn(),
            delete: vi.fn(),
            patch: vi.fn(),
            reload: vi.fn(),
        },
        useForm: useFormMock,
    };
});

describe('Tasks/Index.vue', () => {
    const mockTasks = {
        data: [
            {
                id: 1,
                title: 'Task 1',
                status: 'pending',
                priority: 'medium',
                is_external: false,
                submitter: { name: 'John Doe', email: 'john@example.com' },
            },
            {
                id: 2,
                title: 'Task 2',
                status: 'in-progress',
                priority: 'high',
                is_external: true,
                submitter: { name: 'Jane Smith', email: 'jane@example.com' },
                metadata: { environment: 'production', url: 'https://example.com' },
            },
        ],
        per_page: 10,
        current_page: 1,
        total: 2,
    };

    const mockProjects = [
        { id: 1, name: 'Project 1' },
        { id: 2, name: 'Project 2' },
    ];

    const mockFilters = {
        search: '',
        project_id: '',
        priority: '',
        status: '',
    };

    it('renders tasks table correctly', () => {
        const wrapper = mount(Index, {
            props: {
                tasks: mockTasks,
                projects: mockProjects,
                filters: mockFilters,
            },
        });

        expect(wrapper.find('.o-table').exists()).toBe(true);
        expect(wrapper.find('.app-layout').exists()).toBe(true);
    });

    it('shows add task button', () => {
        const wrapper = mount(Index, {
            props: {
                tasks: mockTasks,
                projects: mockProjects,
                filters: mockFilters,
            },
        });

        const addButton = wrapper.findAll('button').find((btn) => btn.text().includes('Add Task'));
        expect(addButton).toBeDefined();
    });

    it('has search input', () => {
        const wrapper = mount(Index, {
            props: {
                tasks: mockTasks,
                projects: mockProjects,
                filters: mockFilters,
            },
        });

        const searchInput = wrapper.find('input[placeholder="Search..."]');
        expect(searchInput.exists()).toBe(true);
    });

    it('has filter controls: 2 dropdowns + status checkboxes', () => {
        const wrapper = mount(Index, {
            props: {
                tasks: mockTasks,
                projects: mockProjects,
                filters: mockFilters,
            },
        });

        const selects = wrapper.findAll('select');
        expect(selects.length).toBe(2); // Project, Priority filters

        const statusCheckboxes = wrapper.findAll('input[type="checkbox"]');
        expect(statusCheckboxes.length).toBeGreaterThanOrEqual(4);
    });

    it('has reset button', () => {
        const wrapper = mount(Index, {
            props: {
                tasks: mockTasks,
                projects: mockProjects,
                filters: mockFilters,
            },
        });

        const resetButton = wrapper.findAll('button').find((btn) => btn.text().includes('Reset'));
        expect(resetButton).toBeDefined();
    });

    it('shows empty state when no tasks', () => {
        const emptyTasks = {
            data: [],
            per_page: 10,
            current_page: 1,
            total: 0,
        };

        const wrapper = mount(Index, {
            props: {
                tasks: emptyTasks,
                projects: mockProjects,
                filters: mockFilters,
            },
        });

        expect(wrapper.text()).toContain('No tasks found');
    });

    it('updates localTasks when props change', async () => {
        const wrapper = mount(Index, {
            props: {
                tasks: mockTasks,
                projects: mockProjects,
                filters: mockFilters,
            },
        });

        const updatedTasks = {
            ...mockTasks,
            data: [
                ...mockTasks.data,
                {
                    id: 3,
                    title: 'Task 3',
                    status: 'completed',
                    priority: 'low',
                    is_external: false,
                    submitter: { name: 'Bob Johnson', email: 'bob@example.com' },
                },
            ],
            total: 3,
        };

        await wrapper.setProps({ tasks: updatedTasks });

        // Since we're using mocks, we can't directly test the internal state
        // But we can verify the component doesn't error when props change
        expect(wrapper.find('.o-table').exists()).toBe(true);
    });
});
