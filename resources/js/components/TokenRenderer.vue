<script setup lang="ts">
/**
 * TokenRenderer — displays HTML content with {{token.key}} placeholders
 * resolved to live values from the supplied context map.
 *
 * Unresolved tokens are shown as styled chips so editors can spot them.
 *
 * Usage:
 *   <TokenRenderer :content="htmlWithTokens" :values="{ 'user.name': 'Jane' }" />
 */
import { computed } from 'vue'
import { extractTokenKeys } from '@/tokens/tokenRegistry'

const props = defineProps<{
  content: string
  values: Record<string, string>
}>()

const rendered = computed(() => {
  if (!props.content) return ''
  return props.content.replace(/\{\{([a-z_]+\.[a-z_]+)\}\}/g, (_match, key) => {
    if (Object.prototype.hasOwnProperty.call(props.values, key) && props.values[key] !== '') {
      // Resolved — show the plain value
      return `<span class="token-resolved" title="${key}">${escapeHtml(props.values[key])}</span>`
    }
    // Unresolved — show the chip so it's obvious
    return (
      `<span class="token-unresolved" title="Unresolved token: ${key}" ` +
      `style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;` +
      `border-radius:12px;background:#f0ad4e22;color:#c07000;border:1px dashed #f0ad4e;` +
      `font-size:0.8em;font-family:monospace;white-space:nowrap;">` +
      `<span style="opacity:0.7">{ }</span>{{${key}}}` +
      `</span>`
    )
  })
})

const unresolvedKeys = computed(() => {
  return extractTokenKeys(props.content).filter(
    (k) => !Object.prototype.hasOwnProperty.call(props.values, k) || props.values[k] === '',
  )
})

function escapeHtml(str: string): string {
  return str
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
}
</script>

<template>
  <div class="token-renderer">
    <!-- Unresolved token warning banner -->
    <va-alert
      v-if="unresolvedKeys.length"
      color="warning"
      class="mb-3"
      icon="warning"
    >
      <strong>{{ unresolvedKeys.length }} unresolved
        {{ unresolvedKeys.length === 1 ? 'token' : 'tokens' }}:</strong>
      {{ unresolvedKeys.join(', ') }}
    </va-alert>

    <!-- Rendered HTML -->
    <!-- eslint-disable-next-line vue/no-v-html -->
    <div class="token-renderer__content" v-html="rendered" />
  </div>
</template>

<style scoped>
.token-renderer__content :deep(.token-resolved) {
  background: rgba(21, 78, 193, 0.08);
  border-radius: 4px;
  padding: 0 3px;
  color: #154ec1;
  font-weight: 500;
}
</style>
