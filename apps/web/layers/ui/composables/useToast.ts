export interface Toast {
  id: number
  tone: 'success' | 'error' | 'info'
  message: string
}

const toasts = ref<Toast[]>([])
let next = 1

/** Short messages in the corner (UiToaster renders them, and screen readers hear them). */
export function useToast() {
  function show(message: string, tone: Toast['tone'] = 'info', ms = 5000) {
    const id = next++
    toasts.value = [...toasts.value.slice(-3), { id, tone, message }]
    if (ms > 0) setTimeout(() => dismiss(id), ms)
  }
  function dismiss(id: number) {
    toasts.value = toasts.value.filter((t) => t.id !== id)
  }
  return {
    toasts: readonly(toasts),
    success: (m: string) => show(m, 'success'),
    error: (m: string) => show(m, 'error', 8000),
    info: (m: string) => show(m, 'info'),
    dismiss,
  }
}
