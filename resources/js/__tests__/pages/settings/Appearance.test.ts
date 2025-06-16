import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { h } from 'vue'
import Appearance from '@/pages/settings/Appearance.vue'

// Mock components
vi.mock('@/layouts/AppLayout.vue', () => ({
  default: {
    props: ['breadcrumbs'],
    render() {
      return h('div', { class: 'app-layout' }, this.$slots.default?.())
    }
  }
}))

vi.mock('@/layouts/settings/Layout.vue', () => ({
  default: {
    render() {
      return h('div', { class: 'settings-layout' }, this.$slots.default?.())
    }
  }
}))

vi.mock('@/components/HeadingSmall.vue', () => ({
  default: {
    props: ['title', 'description'],
    render() {
      return h('div', { class: 'heading-small' }, [
        h('h3', {}, this.title),
        h('p', {}, this.description)
      ])
    }
  }
}))

vi.mock('@/components/AppearanceTabs.vue', () => ({
  default: {
    render() {
      return h('div', { class: 'appearance-tabs' }, 'Appearance Tabs Content')
    }
  }
}))

// Mock Inertia components and functions
vi.mock('@inertiajs/vue3', () => {
  return {
    Head: {
      render: () => {},
    }
  }
})

describe('settings/Appearance.vue', () => {
  it('renders the appearance settings page correctly', () => {
    const wrapper = mount(Appearance)

    expect(wrapper.find('.app-layout').exists()).toBe(true)
    expect(wrapper.find('.settings-layout').exists()).toBe(true)
    expect(wrapper.find('.heading-small').exists()).toBe(true)
    expect(wrapper.find('.appearance-tabs').exists()).toBe(true)
    expect(wrapper.text()).toContain('Appearance settings')
    expect(wrapper.text()).toContain('Update your account\'s appearance settings')
    expect(wrapper.text()).toContain('Appearance Tabs Content')
  })

  it('passes correct breadcrumbs to AppLayout', () => {
    const wrapper = mount(Appearance)

    const appLayout = wrapper.findComponent('.app-layout')
    expect(appLayout.props('breadcrumbs')).toEqual([
      {
        title: 'Appearance settings',
        href: '/settings/appearance'
      }
    ])
  })

  it('passes correct title and description to HeadingSmall', () => {
    const wrapper = mount(Appearance)

    const headingSmall = wrapper.findComponent('.heading-small')
    expect(headingSmall.props('title')).toBe('Appearance settings')
    expect(headingSmall.props('description')).toBe('Update your account\'s appearance settings')
  })
})
