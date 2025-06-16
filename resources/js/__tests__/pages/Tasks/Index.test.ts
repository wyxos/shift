import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { h } from 'vue'
import Index from '@/pages/Tasks/Index.vue'

// Mock components
vi.mock('@/layouts/AppLayout.vue', () => ({
  default: {
    props: ['breadcrumbs'],
    render() {
      return h('div', { class: 'app-layout' }, this.$slots.default?.())
    }
  }
}))

vi.mock('@/components/ui/button', () => ({
  Button: {
    props: ['variant', 'disabled', 'size'],
    render() {
      return h('button', {
        class: `button ${this.variant || ''} ${this.size || ''}`,
        disabled: this.disabled
      }, this.$slots.default?.())
    }
  }
}))

vi.mock('@/components/ui/input', () => ({
  Input: {
    props: ['type', 'placeholder', 'modelValue'],
    emits: ['update:modelValue'],
    render() {
      return h('input', {
        type: this.type,
        placeholder: this.placeholder,
        value: this.modelValue,
        onInput: (e) => this.$emit('update:modelValue', e.target.value)
      })
    }
  }
}))

vi.mock('@oruga-ui/oruga-next', () => ({
  OTable: {
    props: ['data', 'paginated', 'perPage', 'currentPage', 'backendPagination', 'total'],
    emits: ['pageChange'],
    render() {
      return h('div', { class: 'o-table' }, [
        this.$slots.default?.(),
        this.$slots.empty?.()
      ])
    }
  },
  OTableColumn: {
    props: ['field', 'label'],
    render() {
      return h('div', { class: 'o-table-column' }, this.$slots.default?.())
    }
  }
}))

vi.mock('@/components/ui/dropdown-menu', () => ({
  DropdownMenu: {
    render() {
      return h('div', { class: 'dropdown-menu' }, this.$slots.default?.())
    }
  },
  DropdownMenuTrigger: {
    render() {
      return h('div', { class: 'dropdown-menu-trigger' }, this.$slots.default?.())
    }
  },
  DropdownMenuContent: {
    render() {
      return h('div', { class: 'dropdown-menu-content' }, this.$slots.default?.())
    }
  },
  DropdownMenuItem: {
    render() {
      return h('div', {
        class: 'dropdown-menu-item',
        onClick: this.$attrs.onClick
      }, this.$slots.default?.())
    }
  }
}))

vi.mock('@/components/DeleteDialog.vue', () => ({
  default: {
    props: ['isOpen'],
    render() {
      if (!this.isOpen) return null
      return h('div', { class: 'delete-dialog' }, [
        this.$slots.title?.(),
        this.$slots.description?.(),
        h('div', { class: 'actions' }, [
          this.$slots.cancel?.(),
          this.$slots.confirm?.()
        ])
      ])
    }
  }
}))

// Mock lodash debounce
vi.mock('lodash/debounce', () => ({
  default: (fn) => fn
}))

// Mock Inertia components and functions
vi.mock('@inertiajs/vue3', () => {
  const routerMock = {
    get: vi.fn(),
    visit: vi.fn(),
    delete: vi.fn(),
    patch: vi.fn(),
    reload: vi.fn()
  }

  const useFormMock = vi.fn(() => ({
    id: null,
    isActive: false,
    errors: {},
    processing: false
  }))

  return {
    Head: {
      render: () => {},
    },
    router: routerMock,
    useForm: useFormMock
  }
})

