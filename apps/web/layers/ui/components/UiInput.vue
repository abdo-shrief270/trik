<script setup lang="ts">
/**
 * A labelled text field with hint and error, wired for screen readers (label, aria-describedby, aria-invalid).
 * `type="password"` gets a show/hide toggle.
 */
const props = withDefaults(
  defineProps<{
    label: string
    type?: 'text' | 'email' | 'password' | 'tel' | 'search' | 'url'
    hint?: string
    error?: string
    autocomplete?: string
    dir?: 'ltr' | 'rtl' | 'auto'
    required?: boolean
    disabled?: boolean
  }>(),
  { type: 'text', hint: undefined, error: undefined, autocomplete: undefined, dir: undefined, required: false, disabled: false },
)
const model = defineModel<string>({ default: '' })

const { t } = useI18n()
const id = useId()
const reveal = ref(false)
const inputType = computed(() => (props.type === 'password' && reveal.value ? 'text' : props.type))
const describedBy = computed(() => [props.error ? `${id}-error` : '', props.hint ? `${id}-hint` : ''].filter(Boolean).join(' ') || undefined)

const input = ref<HTMLInputElement | null>(null)
defineExpose({ focus: () => input.value?.focus() })
</script>

<template>
  <div class="flex flex-col gap-1.5">
    <label :for="id" class="text-sm font-semibold">{{ label }}</label>
    <div class="relative">
      <input
        :id="id"
        ref="input"
        v-model="model"
        :type="inputType"
        :autocomplete="autocomplete"
        :dir="dir"
        :required="required"
        :disabled="disabled"
        :aria-invalid="!!error || undefined"
        :aria-describedby="describedBy"
        class="h-11 w-full rounded-lg border bg-white px-3.5 text-[15px] transition-[border-color,box-shadow] outline-none placeholder:text-muted focus:border-indigo focus:shadow-[0_0_0_4px_rgb(79_59_240/.14)] disabled:bg-paper"
        :class="[error ? 'border-danger' : 'border-line', type === 'password' ? 'pe-11' : '', dir === 'ltr' ? 'rtl:text-right' : '']"
      >
      <button
        v-if="type === 'password'"
        type="button"
        class="absolute inset-y-0 end-0 grid w-11 place-items-center text-muted hover:text-ink"
        :aria-label="reveal ? t('ui.hidePassword') : t('ui.showPassword')"
        :aria-pressed="reveal"
        @click="reveal = !reveal"
      >
        <UiIcon :name="reveal ? 'eyeOff' : 'eye'" :size="18" />
      </button>
    </div>
    <p v-if="hint && !error" :id="`${id}-hint`" class="text-[13px] text-muted">{{ hint }}</p>
    <p v-if="error" :id="`${id}-error`" class="flex items-center gap-1.5 text-[13px] text-danger">
      <UiIcon name="alert" :size="14" />{{ error }}
    </p>
  </div>
</template>
