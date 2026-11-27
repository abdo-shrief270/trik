<script lang="ts">
/**
 * The side filter column of the helpdesk layout (inbox, orders, confirmations): collapsible groups of
 * filters with counts. Pages pass their own groups; the selected item gets aria-current.
 */
export interface FilterItem { id: string; label: string; count?: number; tone?: 'danger' | 'indigo' }
export interface FilterGroup { id: string; label: string; items: FilterItem[] }
</script>

<script setup lang="ts">
defineProps<{ title: string; groups: FilterGroup[] }>()
const selected = defineModel<string>({ default: '' })
const { t } = useI18n()
const collapsed = ref<Record<string, boolean>>({})
</script>

<template>
  <aside class="flex w-64 shrink-0 flex-col border-e border-line bg-white" :aria-label="title">
    <header class="flex h-16 items-center justify-between px-5">
      <h2 class="text-lg font-bold">{{ title }}</h2>
      <slot name="action" />
    </header>
    <div class="flex-1 overflow-y-auto px-3 pb-6">
      <section v-for="g in groups" :key="g.id" class="mt-2">
        <button
          type="button"
          class="flex w-full items-center gap-1.5 rounded-md px-2 py-1.5 text-sm font-semibold text-muted hover:text-ink"
          :aria-expanded="!collapsed[g.id]"
          @click="collapsed[g.id] = !collapsed[g.id]"
        >
          <UiIcon name="chevronDown" :size="16" class="transition-transform" :class="{ '-rotate-90 rtl:rotate-90': collapsed[g.id] }" />
          {{ g.label }}
        </button>
        <ul v-show="!collapsed[g.id]" class="mt-1 space-y-0.5">
          <li v-for="item in g.items" :key="item.id">
            <button
              type="button"
              class="flex w-full items-center justify-between gap-2 rounded-lg px-3 py-2 text-start text-[15px] transition-colors"
              :class="selected === item.id ? 'bg-indigo-soft font-semibold text-indigo-strong' : 'hover:bg-paper'"
              :aria-current="selected === item.id ? 'true' : undefined"
              @click="selected = item.id"
            >
              <span class="truncate">{{ item.label }}</span>
              <span v-if="item.count !== undefined" class="text-sm tabular-nums" :class="item.tone === 'danger' ? 'font-semibold text-danger' : 'text-muted'">
                <span class="sr-only">{{ t('ui.count') }}: </span>{{ item.count }}
              </span>
            </button>
          </li>
        </ul>
      </section>
      <slot />
    </div>
  </aside>
</template>
