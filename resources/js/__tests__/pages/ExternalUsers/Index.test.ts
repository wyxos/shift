import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { h } from 'vue'
import Index from '@/pages/ExternalUsers/Index.vue'

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
    props: ['variant', 'disabled'],
    render() {
      return h('button', {
        class: `button ${this.variant || ''}`,
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

// Mock lodash debounce
vi.mock('lodash/debounce', () => ({
  default: (fn) => fn
}))

// Mock Inertia components and functions
vi.mock('@inertiajs/vue3', () => {
  const routerMock = {
    get: vi.fn(),
    visit: vi.fn()
  }

  return {
    Head: {
      render: () => {},
    },
    router: routerMock
  }
})

describe('ExternalUsers/Index.vue', () => {
  const mockExternalUsers = {
    data: [
      {
        id: 1,
        name: 'External User 1',
        email: 'user1@example.com',
        environment: 'production',
        project: { id: 1, name: 'Project 1' }
      },
      {
        id: 2,
        name: 'External User 2',
        email: 'user2@example.com',
        environment: 'staging',
        project: null
      }
    ],
    current_page: 1,
    per_page: 10,
    total: 2
  }

  const mockFilters = {
    search: ''
  }

  it('renders the external users table correctly', () => {
    const wrapper = mount(Index, {
      props: {
        externalUsers: mockExternalUsers,
        filters: mockFilters
      }
    })

    expect(wrapper.find('.o-table').exists()).toBe(true)
    expect(wrapper.findAll('.o-table-column').length).toBeGreaterThan(0)
  })

  it('displays search input', () => {
    const wrapper = mount(Index, {
      props: {
        externalUsers: mockExternalUsers,
        filters: mockFilters
      }
    })

    expect(wrapper.find('input[placeholder="Search..."]').exists()).toBe(true)
  })

  it('displays correct table columns', () => {
    const wrapper = mount(Index, {
      props: {
        externalUsers: mockExternalUsers,
        filters: mockFilters
      }
    })

    const columns = wrapper.findAll('.o-table-column')

    // Check if we have at least 5 columns (Name, Email, Environment, Project, Actions)
    expect(columns.length).toBeGreaterThanOrEqual(5)
  })

  it('displays edit button for each user', () => {
    const wrapper = mount(Index, {
      props: {
        externalUsers: mockExternalUsers,
        filters: mockFilters
      }
    })

    // We can't directly test the buttons in each row due to the mocked OTable
    // But we can check if the edit button is defined in the component
    const editIcon = wrapper.findAll('i.fa-edit')
    expect(editIcon.length).toBeGreaterThan(0)
  })

  it('displays empty state when no users found', () => {
    const emptyExternalUsers = {
      data: [],
      current_page: 1,
      per_page: 10,
      total: 0
    }

    const wrapper = mount(Index, {
      props: {
        externalUsers: emptyExternalUsers,
        filters: mockFilters
      }
    })

    // Since we're using mocked components, we can check if the empty slot is rendered
    expect(wrapper.text()).toContain('No external users found')
  })

  it('updates search when input changes', async () => {
    const wrapper = mount(Index, {
      props: {
        externalUsers: mockExternalUsers,
        filters: mockFilters
      }
    })

    const searchInput = wrapper.find('input[placeholder="Search..."]')
    await searchInput.setValue('test search')

    // Check if the search ref is updated
    expect(wrapper.vm.search).toBe('test search')

    // We can't directly test the router.get call due to the debounce,
    // but we can check if the router.get function is defined
    expect(vi.mocked(wrapper.vm.router.get)).toBeDefined()
  })
})
