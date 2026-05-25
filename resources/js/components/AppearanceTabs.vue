<script setup lang="ts">
import { useAppearance } from '@/composables/useAppearance';
import { ChevronRight, Monitor, Moon, Sun } from 'lucide-vue-next';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        compact?: boolean;
    }>(),
    {
        compact: false,
    },
);

const { appearance, updateAppearance } = useAppearance();

const options = [
    { value: 'light', Icon: Sun, label: 'Light' },
    { value: 'dark', Icon: Moon, label: 'Dark' },
    { value: 'system', Icon: Monitor, label: 'System' },
] as const;

const currentOption = computed(() => options.find((option) => option.value === appearance.value) ?? options[2]);
const nextOption = computed(() => {
    const currentIndex = options.findIndex((option) => option.value === currentOption.value.value);

    return options[(currentIndex + 1) % options.length];
});
const buttonLabel = computed(() => `Theme: ${currentOption.value.label}. Switch to ${nextOption.value.label}.`);

function cycleAppearance() {
    updateAppearance(nextOption.value.value);
}
</script>

<template>
    <button
        type="button"
        data-appearance-toggle
        :data-appearance="appearance"
        :data-next-appearance="nextOption.value"
        :title="buttonLabel"
        :aria-label="buttonLabel"
        @click="cycleAppearance"
        :class="[
            props.compact
                ? 'border-border/70 bg-background/85 text-foreground hover:bg-accent/80 hover:text-foreground inline-flex h-8 items-center gap-2 rounded-md border px-2.5 shadow-xs transition-all'
                : 'border-border/70 bg-background text-foreground hover:bg-accent/80 hover:text-foreground inline-flex h-11 items-center gap-3 rounded-lg border px-3.5 text-left shadow-xs transition-all',
        ]"
    >
        <component :is="currentOption.Icon" class="h-4 w-4 shrink-0" />

        <template v-if="!props.compact">
            <span class="min-w-0">
                <span class="block text-sm leading-none font-medium">{{ currentOption.label }}</span>
                <span class="text-muted-foreground mt-1 block text-xs leading-none">Next: {{ nextOption.label }}</span>
            </span>
            <ChevronRight class="text-muted-foreground ml-1 h-3.5 w-3.5 shrink-0" />
        </template>

        <span v-else class="sr-only">{{ buttonLabel }}</span>
    </button>
</template>
