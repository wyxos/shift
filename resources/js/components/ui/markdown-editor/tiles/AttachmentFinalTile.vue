<template>
  <svg :width="width" :height="height" viewBox="0 0 200 100" xmlns="http://www.w3.org/2000/svg">
    <rect x="1" y="1" width="198" height="98" :fill="bg" :stroke="border" stroke-width="2"/>

    <rect x="14" y="20" width="32" height="40" rx="4" :fill="fileBg" :stroke="fileStroke" stroke-width="2"/>
    <polyline points="46,20 46,32 58,32" fill="none" :stroke="fileStroke" stroke-width="2"/>

    <text x="64" y="38" :style="textStyle">{{ filenameShort }}</text>
    <text x="64" y="58" :style="mutedTextStyle">{{ sizeLabel }}</text>
  </svg>
</template>

<script setup lang="ts">
import { computed } from 'vue'

interface Props {
  filename?: string
  sizeLabel?: string
  width?: number|string
  height?: number|string
  bg?: string
  border?: string
  text?: string
  muted?: string
  fileBg?: string
  fileStroke?: string
}
const props = withDefaults(defineProps<Props>(), {
  filename: 'file',
  sizeLabel: '',
  width: 200,
  height: 100,
  bg: '#f9fafb',
  border: '#d1d5db',
  text: '#111827',
  muted: '#6b7280',
  fileBg: '#dbeafe',
  fileStroke: '#93c5fd',
})

const filenameShort = computed(() => (props.filename || '').slice(0, 28))
const textStyle = computed(() => `font-family: ui-sans-serif,system-ui,-apple-system,'Segoe UI',Roboto,Ubuntu,'Helvetica Neue',Arial; fill:${props.text}; font-size:12px;`)
const mutedTextStyle = computed(() => `font-family: ui-sans-serif,system-ui,-apple-system,'Segoe UI',Roboto,Ubuntu,'Helvetica Neue',Arial; fill:${props.muted}; font-size:12px;`)
</script>

