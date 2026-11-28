/** True when the visitor asked the OS for less motion. False on the server. */
export function useReducedMotion() {
  const pref = usePreferredReducedMotion()
  return computed(() => pref.value === 'reduce')
}

/** Pointer position inside an element as --mx / --my, for the .spotlight hover glow. */
export function spotlight(e: PointerEvent) {
  const el = e.currentTarget as HTMLElement
  const r = el.getBoundingClientRect()
  el.style.setProperty('--mx', `${e.clientX - r.left}px`)
  el.style.setProperty('--my', `${e.clientY - r.top}px`)
}

/** A button that leans a little toward the pointer. Returns handlers to bind on the element. */
export function useMagnetic(strength = 0.25) {
  const reduced = useReducedMotion()
  const style = ref<Record<string, string>>({})
  function move(e: PointerEvent) {
    if (reduced.value || e.pointerType !== 'mouse') return
    const el = e.currentTarget as HTMLElement
    const r = el.getBoundingClientRect()
    const x = (e.clientX - (r.left + r.width / 2)) * strength
    const y = (e.clientY - (r.top + r.height / 2)) * strength
    style.value = { transform: `translate3d(${x}px, ${y}px, 0)` }
  }
  function leave() {
    style.value = { transform: 'translate3d(0, 0, 0)' }
  }
  return { style, move, leave }
}
