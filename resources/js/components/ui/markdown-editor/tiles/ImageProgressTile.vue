<template>
  <svg :width="width" :height="height" viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg">
    <rect x="1" y="1" width="198" height="198" :fill="bg" :stroke="border" stroke-width="2" />
    <rect x="20" y="120" width="160" height="12" rx="6" :fill="barBg"/>
    <rect x="20" y="120" :width="160 * clamped" height="12" rx="6" :fill="bar"/>
    <text x="100" y="95" text-anchor="middle" :style="textStyle">{{ label }}</text>
    <text x="100" y="145" text-anchor="middle" :style="textStyle">{{ Math.round(percent) }}%</text>
  </svg>
</template>

<script setup lang="ts">
import { computed } from 'vue'

interface Props {
  percent?: number
  label?: string
  width?: number|string
  height?: number|string
  bg?: string
  border?: string
  barBg?: string
  bar?: string
  text?: string
}
const props = withDefaults(defineProps<Props>(), {
  percent: 0,
  label: 'Uploading...',
  width: 200,
  height: 200,
  bg: '#f3f4f6',
  border: '#d1d5db',
  barBg: '#e5e7eb',
  bar: '#3b82f6',
  text: '#374151',
})
const clamped = computed(() => Math.max(0, Math.min(1, (props.percent ?? 0) / 100)))
const textStyle = computed(() => `font-family: ui-sans-serif,system-ui,-apple-system,'Segoe UI',Roboto,Ubuntu,'Helvetica Neue',Arial; fill:${props.text}; font-size:14px;`)
</script>

