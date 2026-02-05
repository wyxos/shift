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
    },
  },
}))

vi.mock('@/components/ui/button', () => ({
  Button: {
    props: ['asChild', 'variant', 'size'],
    render() {
      return h('button', {}, this.$slots.default?.())
    },
  },
}))

vi.mock('@/components/ui/badge', () => ({
  Badge: {
    props: ['variant'],
    render() {
      return h('div', {}, this.$slots.default?.())
    },
  },
}))

vi.mock('@/components/ui/card', () => ({
  Card: {
    render() {
      return h('div', {}, this.$slots.default?.())
    },
  },
  CardHeader: {
    render() {
      return h('div', {}, this.$slots.default?.())
    },
  },
  CardTitle: {
    render() {
      return h('div', {}, this.$slots.default?.())
    },
  },
  CardDescription: {
    render() {
      return h('div', {}, this.$slots.default?.())
    },
  },
  CardContent: {
    render() {
      return h('div', {}, this.$slots.default?.())
    },
  },
}))

vi.mock('lucide-vue-next', () => ({
  CheckCircle2: { render: () => h('span') },
  FolderKanban: { render: () => h('span') },
  Github: { render: () => h('span') },
  MessageSquare: { render: () => h('span') },
  Paperclip: { render: () => h('span') },
  Plug: { render: () => h('span') },
}))

describe('Home.vue', () => {
  it('renders the hero message focused on SHIFT', () => {
    const wrapper = mount(Home, {
      global: {
        mocks: {
          route: (name) => `/${name}`,
        },
      },
    })

    const heading = wrapper.find('h1').text()
    expect(heading).toContain('SHIFT keeps client work on track')
    expect(wrapper.text()).toContain('organizations, clients, projects, and tasks')
  })

  it('shows login link and GitHub icon when user is not authenticated', () => {
    const wrapper = mount(Home, {
      props: {
        auth: {
          user: null,
        },
      },
      global: {
        mocks: {
          route: (name) => `/${name}`,
        },
      },
    })

    const links = wrapper.findAll('a')
    expect(links.some((link) => link.text().includes('Log in'))).toBe(true)
    expect(links.some((link) => link.attributes('href')?.includes('github.com/wyxos/shift'))).toBe(true)
    expect(links.some((link) => link.text().includes('Go to Dashboard'))).toBe(false)
  })

  it('shows dashboard link when user is authenticated', () => {
    const wrapper = mount(Home, {
      props: {
        auth: {
          user: { id: 1, name: 'Test User' },
        },
      },
      global: {
        mocks: {
          route: (name) => `/${name}`,
        },
      },
    })

    const links = wrapper.findAll('a')
    expect(links.some((link) => link.text().includes('Go to Dashboard'))).toBe(true)
    expect(links.some((link) => link.text().includes('Log in'))).toBe(false)
  })
})
