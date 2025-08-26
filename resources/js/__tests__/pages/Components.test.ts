import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { h, nextTick } from 'vue'
import Components from '@/pages/Components.vue'

// Mock AppLayout to avoid layout complexity
vi.mock('@/layouts/AppLayout.vue', () => ({
  default: {
    props: ['breadcrumbs'],
    render() {
      return h('div', { class: 'app-layout' }, this.$slots.default?.())
    },
  },
}))

// Mock Inertia Head component
vi.mock('@inertiajs/vue3', () => ({
  Head: { render: () => {} },
}))

describe('Components.vue (TipTap)', () => {
  it('renders TipTap editor container', async () => {
    const wrapper = mount(Components)
    await nextTick()
    expect(wrapper.find('[data-testid="tiptap-editor"]').exists()).toBe(true)
  })
})

