type Resolved = string | Resolved[] | { [key: string]: Resolved }

/**
 * Reads list-shaped copy (arrays, arrays of objects) from the locale files as plain strings.
 * `list<{ title: string; body: string }>('problem.items')`.
 */
export function useCopy() {
  const { tm, rt } = useI18n()

  function resolve(v: unknown): Resolved {
    if (Array.isArray(v)) return v.map(resolve)
    // Compiled vue-i18n messages are AST nodes with a numeric `type`; anything else is our own object.
    if (v && typeof v === 'object' && typeof (v as { type?: unknown }).type !== 'number') {
      return Object.fromEntries(Object.entries(v).map(([k, val]) => [k, resolve(val)]))
    }
    return rt(v as Parameters<typeof rt>[0])
  }

  function list<T = string>(key: string): T[] {
    const raw = tm(key) as unknown
    return (Array.isArray(raw) ? raw.map(resolve) : []) as T[]
  }

  return { list }
}
