import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { h } from 'vue'
import Index from '@/pages/Organisations/Index.vue'

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

// Mock lodash debounce
vi.mock('lodash/debounce', () => ({
  default: (fn) => fn
}))

// Mock fetch
global.fetch = vi.fn(() =>
  Promise.resolve({
    json: () => Promise.resolve([
      { id: 1, user_name: 'User 1', user_email: 'user1@example.com' },
      { id: 2, user_name: 'User 2', user_email: 'user2@example.com' }
    ])
  })
) as any;

// Mock Inertia components and functions
vi.mock('@inertiajs/vue3', () => {
  const routerMock = {
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn()
  }

  const useFormMock = vi.fn((initialValues) => ({
    ...initialValues,
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
    router: routerMock,
    useForm: useFormMock
  }
})

describe('Organisations/Index.vue', () => {
  const mockOrganisations = {
    data: [
      { id: 1, name: 'Organisation 1' },
      { id: 2, name: 'Organisation 2' }
    ],
    current_page: 1,
    per_page: 10,
    total: 2
  }

  const mockFilters = {
    search: ''
  }

  it('renders the organisations table correctly', () => {
    const wrapper = mount(Index, {
      props: {
        organisations: mockOrganisations,
        filters: mockFilters
      }
    })

    expect(wrapper.find('.o-table').exists()).toBe(true)
    expect(wrapper.findAll('.o-table-column').length).toBeGreaterThan(0)
  })

  it('displays search input and buttons', () => {
    const wrapper = mount(Index, {
      props: {
        organisations: mockOrganisations,
        filters: mockFilters
      }
    })

    expect(wrapper.find('input[placeholder="Search..."]').exists()).toBe(true)

    const buttons = wrapper.findAll('button')
    const resetButton = buttons.find(btn => btn.text().includes('Reset'))
    const addButton = buttons.find(btn => btn.text().includes('Add Organisation'))

    expect(resetButton).toBeDefined()
    expect(addButton).toBeDefined()
  })

  it('displays action buttons for each organisation', () => {
    const wrapper = mount(Index, {
      props: {
        organisations: mockOrganisations,
        filters: mockFilters
      }
    })

    // We can't directly test the buttons in each row due to the mocked OTable
    // But we can check if the buttons are defined in the component
    const inviteIcon = wrapper.findAll('i.fa-user-plus')
    const usersIcon = wrapper.findAll('i.fa-users')
    const editIcon = wrapper.findAll('i.fa-edit')
    const deleteIcon = wrapper.findAll('i.fa-trash')

    expect(inviteIcon.length).toBeGreaterThan(0)
    expect(usersIcon.length).toBeGreaterThan(0)
    expect(editIcon.length).toBeGreaterThan(0)
    expect(deleteIcon.length).toBeGreaterThan(0)
  })

  it('displays empty state when no organisations found', () => {
    const emptyOrganisations = {
      data: [],
      current_page: 1,
      per_page: 10,
      total: 0
    }

    const wrapper = mount(Index, {
      props: {
        organisations: emptyOrganisations,
        filters: mockFilters
      }
    })

    // Since we're using mocked components, we can check if the empty slot is rendered
    expect(wrapper.text()).toContain('No organisations found')
  })

  it('opens create organisation dialog when add button is clicked', async () => {
    const wrapper = mount(Index, {
      props: {
        organisations: mockOrganisations,
        filters: mockFilters
      }
    })

    // Initially, the create dialog should not be visible
    expect(wrapper.find('.alert-dialog').exists()).toBe(false)

    // Find the add button and click it
    const addButton = wrapper.findAll('button').find(btn => btn.text().includes('Add Organisation'))
    await addButton?.trigger('click')

    // Now we need to manually set the createForm.isActive to true since we can't directly trigger the state change
    await wrapper.setData({ createForm: { isActive: true } })

    // Now the create dialog should be visible
    expect(wrapper.find('.alert-dialog').exists()).toBe(true)
    expect(wrapper.text()).toContain('Create Organisation')
  })

  it('opens delete confirmation dialog', async () => {
    const wrapper = mount(Index, {
      props: {
        organisations: mockOrganisations,
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
    expect(wrapper.text()).toContain('Delete Organisation')
    expect(wrapper.text()).toContain('Are you sure you want to delete this organisation?')
  })

  it('opens edit organisation dialog', async () => {
    const wrapper = mount(Index, {
      props: {
        organisations: mockOrganisations,
        filters: mockFilters
      }
    })

    // Initially, the edit dialog should not be visible
    expect(wrapper.find('.alert-dialog').exists()).toBe(false)

    // We can't directly trigger the edit button click due to the mocked components
    // But we can manually set the editDialogOpen to true to test the dialog
    await wrapper.setData({
      editDialogOpen: true,
      editForm: { id: 1, name: 'Organisation 1' }
    })

    // Now the edit dialog should be visible
    expect(wrapper.find('.alert-dialog').exists()).toBe(true)
    expect(wrapper.text()).toContain('Edit Organisation')
  })

  it('opens invite user dialog', async () => {
    const wrapper = mount(Index, {
      props: {
        organisations: mockOrganisations,
        filters: mockFilters
      }
    })

    // Initially, the invite dialog should not be visible
    expect(wrapper.find('.alert-dialog').exists()).toBe(false)

    // We can't directly trigger the invite button click due to the mocked components
    // But we can manually set the inviteDialogOpen to true to test the dialog
    await wrapper.setData({
      inviteDialogOpen: true,
      inviteForm: {
        organisation_id: 1,
        organisation_name: 'Organisation 1',
        email: '',
        name: ''
      }
    })

    // Now the invite dialog should be visible
    expect(wrapper.find('.alert-dialog').exists()).toBe(true)
    expect(wrapper.text()).toContain('Invite User to Organisation')
  })

  it('opens manage users dialog', async () => {
    const wrapper = mount(Index, {
      props: {
        organisations: mockOrganisations,
        filters: mockFilters
      }
    })

    // Initially, the manage users dialog should not be visible
    expect(wrapper.find('.alert-dialog').exists()).toBe(false)

    // We can't directly trigger the manage users button click due to the mocked components
    // But we can manually set the manageUsersForm.isOpen to true to test the dialog
    await wrapper.setData({
      manageUsersForm: {
        isOpen: true,
        organisation_id: 1,
        organisation_name: 'Organisation 1',
        users: [
          { id: 1, user_name: 'User 1', user_email: 'user1@example.com' },
          { id: 2, user_name: 'User 2', user_email: 'user2@example.com' }
        ]
      }
    })

    // Now the manage users dialog should be visible
    expect(wrapper.find('.alert-dialog').exists()).toBe(true)
    expect(wrapper.text()).toContain('Manage Organisation Access')
    expect(wrapper.text()).toContain('User 1')
    expect(wrapper.text()).toContain('User 2')
  })
})
