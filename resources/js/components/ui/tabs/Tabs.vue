<script setup lang="ts">
import { provide, ref, toRef, watch } from 'vue'

const props = defineProps<{
  defaultValue?: string
  value?: string
}>()

const emit = defineEmits<{
  'update:value': [value: string]
}>()

const selectedValue = ref(props.value ?? props.defaultValue ?? '')

// Watch for external value changes
watch(
  () => props.value,
  (value) => {
    if (value !== undefined && value !== selectedValue.value) {
      selectedValue.value = value
    }
  }
)

// Provide the selected value to child components
provide('tabs-value', selectedValue)

// Provide a function to update the selected value
provide('tabs-update-value', (value: string) => {
  selectedValue.value = value
  emit('update:value', value)
})
</script>

<template>
  <div class="tabs">
    <slot />
  </div>
</template>
