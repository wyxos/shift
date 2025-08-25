<template>
  <svg :width="width" :height="height" viewBox="0 0 200 100" xmlns="http://www.w3.org/2000/svg">
    <rect x="1" y="1" width="198" height="98" :fill="bg" :stroke="border" stroke-width="2"/>

    <rect x="14" y="18" width="28" height="34" rx="3" :fill="fileBg" :stroke="fileStroke" stroke-width="2"/>
    <polyline points="42,18 42,28 52,28" fill="none" :stroke="fileStroke" stroke-width="2"/>

    <text x="64" y="35" :style="textStyle">{{ filenameShort }}</text>

    <rect x="64" y="52" width="122" height="8" rx="4" :fill="barBg"/>
    <rect x="64" y="52" :width="122 * clamped" height="8" rx="4" :fill="bar"/>
    <text x="190" y="58" text-anchor="end" :style="textStyle">{{ Math.round(percent) }}%</text>
  </svg>
</template>

<script setup lang="ts">
import { computed } from 'vue'

interface Props {
  percent?: number
  filename?: string
  width?: number|string
  height?: number|string
  bg?: string
  border?: string
  text?: string
  barBg?: string
  bar?: string
  fileBg?: string
  fileStroke?: string
}
const props = withDefaults(defineProps<Props>(), {
  percent: 0,
  filename: 'Uploading...',
  width: 200,
  height: 100,
  bg: '#f9fafb',
  border: '#d1d5db',
  text: '#111827',
  barBg: '#e5e7eb',
  bar: '#3b82f6',
  fileBg: '#dbeafe',
  fileStroke: '#93c5fd',
})

const filenameShort = computed(() => (props.filename || '').slice(0, 24))
const clamped = computed(() => Math.max(0, Math.min(1, props.percent / 100)))
const textStyle = computed(() => `font-family: ui-sans-serif,system-ui,-apple-system,'Segoe UI',Roboto,Ubuntu,'Helvetica Neue',Arial; fill:${props.text}; font-size:12px;`)
</script>

