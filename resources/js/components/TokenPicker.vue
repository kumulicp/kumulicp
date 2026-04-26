<script setup lang="ts">
import { computed, ref } from 'vue'
import type { Token, TokenCategory, TokenRegistry } from '@/tokens/tokenRegistry'
import { tokenSyntax } from '@/tokens/tokenRegistry'

const props = defineProps<{
  modelValue: boolean
  registry: TokenRegistry
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  select: [tokenKey: string]
}>()

const search = ref('')
const activeCategory = ref<string | null>(null)

const categoryKeys = computed(() => Object.keys(props.registry))

const filteredCategories = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return props.registry

  const result: TokenRegistry = {}
  for (const [catKey, cat] of Object.entries(props.registry)) {
    const tokens = cat.tokens.filter(
      (t) =>
        t.label.toLowerCase().includes(q) ||
        t.key.toLowerCase().includes(q) ||
        cat.label.toLowerCase().includes(q),
    )
    if (tokens.length) result[catKey] = { ...cat, tokens }
  }
  return result
})

function selectToken(token: Token) {
  emit('select', token.key)
  emit('update:modelValue', false)
  search.value = ''
}

function close() {
  emit('update:modelValue', false)
  search.value = ''
}

function categoryColor(key: string): string {
  const palette: Record<string, string> = {
    user: '#154EC1',
    org: '#2D9CDB',
    plan: '#6E59CF',
    app: '#27AE60',
  }
  return palette[key] ?? '#888'
}
</script>

<template>
  <va-modal
    :model-value="modelValue"
    @update:model-value="$emit('update:modelValue', $event)"
    title="Insert Token"
    hide-default-actions
    size="medium"
    class="token-picker-modal"
  >
    <div class="token-picker">
      <!-- Search -->
      <va-input
        v-model="search"
        placeholder="Search tokens…"
        class="mb-3"
        clearable
        style="width: 100%"
      >
        <template #prepend>
          <va-icon name="search" size="small" />
        </template>
      </va-input>

      <!-- Categories & tokens -->
      <div
        v-for="(cat, catKey) in filteredCategories"
        :key="catKey"
        class="token-category mb-4"
      >
        <!-- Category header -->
        <div class="token-category__header mb-2" style="display:flex;align-items:center;gap:8px">
          <span
            class="token-category__badge"
            :style="{
              background: categoryColor(catKey as string),
              color: '#fff',
              borderRadius: '4px',
              padding: '2px 8px',
              fontSize: '0.75em',
              fontWeight: 600,
              textTransform: 'uppercase',
              letterSpacing: '0.05em',
            }"
          >
            {{ cat.label }}
          </span>
        </div>

        <!-- Token chips -->
        <div style="display:flex;flex-wrap:wrap;gap:8px">
          <button
            v-for="token in cat.tokens"
            :key="token.key"
            class="token-item"
            :title="`Example: ${token.example}`"
            @click="selectToken(token)"
            :style="{
              display: 'inline-flex',
              flexDirection: 'column',
              alignItems: 'flex-start',
              padding: '6px 12px',
              border: `1px solid ${categoryColor(catKey as string)}33`,
              borderRadius: '8px',
              background: `${categoryColor(catKey as string)}0d`,
              cursor: 'pointer',
              transition: 'all 0.15s',
              minWidth: '140px',
            }"
          >
            <span style="font-size:0.75em;font-family:monospace;font-weight:600">
              {{ tokenSyntax(token.key) }}
            </span>
            <span style="font-size:0.7em;color:#888;margin-top:2px">
              {{ token.label }}
            </span>
            <span style="font-size:0.68em;color:#aaa;margin-top:1px;font-style:italic">
              e.g. {{ token.example }}
            </span>
          </button>
        </div>
      </div>

      <!-- Empty state -->
      <div
        v-if="Object.keys(filteredCategories).length === 0"
        style="text-align:center;padding:24px;color:#888"
      >
        No tokens match "{{ search }}"
      </div>
    </div>

    <template #footer>
      <va-button preset="secondary" @click="close">Cancel</va-button>
    </template>
  </va-modal>
</template>

<style scoped>
.token-item:hover {
  filter: brightness(0.95);
  transform: translateY(-1px);
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}
</style>
