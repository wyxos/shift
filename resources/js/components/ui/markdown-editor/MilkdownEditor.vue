<template>
  <div ref="containerRef" class="milkdown-editor relative border-2 border-blue-500 rounded p-4">
    <Milkdown />
  </div>

  <!-- Image modal -->
  <div
    v-if="isImageModalOpen"
    class="fixed inset-0 z-50 bg-black/70 flex items-center justify-center"
    @click="closeImageModal"
  >
    <img
      :src="modalImageSrc"
      class="max-w-[90vw] max-h-[90vh] object-contain"
      @click.stop
      alt="full-size"
    />
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
    const tempIdentifier = ref(Date.now().toString());

    const isImageModalOpen = ref(false);
    const modalImageSrc = ref("");

    const { get } = useEditor((root) =>
      Editor.make()
        .config(nord)
        .config((ctx) => {
          ctx.set(rootCtx, root);
        })
        .use(commonmark),
    );

    // Helper: create a unique ID for each upload placeholder
    const createUploadId = () => `upload-${Math.random().toString(36).slice(2)}-${Date.now()}`;

    // Helper: build a 200x200 SVG progress tile as a data URL
    const progressTile = (percent = 0, label = "Uploading...") => {
      const p = Math.max(0, Math.min(100, Math.round(percent)));
      const barWidth = 160 * (p / 100);
      const svg = `<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns='http://www.w3.org/2000/svg' width='200' height='200'>
  <defs>
    <style>
      .bg{fill:#f3f4f6;}
      .border{stroke:#d1d5db;stroke-width:2;fill:none;}
      .bar-bg{fill:#e5e7eb;}
      .bar{fill:#3b82f6;}
      .txt{font-family:ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,Ubuntu,"Helvetica Neue",Arial;fill:#374151;font-size:14px;}
    </style>
  </defs>
  <rect x='1' y='1' width='198' height='198' class='bg' />
  <rect x='1' y='1' width='198' height='198' class='border' />
  <rect x='20' y='120' width='160' height='12' rx='6' class='bar-bg'/>
  <rect x='20' y='120' width='${barWidth}' height='12' rx='6' class='bar'/>
  <text x='100' y='95' text-anchor='middle' class='txt'>${label}</text>
  <text x='100' y='145' text-anchor='middle' class='txt'>${p}%</text>
</svg>`;
      return `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`;
    };

    // Insert a placeholder image with a unique uploadId in the title attr
    const insertUploadPlaceholder = (file, uploadId) => {
      const instance = get?.();
      if (!instance) return;
      instance.action((ctx) => {
        const view = ctx.get(editorViewCtx);
        const { state, dispatch } = view;
        const { from, to } = state.selection;
        const imageType = state.schema.nodes.image;
        if (!imageType) return;
        const node = imageType.create({ src: progressTile(0), alt: file?.name || "image", title: uploadId });
        const tr = state.tr.replaceRangeWith(from, to, node);
        dispatch(tr.scrollIntoView());
        view.focus();
      });
    };

    // Find the position of an image node by title attribute
    const findImagePosByTitle = (view, titleValue) => {
      const imageType = view.state.schema.nodes.image;
      let found = null;
      view.state.doc.descendants((node, pos) => {
        if (node.type === imageType && node.attrs && node.attrs.title === titleValue) {
          found = { pos, node };
          return false; // stop
        }
        return true;
      });
      return found;
    };

    const updateUploadProgress = (uploadId, percent) => {
      const instance = get?.();
      if (!instance) return;
      instance.action((ctx) => {
        const view = ctx.get(editorViewCtx);
        const found = findImagePosByTitle(view, uploadId);
        if (!found) return;
        const imageType = view.state.schema.nodes.image;
        const newAttrs = { ...found.node.attrs, src: progressTile(percent) };
        const tr = view.state.tr.setNodeMarkup(found.pos, imageType, newAttrs, found.node.marks);
        view.dispatch(tr);
      });
    };

    const finalizeUpload = (uploadId, finalUrl, finalTitle) => {
      const instance = get?.();
      if (!instance) return;
      instance.action((ctx) => {
        const view = ctx.get(editorViewCtx);
        const found = findImagePosByTitle(view, uploadId);
        if (!found) return;
        const imageType = view.state.schema.nodes.image;
        const newAttrs = { ...found.node.attrs, src: finalUrl, title: finalTitle || found.node.attrs.title };
        const tr = view.state.tr.setNodeMarkup(found.pos, imageType, newAttrs, found.node.marks);
        view.dispatch(tr);
      });
    };

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
          dispatch(state.tr.insertText(`![${alt}](${src} \"${title}\")`, from, to));
        }
        view.focus();
      });
    };

    const openImageModal = (src) => {
      modalImageSrc.value = src;
      isImageModalOpen.value = true;
    };
    const closeImageModal = () => {
      isImageModalOpen.value = false;
      modalImageSrc.value = "";
    };
    const onKeydown = (event) => {
      if (event.key === "Escape") closeImageModal();
    };
    const onClickInEditor = (event) => {
      const target = event.target;
      if (target instanceof HTMLImageElement) {
        // Avoid opening modal for our SVG progress placeholders
        if (typeof target.src === 'string' && target.src.startsWith('data:image/svg+xml')) return;
        event.preventDefault();
        if (typeof event.stopImmediatePropagation === 'function') event.stopImmediatePropagation();
        event.stopPropagation();
        openImageModal(target.src);
      }
    };


    const uploadImage = async (file, uploadId) => {
      if (!file) return;
      try {
        const formData = new FormData();
        formData.append("file", file, file.name);
        formData.append("temp_identifier", tempIdentifier.value);

        const response = await axios.post(route("attachments.upload"), formData, {
          headers: { "Content-Type": "multipart/form-data" },
          onUploadProgress: (e) => {
            try {
              const total = e?.total || 0;
              const loaded = e?.loaded || 0;
              const percent = total > 0 ? Math.round((loaded / total) * 100) : 0;
              updateUploadProgress(uploadId, percent);
            } catch (_) { /* noop */ }
          },
        });

        const data = response?.data || {};
        const url = data.url; // rely on backend-provided URL for correctness
        const title = data.original_filename || file.name;
        if (!url) throw new Error("No URL in response");
        // Finalize: replace placeholder with the actual image
        finalizeUpload(uploadId, url, title);
      } catch (error) {
        console.error("Upload failed", error);
        // Show error state in placeholder
        try {
          updateUploadProgress(uploadId, 0);
          const instance = get?.();
          if (instance) {
            instance.action((ctx) => {
              const view = ctx.get(editorViewCtx);
              const found = findImagePosByTitle(view, uploadId);
              if (!found) return;
              const imageType = view.state.schema.nodes.image;
              const errorSvg = (() => {
                const svg = `<?xml version='1.0' encoding='UTF-8'?>
<svg xmlns='http://www.w3.org/2000/svg' width='200' height='200'>
  <rect x='1' y='1' width='198' height='198' fill='#FEF2F2' stroke='#FCA5A5' stroke-width='2'/>
  <text x='100' y='100' text-anchor='middle' style='font-family: ui-sans-serif,system-ui,-apple-system,\"Segoe UI\",Roboto,Ubuntu,\"Helvetica Neue\",Arial; fill:#B91C1C; font-size:14px;'>Upload failed</text>
</svg>`;
                return `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`;
              })();
              const newAttrs = { ...found.node.attrs, src: errorSvg };
              const tr = view.state.tr.setNodeMarkup(found.pos, imageType, newAttrs, found.node.marks);
              view.dispatch(tr);
            });
          }
        } catch (_) { /* noop */ }
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
        files.forEach((file) => {
          const id = createUploadId();
          insertUploadPlaceholder(file, id);
          uploadImage(file, id);
        });
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
        files.forEach((file) => {
          const id = createUploadId();
          insertUploadPlaceholder(file, id);
          uploadImage(file, id);
        });
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
      element.addEventListener("click", onClickInEditor, true);
      window.addEventListener("keydown", onKeydown);
    });

    onBeforeUnmount(() => {
      const element = containerRef.value;
      if (!element) return;
      element.removeEventListener("paste", handlePaste, true);
      element.removeEventListener("dragover", preventDefault, true);
      element.removeEventListener("dragenter", preventDefault, true);
      element.removeEventListener("drop", handleDrop, true);
      element.removeEventListener("click", onClickInEditor, true);
      window.removeEventListener("keydown", onKeydown);
    });

    return {
      containerRef,
      isImageModalOpen,
      modalImageSrc,
      closeImageModal,
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
.milkdown-editor .ProseMirror img {
  max-width: 200px;
  max-height: 200px;
  width: auto;
  height: auto;
  object-fit: contain;
  border-radius: 0.25rem;
  cursor: zoom-in;
}
/* Remove focus ring/outline from the ProseMirror container */
.milkdown-editor .ProseMirror:focus,
.milkdown-editor .ProseMirror:focus-visible {
  outline: none !important;
  box-shadow: none !important;
}
/* Also ensure the wrapper doesn't show any outline on focus-within */
.milkdown-editor:focus,
.milkdown-editor:focus-within {
  outline: none !important;
  box-shadow: none !important;
}

/* Make images inline tiles with wrapping */
.milkdown-editor .ProseMirror img {
    display: inline-block;        /* override any theme block styles */
    vertical-align: top;
    max-width: 200px;
    max-height: 200px;
    width: auto;
    height: auto;
    object-fit: contain;
    border-radius: 0.25rem;
    cursor: zoom-in;
    margin: 0 0.5rem 0.5rem 0;    /* space between images */
}

/* If images are each wrapped in their own <p>, make those paragraphs inline too */
.milkdown-editor .ProseMirror p:has(> img:only-child) {
    display: inline-block;
    margin: 0 0.5rem 0.5rem 0;
}
</style>

