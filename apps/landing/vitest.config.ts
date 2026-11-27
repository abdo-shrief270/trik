import { defineConfig } from 'vitest/config'

// The logic under test (app/utils) is plain TypeScript: no Nuxt environment needed.
export default defineConfig({
  test: { environment: 'node', include: ['tests/unit/**/*.spec.ts'] },
})
