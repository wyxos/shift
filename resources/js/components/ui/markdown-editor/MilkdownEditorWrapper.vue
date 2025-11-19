<template>
  <MilkdownProvider>
    <MilkdownEditor />
  </MilkdownProvider>
</template>

<script>
import { MilkdownProvider } from "@milkdown/vue";
import { defineComponent } from "vue";
import MilkdownEditor from "./MilkdownEditor.vue";

export default defineComponent({
  name: "MilkdownEditorWrapper",
  components: {
    MilkdownProvider,
    MilkdownEditor,
  },
});
</script>

