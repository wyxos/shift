<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuRadioGroup, DropdownMenuRadioItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { getTaskStateIcon } from '@shared/tasks/presentation';
import { ChevronDown } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    modelValue: string;
    options: { value: string; label: string }[];
    label: string;
    testIdPrefix: string;
    disabled?: boolean;
}>();
const emit = defineEmits<{ 'update:modelValue': [value: string] }>();
const selected = computed(() => props.options.find((option) => option.value === props.modelValue));
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button
                :aria-label="label"
                :disabled="disabled"
                :data-testid="`${testIdPrefix}-trigger`"
                variant="outline"
                class="w-full justify-between"
            >
                <span class="flex min-w-0 items-center gap-2">
                    <component :is="getTaskStateIcon(modelValue)" data-icon="inline-start" />
                    <span class="truncate">{{ selected?.label ?? modelValue }}</span>
                </span>
                <ChevronDown data-icon="inline-end" />
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="start" class="min-w-56">
            <DropdownMenuRadioGroup :model-value="modelValue" @update:model-value="emit('update:modelValue', String($event))">
                <DropdownMenuRadioItem
                    v-for="option in options"
                    :key="option.value"
                    :value="option.value"
                    :disabled="disabled"
                    :data-testid="`${testIdPrefix}-${option.value}`"
                >
                    <component :is="getTaskStateIcon(option.value)" class="size-4" />
                    {{ option.label }}
                </DropdownMenuRadioItem>
            </DropdownMenuRadioGroup>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
