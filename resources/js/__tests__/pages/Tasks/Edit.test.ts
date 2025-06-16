import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { h } from 'vue'
import Edit from '@/pages/Tasks/Edit.vue'

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

vi.mock('@/components/ui/label', () => ({
  Label: {
    props: ['for'],
    render() {
      return h('label', { for: this.for }, this.$slots.default?.())
    }
  }
}))

vi.mock('@/components/ui/card', () => ({
  Card: {
    render() {
      return h('div', { class: 'card' }, this.$slots.default?.())
    }
  },
  CardHeader: {
    render() {
      return h('div', { class: 'card-header' }, this.$slots.default?.())
    }
  },
  CardTitle: {
    render() {
      return h('h2', { class: 'card-title' }, this.$slots.default?.())
    }
  },
  CardContent: {
    render() {
      return h('div', { class: 'card-content' }, this.$slots.default?.())
    }
  },
  CardFooter: {
    render() {
      return h('div', { class: 'card-footer' }, this.$slots.default?.())
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
    get: vi.fn(() => Promise.resolve({ data: { internal: [], external: [], files: [] } })),
    delete: vi.fn(() => Promise.resolve())
  }
}))

// Mock marked
vi.mock('marked', () => ({
  marked: (text) => text
}))

// Mock Inertia components and functions
vi.mock('@inertiajs/vue3', () => {
  const useFormMock = vi.fn(() => ({
    title: '',
    description: '',
    project_id: null,
    status: 'pending',
    priority: 'medium',
    temp_identifier: '',
    deleted_attachment_ids: [],
    external_user_ids: [],
    errors: {},
    processing: false,
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

describe('Tasks/Edit.vue', () => {
  const mockTask = {
    id: 1,
    title: 'Test Task',
    description: 'Test Description',
    project_id: 1,
    status: 'pending',
    priority: 'medium'
  }

  const mockProject = {
    id: 1,
    name: 'Test Project'
  }

  const mockAttachments = [
    { id: 1, path: '/path/to/file1.pdf', original_filename: 'file1.pdf', url: '/files/1' },
    { id: 2, path: '/path/to/file2.jpg', original_filename: 'file2.jpg', url: '/files/2' }
  ]

  const mockProjectExternalUsers = [
    { id: 1, name: 'External User 1', email: 'user1@example.com', external_id: 'ext1' },
    { id: 2, name: 'External User 2', email: 'user2@example.com', external_id: 'ext2' }
  ]

  const mockTaskExternalUserIds = [1]

  it('renders the edit task form correctly', () => {
    const wrapper = mount(Edit, {
      props: {
        task: mockTask,
        project: mockProject,
        attachments: mockAttachments,
        projectExternalUsers: mockProjectExternalUsers,
        taskExternalUserIds: mockTaskExternalUserIds
      }
    })

    expect(wrapper.find('.card-title').text()).toContain('Edit Task')
    expect(wrapper.find('input[type="text"]').exists()).toBe(true)
    expect(wrapper.find('.markdown-editor').exists()).toBe(true)
  })

  it('displays project name', () => {
    const wrapper = mount(Edit, {
      props: {
        task: mockTask,
        project: mockProject,
        attachments: mockAttachments,
        projectExternalUsers: mockProjectExternalUsers,
        taskExternalUserIds: mockTaskExternalUserIds
      }
    })

    expect(wrapper.text()).toContain('Project: Test Project')
  })

  it('displays status dropdown with correct options', () => {
    const wrapper = mount(Edit, {
      props: {
        task: mockTask,
        project: mockProject,
        attachments: mockAttachments,
        projectExternalUsers: mockProjectExternalUsers,
        taskExternalUserIds: mockTaskExternalUserIds
      }
    })

    const statusSelect = wrapper.find('select#status')
    expect(statusSelect.exists()).toBe(true)

    const options = statusSelect.findAll('option')
    expect(options.length).toBe(4)
    expect(options[0].text()).toBe('Pending')
    expect(options[1].text()).toBe('In Progress')
    expect(options[2].text()).toBe('Completed')
    expect(options[3].text()).toBe('Awaiting Feedback')
  })

  it('displays priority dropdown with correct options', () => {
    const wrapper = mount(Edit, {
      props: {
        task: mockTask,
        project: mockProject,
        attachments: mockAttachments,
        projectExternalUsers: mockProjectExternalUsers,
        taskExternalUserIds: mockTaskExternalUserIds
      }
    })

    const prioritySelect = wrapper.find('select#priority')
    expect(prioritySelect.exists()).toBe(true)

    const options = prioritySelect.findAll('option')
    expect(options.length).toBe(3)
    expect(options[0].text()).toBe('Low')
    expect(options[1].text()).toBe('Medium')
    expect(options[2].text()).toBe('High')
  })

  it('displays existing attachments', () => {
    const wrapper = mount(Edit, {
      props: {
        task: mockTask,
        project: mockProject,
        attachments: mockAttachments,
        projectExternalUsers: mockProjectExternalUsers,
        taskExternalUserIds: mockTaskExternalUserIds
      }
    })

    expect(wrapper.text()).toContain('Existing Attachments:')
    expect(wrapper.text()).toContain('file1.pdf')
    expect(wrapper.text()).toContain('file2.jpg')
  })

  it('displays external users section', () => {
    const wrapper = mount(Edit, {
      props: {
        task: mockTask,
        project: mockProject,
        attachments: mockAttachments,
        projectExternalUsers: mockProjectExternalUsers,
        taskExternalUserIds: mockTaskExternalUserIds
      }
    })

    expect(wrapper.text()).toContain('Assign External Users')
    expect(wrapper.text()).toContain('External User 1')
    expect(wrapper.text()).toContain('External User 2')
  })

  it('displays comments section with tabs', () => {
    const wrapper = mount(Edit, {
      props: {
        task: mockTask,
        project: mockProject,
        attachments: mockAttachments,
        projectExternalUsers: mockProjectExternalUsers,
        taskExternalUserIds: mockTaskExternalUserIds
      }
    })

    expect(wrapper.text()).toContain('Comments')
    expect(wrapper.text()).toContain('Internal')
    expect(wrapper.text()).toContain('External')
  })
})
