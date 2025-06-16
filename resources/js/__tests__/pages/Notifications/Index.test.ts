import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { h } from 'vue'
import Index from '@/pages/Notifications/Index.vue'

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

// Mock axios
vi.mock('axios', () => ({
  default: {
    post: vi.fn(() => Promise.resolve({ data: {} }))
  }
}))

// Mock Inertia components and functions
vi.mock('@inertiajs/vue3', () => {
  return {
    Link: {
      props: ['href'],
      render() {
        return h('a', {
          href: this.href,
          onClick: this.$attrs.onClick
        }, this.$slots.default?.())
      }
    }
  }
})

// Mock route function
global.route = vi.fn((name, params) => {
  if (name === 'dashboard') return '/dashboard'
  if (name === 'notifications.index') return '/notifications'
  if (name === 'tasks.edit') return `/tasks/${params?.task}/edit`
  if (name === 'projects.index') return '/projects'
  if (name === 'organisations.index') return '/organisations'
  return '/'
})

describe('Notifications/Index.vue', () => {
  const mockNotifications = {
    data: [
      {
        id: '1',
        type: 'App\\Notifications\\TaskCreationNotification',
        data: {
          task_id: 1,
          task_title: 'Test Task',
          project_name: 'Test Project'
        },
        read_at: null,
        created_at: '2023-01-01T12:00:00.000Z'
      },
      {
        id: '2',
        type: 'App\\Notifications\\TaskThreadUpdated',
        data: {
          type: 'internal',
          task_title: 'Test Task',
          content: 'This is a test reply',
          url: '/tasks/1/edit'
        },
        read_at: '2023-01-02T12:00:00.000Z',
        created_at: '2023-01-02T12:00:00.000Z'
      },
      {
        id: '3',
        type: 'App\\Notifications\\ProjectInvitationNotification',
        data: {
          project_name: 'Test Project'
        },
        read_at: null,
        created_at: '2023-01-03T12:00:00.000Z'
      }
    ],
    from: 1,
    to: 3,
    total: 3,
    prev_page_url: null,
    next_page_url: null
  }

  it('renders the notifications list correctly', () => {
    const wrapper = mount(Index, {
      props: {
        notifications: mockNotifications
      }
    })

    expect(wrapper.text()).toContain('Notifications')
    expect(wrapper.text()).toContain('New Task: Test Task')
    expect(wrapper.text()).toContain('New reply in internal thread for Test Task')
    expect(wrapper.text()).toContain('Invited to project: Test Project')
  })

  it('displays "Mark all as read" button when there are unread notifications', () => {
    const wrapper = mount(Index, {
      props: {
        notifications: mockNotifications
      }
    })

    const markAllButton = wrapper.findAll('button').find(btn => btn.text().includes('Mark all as read'))
    expect(markAllButton).toBeDefined()
  })

  it('does not display "Mark all as read" button when all notifications are read', () => {
    const allReadNotifications = {
      ...mockNotifications,
      data: mockNotifications.data.map(n => ({ ...n, read_at: '2023-01-01T12:00:00.000Z' }))
    }

    const wrapper = mount(Index, {
      props: {
        notifications: allReadNotifications
      }
    })

    const markAllButton = wrapper.findAll('button').find(btn => btn.text().includes('Mark all as read'))
    expect(markAllButton).toBeUndefined()
  })

  it('displays "Mark as read" button for unread notifications', () => {
    const wrapper = mount(Index, {
      props: {
        notifications: mockNotifications
      }
    })

    const markAsReadButtons = wrapper.findAll('button').filter(btn => btn.text().includes('Mark as read'))
    expect(markAsReadButtons.length).toBe(2) // Two unread notifications
  })

  it('displays "Mark as unread" button for read notifications', () => {
    const wrapper = mount(Index, {
      props: {
        notifications: mockNotifications
      }
    })

    const markAsUnreadButtons = wrapper.findAll('button').filter(btn => btn.text().includes('Mark as unread'))
    expect(markAsUnreadButtons.length).toBe(1) // One read notification
  })

  it('displays correct notification titles', () => {
    const wrapper = mount(Index, {
      props: {
        notifications: mockNotifications
      }
    })

    expect(wrapper.text()).toContain('New Task: Test Task')
    expect(wrapper.text()).toContain('New reply in internal thread for Test Task')
    expect(wrapper.text()).toContain('Invited to project: Test Project')
  })

  it('displays correct notification descriptions', () => {
    const wrapper = mount(Index, {
      props: {
        notifications: mockNotifications
      }
    })

    expect(wrapper.text()).toContain('Task created in project: Test Project')
    expect(wrapper.text()).toContain('This is a test reply')
    expect(wrapper.text()).toContain('You have been invited to join the project: Test Project')
  })

  it('displays pagination information', () => {
    const wrapper = mount(Index, {
      props: {
        notifications: mockNotifications
      }
    })

    expect(wrapper.text()).toContain('Showing 1 to 3 of 3 notifications')
  })

  it('displays pagination links when available', () => {
    const paginatedNotifications = {
      ...mockNotifications,
      next_page_url: '/notifications?page=2'
    }

    const wrapper = mount(Index, {
      props: {
        notifications: paginatedNotifications
      }
    })

    const nextLink = wrapper.find('a[href="/notifications?page=2"]')
    expect(nextLink.exists()).toBe(true)
    expect(nextLink.text()).toBe('Next')
  })

  it('displays empty state when no notifications', () => {
    const emptyNotifications = {
      data: [],
      from: 0,
      to: 0,
      total: 0,
      prev_page_url: null,
      next_page_url: null
    }

    const wrapper = mount(Index, {
      props: {
        notifications: emptyNotifications
      }
    })

    expect(wrapper.text()).toContain('No notifications found')
  })

  it('marks notification as read when clicked', async () => {
    const wrapper = mount(Index, {
      props: {
        notifications: mockNotifications
      }
    })

    // Find the first unread notification link
    const notificationLink = wrapper.findAll('a').find(link =>
      link.text().includes('New Task: Test Task')
    )

    await notificationLink?.trigger('click')

    // Check if axios.post was called with the correct parameters
    const axios = vi.mocked(await import('axios')).default;
    expect(axios.post).toHaveBeenCalledWith(
      expect.any(String), // We can't check the exact route since it's mocked
      expect.any(Object)
    )
  })
})
