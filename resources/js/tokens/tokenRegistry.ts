export interface Token {
  key: string
  label: string
  example: string
}

export interface TokenCategory {
  label: string
  icon: string
  tokens: Token[]
}

export type TokenRegistry = Record<string, TokenCategory>

/** Bracket-delimited token syntax used in stored content */
export const TOKEN_OPEN = '{{'
export const TOKEN_CLOSE = '}}'

export function tokenSyntax(key: string): string {
  return `${TOKEN_OPEN}${key}${TOKEN_CLOSE}`
}

/** Parse all token keys found in a content string */
export function extractTokenKeys(content: string): string[] {
  const pattern = /\{\{([a-z_]+\.[a-z_]+)\}\}/g
  const keys: string[] = []
  let match: RegExpExecArray | null
  while ((match = pattern.exec(content)) !== null) {
    if (!keys.includes(match[1])) {
      keys.push(match[1])
    }
  }
  return keys
}

/**
 * Resolve all {{token.key}} occurrences in content using a flat key→value map.
 * Unresolved tokens are left untouched.
 */
export function resolveTokens(content: string, values: Record<string, string>): string {
  return content.replace(/\{\{([a-z_]+\.[a-z_]+)\}\}/g, (match, key) => {
    return Object.prototype.hasOwnProperty.call(values, key) ? values[key] : match
  })
}

/**
 * Flatten a TokenRegistry into a simple key→label map for display purposes.
 */
export function flatTokenLabels(registry: TokenRegistry): Record<string, string> {
  const result: Record<string, string> = {}
  for (const category of Object.values(registry)) {
    for (const token of category.tokens) {
      result[token.key] = `${category.label} — ${token.label}`
    }
  }
  return result
}
