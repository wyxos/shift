import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { h } from 'vue'
import Welcome from '@/pages/Welcome.vue'

// Mock Inertia components
vi.mock('@inertiajs/vue3', () => ({
  Head: {
    render: () => {},
  },
  Link: {
    props: ['href'],
    render() {
      return h('a', { href: this.href || '#' }, this.$slots.default?.())
    }
  }
}))

// Mock the $page.props.auth object
const mockAuth = {
  user: null
}

describe('Welcome.vue', () => {
  it('renders welcome page correctly', () => {
    const wrapper = mount(Welcome, {
      global: {
        mocks: {
          $page: {
            props: {
              auth: mockAuth
            }
          },
          route: (name) => `/${name}`
        }
      }
    })

    expect(wrapper.find('h1').text()).toContain("Let's get started")
    expect(wrapper.text()).toContain('Laravel has an incredibly rich ecosystem')
  })

  it('shows login and get started links when user is not authenticated', () => {
    const wrapper = mount(Welcome, {
      global: {
        mocks: {
          $page: {
            props: {
              auth: { user: null }
            }
          },
          route: (name) => `/${name}`
        }
      }
    })

    const links = wrapper.findAll('a')
    expect(links.some(link => link.text().includes('Log in'))).toBe(true)
    expect(links.some(link => link.text().includes('Get Started'))).toBe(true)
    expect(links.some(link => link.text().includes('Dashboard'))).toBe(false)
  })

  it('shows dashboard link when user is authenticated', () => {
    const wrapper = mount(Welcome, {
      global: {
        mocks: {
          $page: {
            props: {
              auth: { user: { id: 1, name: 'Test User' } }
            }
          },
          route: (name) => `/${name}`
        }
      }
    })

    const links = wrapper.findAll('a')
    expect(links.some(link => link.text().includes('Dashboard'))).toBe(true)
    expect(links.some(link => link.text().includes('Log in'))).toBe(false)
    expect(links.some(link => link.text().includes('Register'))).toBe(false)
  })
})
