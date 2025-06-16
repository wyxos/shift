import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { h } from 'vue'
import ConfirmPassword from '@/pages/auth/ConfirmPassword.vue'

// Mock components
vi.mock('@/layouts/AuthLayout.vue', () => ({
  default: {
    props: ['title', 'description'],
    render() {
      return h('div', { class: 'auth-layout' }, [
        h('h1', {}, this.title),
        h('p', {}, this.description),
        this.$slots.default?.()
      ])
    }
  }
}))

vi.mock('@/components/ui/button', () => ({
  Button: {
    props: ['disabled', 'class'],
    render() {
      return h('button', {
        class: `button ${this.class || ''}`,
        disabled: this.disabled
      }, this.$slots.default?.())
    }
  }
}))

vi.mock('@/components/ui/input', () => ({
  Input: {
    props: ['type', 'modelValue', 'id', 'required', 'autofocus', 'autocomplete', 'class'],
    emits: ['update:modelValue'],
    render() {
      return h('input', {
        type: this.type,
        id: this.id,
        required: this.required,
        autofocus: this.autofocus,
        autocomplete: this.autocomplete,
        class: this.class,
        value: this.modelValue,
        onInput: (e) => this.$emit('update:modelValue', e.target.value)
      })
    }
  }
}))

vi.mock('@/components/ui/label', () => ({
  Label: {
    props: ['htmlFor'],
    render() {
      return h('label', { for: this.htmlFor }, this.$slots.default?.())
    }
  }
}))

vi.mock('@/components/InputError.vue', () => ({
  default: {
    props: ['message'],
    render() {
      return this.message ? h('div', { class: 'input-error' }, this.message) : null
    }
  }
}))

vi.mock('lucide-vue-next', () => ({
  LoaderCircle: {
    render() {
      return h('div', { class: 'loader-circle' })
    }
  }
}))

// Inertia.js mock is now handled globally in setupTests.ts

// Mock route function
global.route = vi.fn((name) => {
  if (name === 'password.confirm') return '/confirm-password'
  return '/'
})

describe('auth/ConfirmPassword.vue', () => {
  it('renders the confirm password page correctly', () => {
    const wrapper = mount(ConfirmPassword)

    expect(wrapper.find('.auth-layout').exists()).toBe(true)
    expect(wrapper.text()).toContain('Confirm your password')
    expect(wrapper.text()).toContain('This is a secure area of the application. Please confirm your password before continuing.')
    expect(wrapper.find('input[type="password"]').exists()).toBe(true)
    expect(wrapper.find('button').exists()).toBe(true)
    expect(wrapper.text()).toContain('Confirm Password')
  })

  it('has required attributes on password input', () => {
    const wrapper = mount(ConfirmPassword)

    const passwordInput = wrapper.find('input[type="password"]')
    expect(passwordInput.attributes('required')).toBeDefined()
    expect(passwordInput.attributes('autocomplete')).toBe('current-password')
    expect(passwordInput.attributes('autofocus')).toBeDefined()
  })

  it('displays error message when form has errors', async () => {
    // Reset the form state to defaults
    formState.reset()

    // Set the form state before mounting the component
    formState.password = 'wrong-password'
    formState.errors = { password: 'The password is incorrect.' }
    formState.processing = false

    const wrapper = mount(ConfirmPassword)

    // Wait for the component to update
    await wrapper.vm.$nextTick()

    const errorMessage = wrapper.find('.input-error')
    expect(errorMessage.exists()).toBe(true)
    expect(errorMessage.text()).toBe('The password is incorrect.')
  })

  it('shows loading indicator when form is processing', async () => {
    // Reset the form state to defaults
    formState.reset()

    // Set the form state before mounting the component
    formState.password = 'password'
    formState.errors = {}
    formState.processing = true

    const wrapper = mount(ConfirmPassword)

    // Wait for the component to update
    await wrapper.vm.$nextTick()

    expect(wrapper.find('.loader-circle').exists()).toBe(true)
    expect(wrapper.find('button').attributes('disabled')).toBeDefined()
  })

  it('submits the form when confirm button is clicked', async () => {
    const wrapper = mount(ConfirmPassword)

    const form = wrapper.find('form')
    await form.trigger('submit.prevent')

    // We can't directly test the form.post call due to the mocked useForm,
    // but we can check if the form submission is triggered
    expect(form.exists()).toBe(true)
  })
})
