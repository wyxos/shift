<script setup lang="ts">
import { computed, inject, ref } from 'vue'

const props = defineProps<{
  value: string
  disabled?: boolean
}>()

// Inject the selected value from the Tabs component
const selectedValue = inject('tabs-value', ref(''))
const updateValue = inject('tabs-update-value', (value: string) => {})

// Compute whether this trigger is selected
const isSelected = computed(() => selectedValue.value === props.value)

// Handle click event
const handleClick = (event) => {
  if (props.disabled) return
  // Prevent form submission
  event.preventDefault()
  event.stopPropagation()
  updateValue(props.value)
}
</script>

<template>
  <button
    type="button"
    :class="[
      'inline-flex items-center justify-center whitespace-nowrap rounded-sm px-3 py-1.5 text-sm font-medium ring-offset-background transition-all focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50',
      isSelected
        ? 'bg-background text-foreground shadow-sm'
        : 'text-muted-foreground hover:bg-muted hover:text-foreground'
    ]"
    :data-state="isSelected ? 'active' : 'inactive'"
    :disabled="disabled"
    @click="handleClick($event)"
  >
    <slot />
  </button>
</template>
