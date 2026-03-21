<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Sheet, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import { Filter } from 'lucide-vue-next';
import { computed } from 'vue';

type PaginatedData = {
    current_page?: number | null;
    last_page?: number | null;
    from?: number | null;
    to?: number | null;
    total?: number | null;
    data?: unknown[];
};

const props = withDefaults(
    defineProps<{
        title: string;
        description?: string;
        filtersOpen?: boolean;
        activeFilterCount?: number;
        page: PaginatedData;
        itemsLabel: string;
        error?: string | null;
        filterTitle?: string;
        filterDescription?: string;
        showFilters?: boolean;
    }>(),
    {
        description: '',
        filtersOpen: false,
        activeFilterCount: 0,
        error: null,
        filterTitle: 'Filters',
        filterDescription: 'Refine this list.',
        showFilters: true,
    },
);

const emit = defineEmits<{
    'update:filtersOpen': [value: boolean];
    'page-change': [page: number];
}>();

const currentPage = computed(() => Math.max(1, Number(props.page.current_page ?? 1)));
const lastPage = computed(() => Math.max(1, Number(props.page.last_page ?? 1)));
const totalItems = computed(() => Number(props.page.total ?? props.page.data?.length ?? 0));
const from = computed(() => (totalItems.value === 0 ? 0 : Number(props.page.from ?? 0)));
const to = computed(() => (totalItems.value === 0 ? 0 : Number(props.page.to ?? 0)));

function changePage(page: number) {
    const nextPage = Math.max(1, Math.min(lastPage.value, page));
    if (nextPage === currentPage.value) return;
    emit('page-change', nextPage);
}
</script>

<template>
    <Card class="w-full">
        <CardHeader class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <CardTitle>{{ title }}</CardTitle>
                <p v-if="description" class="text-muted-foreground text-sm">{{ description }}</p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <Sheet v-if="showFilters" :open="filtersOpen" @update:open="emit('update:filtersOpen', $event)">
                    <SheetTrigger as-child>
                        <Button variant="outline" size="sm" data-testid="filters-trigger">
                            <Filter class="mr-2 h-4 w-4" />
                            Filters
                            <Badge v-if="activeFilterCount" variant="secondary" class="ml-2">
                                {{ activeFilterCount }}
                            </Badge>
                        </Button>
                    </SheetTrigger>
                    <SheetContent side="right" class="flex h-full flex-col p-0">
                        <SheetHeader class="p-0">
                            <div class="px-6 pt-6 pb-3">
                                <SheetTitle>{{ filterTitle }}</SheetTitle>
                                <SheetDescription class="text-muted-foreground mt-1 text-sm">
                                    {{ filterDescription }}
                                </SheetDescription>
                            </div>
                        </SheetHeader>

                        <div class="min-h-0 flex-1 space-y-6 overflow-y-auto px-6 pb-6">
                            <slot name="filters" />
                        </div>

                        <SheetFooter class="flex flex-row items-center justify-between border-t px-6 py-4">
                            <slot name="filter-actions" />
                        </SheetFooter>
                    </SheetContent>
                </Sheet>

                <slot name="actions" />
            </div>
        </CardHeader>

        <CardContent>
            <div class="text-muted-foreground mb-4 flex flex-wrap items-center justify-between gap-2 text-xs">
                <span>Showing {{ from }} to {{ to }} of {{ totalItems }} {{ itemsLabel }}</span>
                <span v-if="activeFilterCount">{{ activeFilterCount }} filter{{ activeFilterCount === 1 ? '' : 's' }} active</span>
            </div>

            <div v-if="error" class="text-destructive py-2 text-center text-sm">{{ error }}</div>

            <slot />

            <div class="mt-4 flex items-center justify-between gap-2 border-t pt-4">
                <div class="text-muted-foreground text-xs">Page {{ currentPage }} of {{ lastPage }}</div>

                <div class="flex items-center gap-2">
                    <Button size="sm" variant="outline" :disabled="currentPage <= 1" @click="changePage(currentPage - 1)">Previous</Button>
                    <Button size="sm" variant="outline" :disabled="currentPage >= lastPage" @click="changePage(currentPage + 1)">Next</Button>
                </div>
            </div>
        </CardContent>
    </Card>
</template>
