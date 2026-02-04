<script setup lang="ts">
import { computed, ref } from 'vue'
import SharedShiftEditor from '@shared/components/ShiftEditor.vue'

defineOptions({ inheritAttrs: false })

const innerRef = ref<InstanceType<typeof SharedShiftEditor> | null>(null)
const editor = computed(() => innerRef.value?.editor ?? null)

defineExpose({ editor })
</script>

<template>
  <SharedShiftEditor ref="innerRef" v-bind="$attrs" />
</template>
