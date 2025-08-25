<template>
  <svg :width="width" :height="height" viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg">
    <rect x="1" y="1" width="198" height="198" :fill="bg" :stroke="stroke" stroke-width="2"/>
    <text x="100" y="100" text-anchor="middle" :style="textStyle">{{ message }}</text>
  </svg>
</template>

<script setup lang="ts">
interface Props {
  message?: string
  width?: number|string
  height?: number|string
  bg?: string
  stroke?: string
  textColor?: string
}
const props = withDefaults(defineProps<Props>(), {
  message: 'Upload failed',
  width: 200,
  height: 200,
  bg: '#FEF2F2',
  stroke: '#FCA5A5',
  textColor: '#B91C1C',
})
const textStyle = `font-family: ui-sans-serif,system-ui,-apple-system,'Segoe UI',Roboto,Ubuntu,'Helvetica Neue',Arial; fill:${props.textColor}; font-size:14px;`
</script>

