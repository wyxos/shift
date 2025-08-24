<template>
  <div ref="containerRef" class="milkdown-editor relative border-2 border-blue-500 rounded">
    <!-- Upload overlay spinner -->
    <div v-if="isUploading" class="absolute inset-0 bg-white/70 backdrop-blur-[1px] flex items-center justify-center z-10">
      <div class="inline-flex items-center gap-2 text-gray-700">
        <span class="inline-block w-5 h-5 border-2 border-gray-300 border-t-gray-600 rounded-full animate-spin"></span>
        <span>Uploading image...</span>
      </div>
    </div>
    <Milkdown />
  </div>
</template>

<script>
import { Editor, rootCtx } from "@milkdown/kit/core";
import { editorViewCtx } from "@milkdown/core";
import { commonmark } from "@milkdown/kit/preset/commonmark";
import { nord } from "@milkdown/theme-nord";
import { Milkdown, useEditor } from "@milkdown/vue";
import axios from "axios";
import { defineComponent, onMounted, onBeforeUnmount, ref } from "vue";

export default defineComponent({
  name: "MilkdownEditor",
  components: {
    Milkdown,
  },
  setup: () => {
    const containerRef = ref(null);
    const isUploading = ref(false);
    const pendingUploads = ref(0);
    const tempIdentifier = ref(Date.now().toString());

    const { get } = useEditor((root) =>
      Editor.make()
        .config(nord)
        .config((ctx) => {
          ctx.set(rootCtx, root);
        })
        .use(commonmark),
    );

    const insertImageAtSelection = ({ src, alt, title }) => {
      const instance = get?.();
      if (!instance) return;
      instance.action((ctx) => {
        const view = ctx.get(editorViewCtx);
        const { state, dispatch } = view;
        const { from, to } = state.selection;
        const imageType = state.schema.nodes.image;
        if (imageType) {
          const imageNode = imageType.create({ src, alt, title });
          const tr = state.tr.replaceRangeWith(from, to, imageNode);
          dispatch(tr.scrollIntoView());
        } else {
          // Fallback: insert as markdown text if image node not available
          dispatch(state.tr.insertText(`![${alt}](${src} "${title}")`, from, to));
        }
        view.focus();
      });
    };

    const startUpload = () => {
      pendingUploads.value += 1;
      isUploading.value = true;
    };

    const endUpload = () => {
      pendingUploads.value = Math.max(0, pendingUploads.value - 1);
      if (pendingUploads.value === 0) {
        isUploading.value = false;
      }
    };

    const uploadImage = async (file) => {
      if (!file) return;
      try {
        startUpload();
        const formData = new FormData();
        formData.append("file", file, file.name);
        formData.append("temp_identifier", tempIdentifier.value);

        const response = await axios.post(route("attachments.upload"), formData, {
          headers: { "Content-Type": "multipart/form-data" },
        });

        const data = response?.data || {};
        const url = data.url; // rely on backend-provided URL for correctness
        const title = data.original_filename || file.name;
        if (url) {
          // Insert an image node so it renders immediately in the editor
          insertImageAtSelection({ src: url, alt: '1.00', title });
        }
      } catch (e) {
        console.error("Upload failed", e);
      } finally {
        endUpload();
      }
    };

    const handlePaste = (event) => {
      if (!event || !event.clipboardData) return;
      const items = event.clipboardData.items || [];
      const files = [];
      for (let index = 0; index < items.length; index++) {
        const item = items[index];
        if (item.kind === "file") {
          const file = item.getAsFile();
          if (file && file.type && file.type.startsWith("image/")) {
            files.push(file);
          }
        }
      }
      if (files.length > 0) {
        event.preventDefault();
        if (typeof event.stopImmediatePropagation === 'function') event.stopImmediatePropagation();
        event.stopPropagation();
        files.forEach(uploadImage);
      }
    };

    const handleDrop = (event) => {
      if (!event) return;
      event.preventDefault();
      if (typeof event.stopImmediatePropagation === 'function') event.stopImmediatePropagation();
      event.stopPropagation();
      const dataTransfer = event.dataTransfer;
      if (!dataTransfer) return;
      const files = Array.from(dataTransfer.files || []).filter((file) => file.type && file.type.startsWith("image/"));
      if (files.length > 0) {
        files.forEach(uploadImage);
      }
    };

    const preventDefault = (event) => {
      if (!event) return;
      event.preventDefault();
      if (typeof event.stopImmediatePropagation === 'function') event.stopImmediatePropagation();
      event.stopPropagation();
    };

    onMounted(() => {
      const element = containerRef.value;
      if (!element) return;
      // Use capture so we intercept before Milkdown/ProseMirror handlers to avoid duplicates
      element.addEventListener("paste", handlePaste, true);
      element.addEventListener("dragover", preventDefault, true);
      element.addEventListener("dragenter", preventDefault, true);
      element.addEventListener("drop", handleDrop, true);
    });

    onBeforeUnmount(() => {
      const element = containerRef.value;
      if (!element) return;
      element.removeEventListener("paste", handlePaste, true);
      element.removeEventListener("dragover", preventDefault, true);
      element.removeEventListener("dragenter", preventDefault, true);
      element.removeEventListener("drop", handleDrop, true);
    });

    return {
      containerRef,
      isUploading,
    };
  },
});
</script>

<style>
.milkdown-editor { position: relative; }
.milkdown-editor .ProseMirror {
  min-height: 300px;
  max-height: 700px;
  overflow-y: auto;
}
</style>

