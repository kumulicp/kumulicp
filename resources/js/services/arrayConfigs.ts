// Array configurations follow the naming convention `<prefix>-<index>-<field>`,
// e.g. persistence-0-storageClass, persistence-0-capacity, persistence-1-storageClass.
// The index is only used to group fields together and is never shown in the UI.

export interface PlanConfig {
  name: string
  type: string
  persistent?: boolean
  additional?: boolean
  default?: unknown
  options?: unknown
  [key: string]: unknown
}

export type PlanConfigs = Record<string, PlanConfig>

export type ConfigRow =
  | { kind: 'config', key: string, config: PlanConfig, label: string, grouped: boolean, removable: boolean }
  | { kind: 'groupStart', key: string, prefix: string, title: string }
  | { kind: 'itemStart', key: string, prefix: string, index: number, title: string, removable: boolean }
  | { kind: 'groupEnd', key: string, prefix: string, title: string }

// Types an additional config can be saved as. Matches the validation types in
// the plan Configurations controller.
const CLONEABLE_TYPES = ['string', 'int', 'bool', 'password', 'textarea', 'enum']

const ARRAY_CONFIG_PATTERN = /^(.+?)-(\d+)-(.+)$/

export function parseArrayConfigName (name: string) {
  const match = ARRAY_CONFIG_PATTERN.exec(name)

  return match ? { prefix: match[1], index: Number(match[2]), field: match[3] } : null
}

export function arrayConfigName (prefix: string, index: number, field: string) {
  return `${prefix}-${index}-${field}`
}

// "extra-volumes" and "extraVolumes" both become "Extra Volumes"
export function headline (value: string) {
  return value
    .replace(/([a-z0-9])([A-Z])/g, '$1 $2')
    .split(/[-_\s]+/)
    .filter(Boolean)
    .map(word => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ')
}

// The lowest-index group of a prefix is what new items are copied from. Only
// the profile's own configs are used so manually added extras aren't copied,
// unless the profile has nothing for the prefix.
export function arrayTemplate (configs: PlanConfigs, profileConfigs: PlanConfigs, prefix: string): PlanConfig[] {
  for (const source of [profileConfigs, configs]) {
    const candidates = Object.values(source)
      .map(config => ({ config, parsed: parseArrayConfigName(config.name) }))
      .filter(candidate => candidate.parsed && candidate.parsed.prefix === prefix)

    if (candidates.length === 0) continue

    const lowest = Math.min(...candidates.map(candidate => candidate.parsed!.index))

    return candidates.filter(candidate => candidate.parsed!.index === lowest).map(candidate => candidate.config)
  }

  return []
}

export function nextArrayIndex (configs: PlanConfigs, prefix: string) {
  const indexes = Object.keys(configs)
    .map(parseArrayConfigName)
    .filter(parsed => parsed && parsed.prefix === prefix)
    .map(parsed => parsed!.index)

  return indexes.length ? Math.max(...indexes) + 1 : 0
}

export function emptyValue (config: PlanConfig) {
  if (config.default !== undefined && config.default !== null) return config.default

  return config.type === 'bool' ? false : null
}

// The configs for a new array item, copied from the template at the lowest index.
export function newArrayItem (configs: PlanConfigs, profileConfigs: PlanConfigs, prefix: string) {
  const index = nextArrayIndex(configs, prefix)
  const items: { config: PlanConfig, value: unknown }[] = []

  for (const template of arrayTemplate(configs, profileConfigs, prefix)) {
    if (! CLONEABLE_TYPES.includes(template.type)) continue

    const parsed = parseArrayConfigName(template.name)!
    const config: PlanConfig = {
      name: arrayConfigName(prefix, index, parsed.field),
      type: template.type,
      persistent: !! template.persistent,
      additional: true
    }
    if (template.default !== undefined) config.default = template.default
    if (template.options !== undefined) config.options = template.options

    items.push({ config, value: emptyValue(template) })
  }

  return items
}

// Maps each config name affected by removing an array item to its new name,
// or null when it is removed. Higher indexes shift down by one so the numbering
// stays continuous. Unaffected names aren't included.
export function removalMap (names: string[], prefix: string, removedIndex: number) {
  const map: Record<string, string | null> = {}

  for (const name of names) {
    const parsed = parseArrayConfigName(name)
    if (! parsed || parsed.prefix !== prefix) continue

    if (parsed.index === removedIndex) {
      map[name] = null
    } else if (parsed.index > removedIndex) {
      map[name] = arrayConfigName(prefix, parsed.index - 1, parsed.field)
    }
  }

  return map
}

// Flattens the configs into the rows rendered by the page: plain configs stay
// where they are, and array configs are collected under their prefix at the
// position of the first one, grouped by index.
export function buildRows (configs: PlanConfigs, profileConfigs: PlanConfigs = {}): ConfigRow[] {
  const order: ({ type: 'config', name: string } | { type: 'group', prefix: string })[] = []
  const groups: Record<string, { fields: string[], items: Record<number, Record<string, PlanConfig>> }> = {}

  for (const [name, config] of Object.entries(configs)) {
    const parsed = parseArrayConfigName(name)

    if (! parsed) {
      order.push({ type: 'config', name })
      continue
    }

    if (! groups[parsed.prefix]) {
      groups[parsed.prefix] = { fields: [], items: {} }
      order.push({ type: 'group', prefix: parsed.prefix })
    }

    const group = groups[parsed.prefix]
    if (! group.fields.includes(parsed.field)) group.fields.push(parsed.field)
    group.items[parsed.index] = group.items[parsed.index] || {}
    group.items[parsed.index][parsed.field] = config
  }

  const rows: ConfigRow[] = []

  for (const entry of order) {
    if (entry.type === 'config') {
      rows.push({
        kind: 'config',
        key: 'config-' + entry.name,
        config: configs[entry.name],
        label: entry.name,
        grouped: false,
        removable: !! configs[entry.name].additional
      })
      continue
    }

    const { prefix } = entry
    const group = groups[prefix]
    const title = headline(prefix)
    const indexes = Object.keys(group.items).map(Number).sort((a, b) => a - b)
    const templateFields = arrayTemplate({}, profileConfigs, prefix)
      .map(config => parseArrayConfigName(config.name)!.field)

    rows.push({ kind: 'groupStart', key: 'group-' + prefix, prefix, title })

    for (const index of indexes) {
      rows.push({
        kind: 'itemStart',
        key: `item-${prefix}-${index}`,
        prefix,
        index,
        title,
        removable: indexes.length > 1
      })

      for (const field of group.fields) {
        const config = group.items[index][field]
        if (! config) continue

        rows.push({
          kind: 'config',
          key: 'config-' + config.name,
          config,
          label: field,
          grouped: true,
          // Fields the profile doesn't define (manually added) can be removed on their own
          removable: !! config.additional && ! templateFields.includes(field)
        })
      }
    }

    rows.push({ kind: 'groupEnd', key: 'groupEnd-' + prefix, prefix, title })
  }

  return rows
}
