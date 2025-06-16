import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { h } from 'vue'
import Clients from '@/pages/Clients.vue'

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
    render() {
      // Simplified implementation that just renders the empty slot
      return h('div', { class: 'o-table' }, this.$slots.empty?.())
    }
  },
  OTableColumn: {
    render() {
      // Simplified implementation
      return h('div', { class: 'o-table-column' })
    }
  }
}))

vi.mock('@/components/ui/alert-dialog', () => ({
  AlertDialog: {
    props: ['open'],
    render() {
      if (!this.open) return null
      return h('div', { class: 'alert-dialog' }, this.$slots.default?.())
    }
  },
  AlertDialogTrigger: {
    render() {
      return h('div', { class: 'alert-dialog-trigger' }, this.$slots.default?.())
    }
  },
  AlertDialogContent: {
    render() {
      return h('div', { class: 'alert-dialog-content' }, this.$slots.default?.())
    }
  },
  AlertDialogHeader: {
    render() {
      return h('div', { class: 'alert-dialog-header' }, this.$slots.default?.())
    }
  },
  AlertDialogTitle: {
    render() {
      return h('h2', { class: 'alert-dialog-title' }, this.$slots.default?.())
    }
  },
  AlertDialogDescription: {
    render() {
      return h('p', { class: 'alert-dialog-description' }, this.$slots.default?.())
    }
  },
  AlertDialogFooter: {
    render() {
      return h('div', { class: 'alert-dialog-footer' }, this.$slots.default?.())
    }
  },
  AlertDialogAction: {
    props: ['disabled'],
    render() {
      return h('button', {
        class: 'alert-dialog-action',
        disabled: this.disabled
      }, this.$slots.default?.())
    }
  },
  AlertDialogCancel: {
    render() {
      return h('button', { class: 'alert-dialog-cancel' }, this.$slots.default?.())
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

// Mock Inertia components and functions
vi.mock('@inertiajs/vue3', () => {
  const useFormMock = vi.fn(() => ({
    id: null,
    name: '',
    organisation_id: null,
    isActive: false,
    errors: {},
    processing: false,
    post: vi.fn(),
    put: vi.fn(),
    reset: vi.fn()
  }))

  return {
    Head: {
      render: () => {},
    },
    router: {
      get: vi.fn(),
      delete: vi.fn()
    },
    useForm: useFormMock
  }
})

describe('Clients.vue', () => {
  const mockClients = {
    data: [
      { id: 1, name: 'Client 1' },
      { id: 2, name: 'Client 2' }
    ],
    per_page: 10,
    current_page: 1,
    total: 2
  }

  const mockOrganisations = [
    { id: 1, name: 'Organisation 1' },
    { id: 2, name: 'Organisation 2' }
  ]

  const mockFilters = {
    search: ''
  }

  it('renders clients table correctly', () => {
    const wrapper = mount(Clients, {
      props: {
        clients: mockClients,
        organisations: mockOrganisations,
        filters: mockFilters
      }
    })

    expect(wrapper.find('.o-table').exists()).toBe(true)
    expect(wrapper.text()).toContain('No clients found')
  })

  it('shows add client button', () => {
    const wrapper = mount(Clients, {
      props: {
        clients: mockClients,
        organisations: mockOrganisations,
        filters: mockFilters
      }
    })

    const addButton = wrapper.findAll('button').find(btn => btn.text().includes('Add Client'))
    expect(addButton).toBeDefined()
  })

  it('has search input', () => {
    const wrapper = mount(Clients, {
      props: {
        clients: mockClients,
        organisations: mockOrganisations,
        filters: mockFilters
      }
    })

    const searchInput = wrapper.find('input[placeholder="Search..."]')
    expect(searchInput.exists()).toBe(true)
  })
})
