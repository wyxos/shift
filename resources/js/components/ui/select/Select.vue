<script setup lang="ts">
import { cn } from '@/lib/utils';
import { Check, ChevronsUpDown, Search } from 'lucide-vue-next';
import { PopoverContent, PopoverPortal, PopoverRoot, PopoverTrigger } from 'reka-ui';
import { computed, nextTick, ref, watch, type HTMLAttributes } from 'vue';
import type { SelectOption, SelectOptionValue } from './types';

const props = withDefaults(
    defineProps<{
        modelValue?: SelectOptionValue;
        options: SelectOption[];
        placeholder?: string;
        searchPlaceholder?: string;
        emptyLabel?: string;
        disabled?: boolean;
        searchable?: boolean;
        align?: 'start' | 'center' | 'end';
        class?: HTMLAttributes['class'];
        triggerClass?: HTMLAttributes['class'];
        contentClass?: HTMLAttributes['class'];
        testId?: string;
        ariaLabel?: string;
    }>(),
    {
        placeholder: 'Select an option',
        searchPlaceholder: 'Search options...',
        emptyLabel: 'No options found.',
        disabled: false,
        searchable: false,
        align: 'start',
        class: undefined,
        triggerClass: undefined,
        contentClass: undefined,
        testId: undefined,
        ariaLabel: undefined,
        modelValue: undefined,
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: SelectOptionValue];
}>();

const open = ref(false);
const searchTerm = ref('');
const searchInput = ref<HTMLInputElement | null>(null);
const contentId = `select-${Math.random().toString(36).slice(2)}`;

const selectedOption = computed(() => props.options.find((option) => valuesMatch(option.value, props.modelValue)) ?? null);

const filteredOptions = computed(() => {
    const query = searchTerm.value.trim().toLowerCase();

    if (!props.searchable || !query) {
        return props.options;
    }

    return props.options.filter((option) => {
        const haystack = [option.label, option.description, ...(option.keywords ?? [])].filter(Boolean).join(' ').toLowerCase();

        return haystack.includes(query);
    });
});

watch(open, (isOpen) => {
    if (!isOpen) {
        searchTerm.value = '';
        return;
    }

    if (!props.searchable) return;

    void nextTick(() => searchInput.value?.focus());
});

function valuesMatch(left: SelectOptionValue | undefined, right: SelectOptionValue | undefined): boolean {
    if (left === null || left === undefined || left === '') {
        return right === null || right === undefined || right === '';
    }

    return left === right;
}

function selectOption(option: SelectOption) {
    if (option.disabled) return;

    emit('update:modelValue', option.value);
    open.value = false;
}
</script>

<template>
    <PopoverRoot v-model:open="open">
        <PopoverTrigger as-child>
            <button
                type="button"
                role="combobox"
                :aria-controls="contentId"
                :aria-expanded="open"
                :aria-label="ariaLabel ?? placeholder"
                :disabled="disabled"
                :data-testid="testId"
                data-shift-field-control
                :class="
                    cn(
                        'border-input bg-background placeholder:text-muted-foreground focus:border-ring focus-visible:border-ring flex h-10 w-full items-center justify-between gap-2 rounded-md border px-3 py-2 text-left text-sm transition-colors focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50',
                        props.class,
                        triggerClass,
                    )
                "
            >
                <span :class="cn('min-w-0 truncate', !selectedOption && 'text-muted-foreground')">
                    {{ selectedOption?.label ?? placeholder }}
                </span>
                <ChevronsUpDown class="text-muted-foreground h-4 w-4 shrink-0 opacity-70" />
            </button>
        </PopoverTrigger>

        <PopoverPortal>
            <PopoverContent
                :id="contentId"
                :align="align"
                :side-offset="4"
                :class="
                    cn(
                        'bg-popover text-popover-foreground data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 z-50 w-(--reka-popover-trigger-width) min-w-[12rem] overflow-hidden rounded-md border p-0 shadow-md outline-none',
                        contentClass,
                    )
                "
            >
                <div v-if="searchable" class="border-b p-2">
                    <div class="relative">
                        <Search class="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 h-4 w-4 -translate-y-1/2" />
                        <input
                            ref="searchInput"
                            v-model="searchTerm"
                            :placeholder="searchPlaceholder"
                            data-shift-field-control
                            class="border-input bg-background placeholder:text-muted-foreground focus:border-ring focus-visible:border-ring flex h-8 w-full rounded-md border px-3 py-1 pl-8 text-sm transition-colors outline-none"
                            :data-testid="testId ? `${testId}-search` : undefined"
                            @keydown.enter.prevent
                        />
                    </div>
                </div>

                <div role="listbox" :aria-label="ariaLabel ?? placeholder" class="max-h-64 overflow-y-auto p-1">
                    <button
                        v-for="option in filteredOptions"
                        :key="`${option.value ?? 'null'}-${option.label}`"
                        type="button"
                        role="option"
                        :aria-selected="valuesMatch(option.value, modelValue)"
                        :disabled="option.disabled"
                        :data-testid="testId ? `${testId}-option-${option.value ?? 'none'}` : undefined"
                        :class="
                            cn(
                                'focus:bg-accent focus:text-accent-foreground hover:bg-accent hover:text-accent-foreground relative flex w-full cursor-pointer items-start gap-2 rounded-sm px-2 py-1.5 text-left text-sm outline-none transition-colors disabled:pointer-events-none disabled:opacity-50',
                                valuesMatch(option.value, modelValue) && 'bg-accent text-accent-foreground',
                            )
                        "
                        @click="selectOption(option)"
                    >
                        <Check
                            :class="cn('mt-0.5 h-4 w-4 shrink-0', valuesMatch(option.value, modelValue) ? 'opacity-100' : 'opacity-0')"
                        />
                        <span class="min-w-0">
                            <span class="block truncate">{{ option.label }}</span>
                            <span v-if="option.description" class="text-muted-foreground block truncate text-xs">{{ option.description }}</span>
                        </span>
                    </button>

                    <div v-if="filteredOptions.length === 0" class="text-muted-foreground px-2 py-6 text-center text-sm">
                        {{ emptyLabel }}
                    </div>
                </div>
            </PopoverContent>
        </PopoverPortal>
    </PopoverRoot>
</template>
