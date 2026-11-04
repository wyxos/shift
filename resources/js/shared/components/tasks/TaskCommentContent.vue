<script setup lang="ts">
import { computed } from 'vue';
import { imageTiles as vImageTiles } from '../../tasks/image-tile';
import { renderThreadContent } from '../../tasks/rich-content';

const props = defineProps<{ content: string }>();
const html = computed(() => renderThreadContent(props.content));
</script>

<template>
    <div
        v-image-tiles
        class="shift-rich text-inherit [&_img]:my-2 [&_img]:max-w-full [&_img]:cursor-zoom-in [&_img]:rounded-lg [&_img]:shadow-sm [&_img.editor-tile]:aspect-square [&_img.editor-tile]:w-[200px] [&_img.editor-tile]:max-w-[200px] [&_img.editor-tile]:object-contain"
        v-html="html"
    ></div>
</template>
