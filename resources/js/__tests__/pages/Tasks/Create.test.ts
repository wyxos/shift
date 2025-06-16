import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { h } from 'vue'
import Create from '@/pages/Tasks/Create.vue'

// Mock components
vi.mock('@/layouts/AppLayout.vue', () => ({
  default: {
    props: ['breadcrumbs'],
    render() {
      return h('div', { class: 'app-layout' }, this.$slots.default?.())
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

vi.mock('@/components/ui/markdown-editor', () => ({
  MarkdownEditor: {
    props: ['modelValue', 'height', 'placeholder'],
    emits: ['update:modelValue'],
    render() {
      return h('textarea', {
        class: 'markdown-editor',
        placeholder: this.placeholder,
        style: { height: this.height },
        value: this.modelValue,
        onInput: (e) => this.$emit('update:modelValue', e.target.value)
      })
    }
  }
}))

// Mock axios
vi.mock('axios', () => ({
  default: {
    post: vi.fn(() => Promise.resolve({ data: {} })),
    get: vi.fn(() => Promise.resolve({ data: { files: [] } })),
    delete: vi.fn(() => Promise.resolve())
  }
}))

// Mock Inertia components and functions
vi.mock('@inertiajs/vue3', () => {
  const useFormMock = vi.fn(() => ({
    title: '',
    description: '',
    project_id: null,
    temp_identifier: '',
    external_user_ids: [],
    errors: {},
    processing: false,
    post: vi.fn(),
    reset: vi.fn()
  }))

  return {
    Head: {
      render: () => {},
    },
    router: {
      get: vi.fn()
    },
    useForm: useFormMock
  }
})

describe('Tasks/Create.vue', () => {
  const mockProjects = [
    {
      id: 1,
      name: 'Project 1',
      external_users: [
        { id: 1, name: 'External User 1', email: 'user1@example.com', external_id: 'ext1' },
        { id: 2, name: 'External User 2', email: 'user2@example.com', external_id: 'ext2' }
      ]
    },
    {
      id: 2,
      name: 'Project 2',
      external_users: []
    }
  ]

  it('renders the create task form correctly', () => {
    const wrapper = mount(Create, {
      props: {
        projects: mockProjects
      }
    })

    expect(wrapper.text()).toContain('Task Name')
    expect(wrapper.text()).toContain('Description')
    expect(wrapper.text()).toContain('Project')
    expect(wrapper.text()).toContain('Attachments')
    expect(wrapper.find('input[type="text"]').exists()).toBe(true)
    expect(wrapper.find('.markdown-editor').exists()).toBe(true)
    expect(wrapper.find('button[type="submit"]').text()).toBe('Create Task')
  })

  it('displays project dropdown with correct options', () => {
    const wrapper = mount(Create, {
      props: {
        projects: mockProjects
      }
    })

    const projectSelect = wrapper.find('select#project_id')
    expect(projectSelect.exists()).toBe(true)

    const options = projectSelect.findAll('option')
    expect(options.length).toBe(3) // Including the default "Select a project" option
    expect(options[0].text()).toBe('Select a project')
    expect(options[1].text()).toBe('Project 1')
    expect(options[2].text()).toBe('Project 2')
  })

  it('does not display external users section when no project is selected', () => {
    const wrapper = mount(Create, {
      props: {
        projects: mockProjects
      }
    })

    expect(wrapper.text()).not.toContain('Assign External Users')
  })

  it('displays external users section when a project with external users is selected', async () => {
    const wrapper = mount(Create, {
      props: {
        projects: mockProjects
      }
    })

    // Select the first project
    await wrapper.find('select#project_id').setValue(1)

    expect(wrapper.text()).toContain('Assign External Users')
    expect(wrapper.text()).toContain('External User 1')
    expect(wrapper.text()).toContain('External User 2')
  })

  it('displays "No external users" message when a project without external users is selected', async () => {
    const wrapper = mount(Create, {
      props: {
        projects: mockProjects
      }
    })

    // Select the second project
    await wrapper.find('select#project_id').setValue(2)

    expect(wrapper.text()).toContain('No external users available for this project')
  })

  it('displays file upload section', () => {
    const wrapper = mount(Create, {
      props: {
        projects: mockProjects
      }
    })

    expect(wrapper.find('input[type="file"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Upload files directly')
  })
})
