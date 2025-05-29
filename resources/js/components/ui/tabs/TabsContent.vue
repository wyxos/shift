<script setup lang="ts">
import { computed, inject, ref } from 'vue'

const props = defineProps<{
  value: string
}>()

// Inject the selected value from the Tabs component
const selectedValue = inject('tabs-value', ref(''))

// Compute whether this content should be shown
const isSelected = computed(() => selectedValue.value === props.value)
</script>

<template>
  <div
    v-if="isSelected"
    :class="[
      'mt-2 ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2',
    ]"
    :data-state="isSelected ? 'active' : 'inactive'"
  >
    <slot />
  </div>
</template>
