<script setup lang="ts">
import type { SidebarProps } from '.'
import { cn } from '@/lib/utils'
import { Sheet, SheetContent } from '@/components/ui/sheet'
import SheetDescription from '@/components/ui/sheet/SheetDescription.vue'
import SheetHeader from '@/components/ui/sheet/SheetHeader.vue'
import SheetTitle from '@/components/ui/sheet/SheetTitle.vue'
import { SIDEBAR_WIDTH, SIDEBAR_WIDTH_ICON, SIDEBAR_WIDTH_MOBILE, useSidebar } from './utils'
import { computed } from 'vue'

defineOptions({
  inheritAttrs: false,
})

const props = withDefaults(defineProps<SidebarProps>(), {
  side: 'left',
  variant: 'sidebar',
  collapsible: 'offcanvas',
})

const { isMobile, state, openMobile, setOpenMobile } = useSidebar()

// Computed styles to ensure width values work across different build contexts
const gapStyle = computed(() => ({
  width: `var(--sidebar-width, ${SIDEBAR_WIDTH})`,
}))

const gapCollapsedStyle = computed(() => ({
  width: props.variant === 'floating' || props.variant === 'inset'
    ? `calc(var(--sidebar-width-icon, ${SIDEBAR_WIDTH_ICON}) + 1rem)`
    : `var(--sidebar-width-icon, ${SIDEBAR_WIDTH_ICON})`,
}))

const sidebarStyle = computed(() => ({
  width: `var(--sidebar-width, ${SIDEBAR_WIDTH})`,
}))

const sidebarCollapsedStyle = computed(() => ({
  width: props.variant === 'floating' || props.variant === 'inset'
    ? `calc(var(--sidebar-width-icon, ${SIDEBAR_WIDTH_ICON}) + 1rem + 2px)`
    : `var(--sidebar-width-icon, ${SIDEBAR_WIDTH_ICON})`,
}))
</script>

<template>
  <div
    v-if="collapsible === 'none'"
    data-slot="sidebar"
    :class="cn('bg-sidebar text-sidebar-foreground flex h-full flex-col', props.class)"
    :style="{ width: `var(--sidebar-width, ${SIDEBAR_WIDTH})` }"
    v-bind="$attrs"
  >
    <slot />
  </div>

  <Sheet v-else-if="isMobile" :open="openMobile" v-bind="$attrs" @update:open="setOpenMobile">
    <SheetContent
      data-sidebar="sidebar"
      data-slot="sidebar"
      data-mobile="true"
      :side="side"
      class="bg-sidebar text-sidebar-foreground p-0 [&>button]:hidden"
      :style="{
        '--sidebar-width': SIDEBAR_WIDTH_MOBILE,
        width: SIDEBAR_WIDTH_MOBILE,
      }"
    >
      <SheetHeader class="sr-only">
        <SheetTitle>Sidebar</SheetTitle>
        <SheetDescription>Displays the mobile sidebar.</SheetDescription>
      </SheetHeader>
      <div class="flex h-full w-full flex-col">
        <slot />
      </div>
    </SheetContent>
  </Sheet>

  <div
    v-else
    class="group peer text-sidebar-foreground hidden md:block"
    data-slot="sidebar"
    :data-state="state"
    :data-collapsible="state === 'collapsed' ? collapsible : ''"
    :data-variant="variant"
    :data-side="side"
  >
    <!-- This is what handles the sidebar gap on desktop  -->
    <div
      :class="cn(
        'relative bg-transparent transition-[width] duration-200 ease-linear',
        'group-data-[collapsible=offcanvas]:!w-0',
        'group-data-[side=right]:rotate-180',
      )"
      :style="state === 'collapsed' ? gapCollapsedStyle : gapStyle"
    />
    <div
      :class="cn(
        'fixed inset-y-0 z-10 hidden h-svh transition-[left,right,width] duration-200 ease-linear md:flex',
        side === 'left'
          ? 'left-0 group-data-[collapsible=offcanvas]:left-[calc(var(--sidebar-width)*-1)]'
          : 'right-0 group-data-[collapsible=offcanvas]:right-[calc(var(--sidebar-width)*-1)]',
        // Adjust the padding for floating and inset variants.
        variant === 'floating' || variant === 'inset'
          ? 'p-2'
          : 'group-data-[side=left]:border-r group-data-[side=right]:border-l',
        props.class,
      )"
      :style="state === 'collapsed' ? sidebarCollapsedStyle : sidebarStyle"
      v-bind="$attrs"
    >
      <div
        data-sidebar="sidebar"
        class="bg-sidebar group-data-[variant=floating]:border-sidebar-border flex h-full w-full flex-col group-data-[variant=floating]:rounded-lg group-data-[variant=floating]:border group-data-[variant=floating]:shadow-sm"
      >
        <slot />
      </div>
    </div>
  </div>
</template>
