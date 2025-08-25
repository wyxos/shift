
<script>
import { Editor, rootCtx } from "@milkdown/kit/core";
import { editorViewCtx } from "@milkdown/core";
import { commonmark } from "@milkdown/kit/preset/commonmark";
import { nord } from "@milkdown/theme-nord";
import { Milkdown, useEditor } from "@milkdown/vue";
import axios from "axios";
import { defineComponent, onMounted, onBeforeUnmount, ref } from "vue";
import ImageProgressTile from './tiles/ImageProgressTile.vue';
import AttachmentProgressTile from './tiles/AttachmentProgressTile.vue';
import AttachmentFinalTile from './tiles/AttachmentFinalTile.vue';
import ImageErrorTile from './tiles/ImageErrorTile.vue';
import AttachmentErrorTile from './tiles/AttachmentErrorTile.vue';
import { renderTileToDataUrl } from './tiles/render';

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

    // Helper: build a 200x200 SVG progress tile for images (externalized)
    const progressTile = async (percent = 0, label = "Uploading...") => {
      return renderTileToDataUrl(ImageProgressTile, { percent, label });
    };

    // Helper: build a 200x100 SVG progress tile for generic files (attachments)
    const attachmentProgressTile = async (percent = 0, filename = "Uploading...") => {
      return renderTileToDataUrl(AttachmentProgressTile, { percent, filename });
    };

    // Helper: build the final 200x100 tile for attachments with icon, name and size
    const attachmentFinalTile = async (filename = "file", sizeLabel = "") => {
      return renderTileToDataUrl(AttachmentFinalTile, { filename, sizeLabel });
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
        const node = imageType.create({ src: '', alt: file?.name || "image", title: uploadId });
        const tr = state.tr.replaceRangeWith(from, to, node);
        dispatch(tr.scrollIntoView());
        // async update placeholder src with Vue component-rendered SVG
        progressTile(0, 'Uploading...').then((dataUrl) => {
          const posFound = findImagePosByTitle(view, uploadId);
          if (!posFound) return;
          const imgType = view.state.schema.nodes.image;
          const tr2 = view.state.tr.setNodeMarkup(posFound.pos, imgType, { ...posFound.node.attrs, src: dataUrl }, posFound.node.marks);
          view.dispatch(tr2);
        });
        view.focus();
      });
    };
    // Insert placeholder for attachments (non-images)
    const insertAttachmentPlaceholder = (file, uploadId) => {
      const instance = get?.();
      if (!instance) return;
      instance.action((ctx) => {
        const view = ctx.get(editorViewCtx);
        const { state, dispatch } = view;
        const { from, to } = state.selection;
        const imageType = state.schema.nodes.image;
        if (!imageType) return;
        const node = imageType.create({ src: '', alt: file?.name || 'file', title: `attachment:${uploadId}` });
        const tr = state.tr.replaceRangeWith(from, to, node);
        dispatch(tr.scrollIntoView());
        // async update placeholder src
        attachmentProgressTile(0, file?.name || 'file').then((dataUrl) => {
          const posFound = findImagePosByTitle(view, `attachment:${uploadId}`);
          if (!posFound) return;
          const imgType = view.state.schema.nodes.image;
          const tr2 = view.state.tr.setNodeMarkup(posFound.pos, imgType, { ...posFound.node.attrs, src: dataUrl }, posFound.node.marks);
          view.dispatch(tr2);
        });
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
        // async render of progress component
        progressTile(percent, 'Uploading...').then((dataUrl) => {
          const tr = view.state.tr.setNodeMarkup(found.pos, imageType, { ...found.node.attrs, src: dataUrl }, found.node.marks);
          view.dispatch(tr);
        });
      });
    };

    const updateAttachmentUploadProgress = (uploadId, percent, filename) => {
      const instance = get?.();
      if (!instance) return;
      instance.action((ctx) => {
        const view = ctx.get(editorViewCtx);
        const found = findImagePosByTitle(view, `attachment:${uploadId}`);
        if (!found) return;
        const imageType = view.state.schema.nodes.image;
        attachmentProgressTile(percent, filename).then((dataUrl) => {
          const tr = view.state.tr.setNodeMarkup(found.pos, imageType, { ...found.node.attrs, src: dataUrl }, found.node.marks);
          view.dispatch(tr);
        });
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

    const finalizeAttachment = (uploadId, url, filename, sizeLabel) => {
      const instance = get?.();
      if (!instance) return;
      instance.action((ctx) => {
        const view = ctx.get(editorViewCtx);
        const found = findImagePosByTitle(view, `attachment:${uploadId}`);
        if (!found) return;
        const imageType = view.state.schema.nodes.image;
        const tilePromise = attachmentFinalTile(filename, sizeLabel);
        const title = `attachment|${encodeURIComponent(filename || '')}|${encodeURIComponent(sizeLabel || '')}|${url}`;
        tilePromise.then((tile) => {
          const tr = view.state.tr.setNodeMarkup(found.pos, imageType, { ...found.node.attrs, src: tile, title }, found.node.marks);
          view.dispatch(tr);
        });
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
        const title = target.getAttribute('title') || '';
        // If this is an attachment tile, open the file in a new tab
        if (title.startsWith('attachment|')) {
          const parts = title.split('|');
          const url = parts[3] || '';
          if (url) window.open(url, '_blank');
          return;
        }
        // Avoid opening modal for our SVG progress placeholders for images
        if (typeof target.src === 'string' && target.src.startsWith('data:image/svg+xml')) return;
        event.preventDefault();
        if (typeof event.stopImmediatePropagation === 'function') event.stopImmediatePropagation();
        event.stopPropagation();
        openImageModal(target.src);
      }
    };

    const formatBytes = (bytes = 0) => {
      if (!bytes || isNaN(bytes)) return '0 B';
      const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
      const i = Math.floor(Math.log(bytes) / Math.log(1024));
      const val = bytes / Math.pow(1024, i);
      return `${val.toFixed(val >= 10 || i === 0 ? 0 : 1)} ${sizes[i]}`;
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
              renderTileToDataUrl(ImageErrorTile, { message: 'Upload failed' }).then((dataUrl) => {
                const newAttrs = { ...found.node.attrs, src: dataUrl };
                const tr = view.state.tr.setNodeMarkup(found.pos, imageType, newAttrs, found.node.marks);
                view.dispatch(tr);
              });
            });
          }
        } catch (_) { /* noop */ }
      }
    };

    const uploadAttachment = async (file, uploadId) => {
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
              updateAttachmentUploadProgress(uploadId, percent, file.name);
            } catch (_) { /* noop */ }
          },
        });

        const data = response?.data || {};
        const url = data.url;
        const filename = data.original_filename || file.name;
        const sizeLabel = data.size ? formatBytes(parseInt(data.size, 10)) : formatBytes(file.size);
        if (!url) throw new Error('No URL in response');
        finalizeAttachment(uploadId, url, filename, sizeLabel);
      } catch (error) {
        console.error('Attachment upload failed', error);
        try {
          const instance = get?.();
          if (instance) {
            instance.action((ctx) => {
              const view = ctx.get(editorViewCtx);
              const found = findImagePosByTitle(view, `attachment:${uploadId}`);
              if (!found) return;
              const imageType = view.state.schema.nodes.image;
              renderTileToDataUrl(AttachmentErrorTile, { message: 'Upload failed' }).then((dataUrl) => {
                const tr = view.state.tr.setNodeMarkup(found.pos, imageType, { ...found.node.attrs, src: dataUrl }, found.node.marks);
                view.dispatch(tr);
              });
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
          if (file) files.push(file);
        }
      }
      if (files.length > 0) {
        event.preventDefault();
        if (typeof event.stopImmediatePropagation === 'function') event.stopImmediatePropagation();
        event.stopPropagation();
        files.forEach((file) => {
          const id = createUploadId();
          if (file.type && file.type.startsWith('image/')) {
            insertUploadPlaceholder(file, id);
            uploadImage(file, id);
          } else {
            insertAttachmentPlaceholder(file, id);
            uploadAttachment(file, id);
          }
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
      const files = Array.from(dataTransfer.files || []);
      if (files.length > 0) {
        files.forEach((file) => {
          const id = createUploadId();
          if (file.type && file.type.startsWith('image/')) {
            insertUploadPlaceholder(file, id);
            uploadImage(file, id);
          } else {
            insertAttachmentPlaceholder(file, id);
            uploadAttachment(file, id);
          }
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

<template>
    <div ref="containerRef" class="milkdown-editor relative border-2 border-blue-200 rounded p-4">
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
