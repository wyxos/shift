<script setup lang="ts">
import { ButtonGroup } from '@/components/ui/button-group';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Search } from 'lucide-vue-next';
import { computed } from 'vue';
import { sortOptions, type SortBy } from './project-shared';

const props = defineProps<{
    searchTerm: string;
    sortBy: SortBy;
}>();

const emit = defineEmits<{
    'update:searchTerm': [value: string];
    'update:sortBy': [value: SortBy];
}>();

const searchValue = computed({
    get: () => props.searchTerm,
    set: (value: string) => emit('update:searchTerm', value),
});

const sortValue = computed({
    get: () => props.sortBy,
    set: (value: SortBy) => emit('update:sortBy', value),
});
</script>

<template>
    <div class="space-y-2">
        <Label for="projects-search">Search</Label>
        <div class="relative">
            <Search class="text-muted-foreground pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2" />
            <Input id="projects-search" v-model="searchValue" data-testid="filter-search" placeholder="Search by project name" class="pl-9" />
        </div>
    </div>

    <div class="space-y-2">
        <Label class="text-sm leading-none font-medium">Sort By</Label>
        <ButtonGroup v-model="sortValue" :columns="3" :options="sortOptions" test-id-prefix="sort-by" />
    </div>
</template>
