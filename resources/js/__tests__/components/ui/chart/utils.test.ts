import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { defineComponent, h } from 'vue'
import { componentToString } from '@/components/ui/chart'

const Tooltip = defineComponent({
    props: {
        payload: {
            type: Object,
            default: () => ({}),
        },
    },
    setup(props) {
        return () => h('div', String((props.payload as Record<string, unknown>).value ?? ''))
    },
})

describe('componentToString', () => {
    it('returns an empty string when the chart library provides no active datum', () => {
        let output = '__unset__'

        const Harness = defineComponent({
            setup() {
                const template = componentToString({ value: { label: 'Value', color: 'var(--chart-1)' } }, Tooltip as any)!
                output = template(undefined, new Date('2026-03-22'))

                return () => h('div')
            },
        })

        mount(Harness)

        expect(output).toBe('')
    })

    it('renders tooltip markup when the chart library provides a nested data payload', () => {
        let output = ''

        const Harness = defineComponent({
            setup() {
                const template = componentToString({ value: { label: 'Value', color: 'var(--chart-1)' } }, Tooltip as any)!
                output = template({ data: { value: 7 } }, new Date('2026-03-22'))

                return () => h('div')
            },
        })

        mount(Harness)

        expect(output).toContain('7')
    })
})
