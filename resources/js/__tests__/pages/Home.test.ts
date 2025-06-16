import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { h } from 'vue'
import Home from '@/pages/Home.vue'

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

describe('Home.vue', () => {
  it('renders welcome message correctly', () => {
    const wrapper = mount(Home, {
      global: {
        mocks: {
          route: (name) => `/${name}`
        }
      }
    })

    expect(wrapper.find('h1').text()).toBe('SHIFT')
    expect(wrapper.find('p').text()).toContain('Service Hub for Integrated Framework Tasks')
  })

  it('shows login and register links when user is not authenticated', () => {
    const wrapper = mount(Home, {
      props: {
        auth: {
          user: null
        }
      },
      global: {
        mocks: {
          route: (name) => `/${name}`
        }
      }
    })

    const links = wrapper.findAll('a')
    expect(links.some(link => link.text().includes('Log In'))).toBe(true)
    expect(links.some(link => link.text().includes('Register'))).toBe(true)
    expect(links.some(link => link.text().includes('Go to Dashboard'))).toBe(false)
  })

  it('shows dashboard link when user is authenticated', () => {
    const wrapper = mount(Home, {
      props: {
        auth: {
          user: { id: 1, name: 'Test User' }
        }
      },
      global: {
        mocks: {
          route: (name) => `/${name}`
        }
      }
    })

    const links = wrapper.findAll('a')
    expect(links.some(link => link.text().includes('Go to Dashboard'))).toBe(true)
    expect(links.some(link => link.text().includes('Log In'))).toBe(false)
    expect(links.some(link => link.text().includes('Register'))).toBe(false)
  })
})