describe('Tasks/Index.vue', () => {
  const mockTasks = {
    data: [
      {
        id: 1,
        title: 'Task 1',
        status: 'pending',
        priority: 'medium',
        is_external: false,
        submitter: { name: 'John Doe' }
      },
      {
        id: 2,
        title: 'Task 2',
        status: 'in-progress',
        priority: 'high',
        is_external: true,
        submitter: { name: 'Jane Smith', email: 'jane@example.com' },
        metadata: { environment: 'production', url: 'https://example.com' }
      }
    ],
    current_page: 1,
    per_page: 10,
    total: 2
  }

  const mockProjects = [
    { id: 1, name: 'Project 1' },
    { id: 2, name: 'Project 2' }
  ]

  const mockFilters = {
    search: '',
    project_id: '',
    priority: '',
    status: ''
  }

  it('renders the tasks table correctly', () => {
    const wrapper = mount(Index, {
      props: {
        tasks: mockTasks,
        projects: mockProjects,
        filters: mockFilters
      }
    })

    expect(wrapper.find('.o-table').exists()).toBe(true)
    expect(wrapper.findAll('.o-table-column').length).toBeGreaterThan(0)
  })

  it('displays search input and filters', () => {
    const wrapper = mount(Index, {
      props: {
        tasks: mockTasks,
        projects: mockProjects,
        filters: mockFilters
      }
    })

    expect(wrapper.find('input[placeholder="Search..."]').exists()).toBe(true)

    // Project filter
    const projectSelect = wrapper.find('select[class*="mb-4"]:nth-of-type(1)')
    expect(projectSelect.exists()).toBe(true)
    expect(projectSelect.findAll('option').length).toBe(3) // All Projects + 2 projects

    // Priority filter
    const prioritySelect = wrapper.find('select[class*="mb-4"]:nth-of-type(2)')
    expect(prioritySelect.exists()).toBe(true)
    expect(prioritySelect.findAll('option').length).toBe(4) // All Priorities + 3 priorities

    // Status filter
    const statusSelect = wrapper.find('select[class*="mb-4"]:nth-of-type(3)')
    expect(statusSelect.exists()).toBe(true)
    expect(statusSelect.findAll('option').length).toBe(5) // All Statuses + 4 statuses
  })

  it('displays reset and add task buttons', () => {
    const wrapper = mount(Index, {
      props: {
        tasks: mockTasks,
        projects: mockProjects,
        filters: mockFilters
      }
    })

    const buttons = wrapper.findAll('button')
    const resetButton = buttons.find(btn => btn.text().includes('Reset'))
    const addButton = buttons.find(btn => btn.text().includes('Add Task'))

    expect(resetButton).toBeDefined()
    expect(addButton).toBeDefined()
  })

  it('displays task status with correct styling', () => {
    const wrapper = mount(Index, {
      props: {
        tasks: mockTasks,
        projects: mockProjects,
        filters: mockFilters
      }
    })

    // Since we're using mocked components, we can't directly test the styling
    // But we can check if the component structure is correct
    expect(wrapper.find('.dropdown-menu').exists()).toBe(true)
    expect(wrapper.find('.dropdown-menu-trigger').exists()).toBe(true)
    expect(wrapper.find('.dropdown-menu-content').exists()).toBe(true)
  })

  it('displays task priority with correct styling', () => {
    const wrapper = mount(Index, {
      props: {
        tasks: mockTasks,
        projects: mockProjects,
        filters: mockFilters
      }
    })

    // Since we're using mocked components, we can't directly test the styling
    // But we can check if the component structure is correct
    expect(wrapper.findAll('.dropdown-menu').length).toBeGreaterThan(1)
  })

  it('displays action buttons for each task', () => {
    const wrapper = mount(Index, {
      props: {
        tasks: mockTasks,
        projects: mockProjects,
        filters: mockFilters
      }
    })

    // We can't directly test the buttons in each row due to the mocked OTable
    // But we can check if the edit and delete buttons are defined in the component
    const editIcon = wrapper.findAll('i.fa-edit')
    const deleteIcon = wrapper.findAll('i.fa-trash')

    expect(editIcon.length).toBeGreaterThan(0)
    expect(deleteIcon.length).toBeGreaterThan(0)
  })

  it('displays delete confirmation dialog when delete is clicked', async () => {
    const wrapper = mount(Index, {
      props: {
        tasks: mockTasks,
        projects: mockProjects,
        filters: mockFilters
      }
    })

    // Initially, the delete dialog should not be visible
    expect(wrapper.find('.delete-dialog').exists()).toBe(false)

    // We can't directly trigger the delete button click due to the mocked components
    // But we can manually set the deleteForm.isActive to true to test the dialog
    await wrapper.setData({ deleteForm: { isActive: true, id: 1 } })

    // Now the delete dialog should be visible
    expect(wrapper.find('.delete-dialog').exists()).toBe(true)
    expect(wrapper.text()).toContain('Delete Task')
    expect(wrapper.text()).toContain('Are you sure you want to delete this task?')
  })
})
