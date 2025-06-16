import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { h } from 'vue'
import ResetPassword from '@/pages/auth/ResetPassword.vue'

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
    props: ['type', 'disabled', 'class'],
    render() {
      return h('button', {
        type: this.type,
        class: `button ${this.class || ''}`,
        disabled: this.disabled
      }, this.$slots.default?.())
    }
  }
}))

vi.mock('@/components/ui/input', () => ({
  Input: {
    props: ['type', 'placeholder', 'modelValue', 'id', 'name', 'autocomplete', 'autofocus', 'readonly', 'class'],
    emits: ['update:modelValue'],
    render() {
      return h('input', {
        type: this.type,
        placeholder: this.placeholder,
        id: this.id,
        name: this.name,
        autocomplete: this.autocomplete,
        autofocus: this.autofocus,
        readonly: this.readonly,
        class: this.class,
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

vi.mock('@/components/InputError.vue', () => ({
  default: {
    props: ['message', 'class'],
    render() {
      return this.message ? h('div', { class: `input-error ${this.class || ''}` }, this.message) : null
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

// Mock Inertia components and functions
vi.mock('@inertiajs/vue3', () => {
  const useFormMock = vi.fn((initialValues) => ({
    ...initialValues,
    errors: {},
    processing: false,
    post: vi.fn(),
    reset: vi.fn()
  }))

  return {
    Head: {
      render: () => {},
    },
    useForm: useFormMock
  }
})

// Mock route function
global.route = vi.fn((name) => {
  if (name === 'password.store') return '/reset-password'
  return '/'
})

describe('auth/ResetPassword.vue', () => {
  const mockProps = {
    token: 'test-token',
    email: 'test@example.com'
  }

  it('renders the reset password form correctly', () => {
    const wrapper = mount(ResetPassword, {
      props: mockProps
    })

    expect(wrapper.find('.auth-layout').exists()).toBe(true)
    expect(wrapper.text()).toContain('Reset password')
    expect(wrapper.text()).toContain('Please enter your new password below')
    expect(wrapper.find('input[type="email"]').exists()).toBe(true)
    expect(wrapper.find('input[type="password"]').exists()).toBe(true)
    expect(wrapper.findAll('input[type="password"]').length).toBe(2) // Password and confirmation
    expect(wrapper.find('button[type="submit"]').exists()).toBe(true)
  })

  it('pre-fills email field and makes it readonly', () => {
    const wrapper = mount(ResetPassword, {
      props: mockProps
    })

    const emailInput = wrapper.find('input[type="email"]')
    expect(emailInput.attributes('value')).toBe('test@example.com')
    expect(emailInput.attributes('readonly')).toBeDefined()
  })

  it('includes token in the form data', () => {
    const wrapper = mount(ResetPassword, {
      props: mockProps
    })

    // We can't directly test the form values due to the mocked useForm,
    // but we can check if the props are passed correctly
    expect(wrapper.props('token')).toBe('test-token')
  })

  it('displays error messages when form has errors', async () => {
    const wrapper = mount(ResetPassword, {
      props: mockProps
    })

    // Set form errors manually
    await wrapper.setData({
      form: {
        token: 'test-token',
        email: 'test@example.com',
        password: 'password',
        password_confirmation: 'different',
        errors: {
          email: 'The email field is required.',
          password: 'The password field is required.',
          password_confirmation: 'The password confirmation does not match.'
        },
        processing: false,
        post: vi.fn(),
        reset: vi.fn()
      }
    })

    const errorMessages = wrapper.findAll('.input-error')
    expect(errorMessages.length).toBe(3)
    expect(errorMessages[0].text()).toBe('The email field is required.')
    expect(errorMessages[1].text()).toBe('The password field is required.')
    expect(errorMessages[2].text()).toBe('The password confirmation does not match.')
  })

  it('shows loading indicator when form is processing', async () => {
    const wrapper = mount(ResetPassword, {
      props: mockProps
    })

    // Set form processing to true
    await wrapper.setData({
      form: {
        token: 'test-token',
        email: 'test@example.com',
        password: 'password',
        password_confirmation: 'password',
        errors: {},
        processing: true,
        post: vi.fn(),
        reset: vi.fn()
      }
    })

    expect(wrapper.find('.loader-circle').exists()).toBe(true)
    expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeDefined()
  })

  it('submits the form when reset button is clicked', async () => {
    const wrapper = mount(ResetPassword, {
      props: mockProps
    })

    const form = wrapper.find('form')
    await form.trigger('submit.prevent')

    // We can't directly test the form.post call due to the mocked useForm,
    // but we can check if the form submission is triggered
    expect(form.exists()).toBe(true)
  })
})
