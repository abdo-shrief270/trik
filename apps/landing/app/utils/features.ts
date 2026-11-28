/** Feature pages, in the order they appear in menus and grids. Copy lives in i18n under features.items.<slug>. */
export const FEATURE_SLUGS = ['confirmation', 'risk', 'inbox', 'stores'] as const
export type FeatureSlug = (typeof FEATURE_SLUGS)[number]

export const isFeatureSlug = (s: string): s is FeatureSlug => (FEATURE_SLUGS as readonly string[]).includes(s)

export const formatNumber = (n: number) => Math.round(n).toLocaleString('en-US')
