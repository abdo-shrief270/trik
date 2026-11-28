<script setup lang="ts">
/** A native select (keyboard and screen readers work everywhere) styled like UiInput. */
defineProps<{ label: string; options: { value: string; label: string }[]; hint?: string }>()
const model = defineModel<string>({ required: true })
const id = useId()
</script>

<template>
  <div class="flex flex-col gap-1.5">
    <label :for="id" class="text-sm font-semibold">{{ label }}</label>
    <div class="relative">
      <select
        :id="id"
        v-model="model"
        class="h-11 w-full appearance-none rounded-lg border border-line bg-white ps-3.5 pe-10 text-[15px] outline-none focus:border-indigo focus:shadow-[0_0_0_4px_rgb(79_59_240/.14)]"
      >
        <option v-for="o in options" :key="o.value" :value="o.value">{{ o.label }}</option>
      </select>
      <UiIcon name="chevronDown" :size="18" class="pointer-events-none absolute end-3 top-1/2 -translate-y-1/2 text-muted" />
    </div>
    <p v-if="hint" class="text-[13px] text-muted">{{ hint }}</p>
  </div>
</template>
