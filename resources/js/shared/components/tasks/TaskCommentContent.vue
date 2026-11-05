<script setup lang="ts">
import { computed } from 'vue';
import { imageTiles as vImageTiles } from '../../tasks/image-tile';
import { renderThreadContent } from '../../tasks/rich-content';

const props = defineProps<{ content: string }>();
const html = computed(() => renderThreadContent(props.content));
function onImageKeydown(event: KeyboardEvent): void {
    if (!(event.target instanceof HTMLImageElement) || !['Enter', ' '].includes(event.key)) return;
    event.preventDefault();
    event.target.click();
}
</script>

<template>
    <div v-image-tiles class="shift-rich text-inherit" @keydown="onImageKeydown" v-html="html"></div>
</template>

<style src="../../styles/message-images.css"></style>
