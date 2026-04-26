<script setup lang="ts">
import { ref, computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import TokenizedEditor from '@/components/TokenizedEditor.vue'
import TokenRenderer from '@/components/TokenRenderer.vue'
import type { TokenRegistry } from '@/tokens/tokenRegistry'
import { flatTokenLabels } from '@/tokens/tokenRegistry'

// Props injected by Inertia from Admin\Tokens@demo
const page = usePage<{
  registry: TokenRegistry
  sampleContext: Record<string, string>
  defaultContent: string
  breadcrumbs: { label: string; url?: string }[]
}>()

const registry = computed(() => page.props.registry)

// Editor content seeded from the backend (kept in sync with DEMO_DEFAULT_CONTENT
// so the server only loads context for the categories that are actually used)
const editorContent = ref(page.props.defaultContent)

// Context values — editable by the user to simulate token resolution
const context = ref<Record<string, string>>({ ...page.props.sampleContext })

// Flat map of key → display label for the context editor
const tokenLabels = computed(() => flatTokenLabels(registry.value))

// Only show context fields that are actually referenced in the current content
const referencedKeys = computed(() => {
  const keys: string[] = []
  const pattern = /\{\{([a-z_]+\.[a-z_]+)\}\}/g
  let m: RegExpExecArray | null
  while ((m = pattern.exec(editorContent.value)) !== null) {
    if (!keys.includes(m[1])) keys.push(m[1])
  }
  return keys
})

const activeTab = ref<'editor' | 'preview'>('editor')
</script>

<template>
  <div class="token-demo pa-4">
    <!-- Page header -->
    <va-breadcrumbs class="mb-4">
      <va-breadcrumbs-item
        v-for="crumb in page.props.breadcrumbs"
        :key="crumb.label"
        :label="crumb.label"
        :href="crumb.url"
      />
    </va-breadcrumbs>

    <h1 class="va-h3 mb-1">Token System — Editor Demo</h1>
    <p class="mb-5" style="color: var(--va-text-secondary)">
      Click the
      <code
        style="
          background: var(--va-primary);
          color: #fff;
          border-radius: 4px;
          padding: 1px 6px;
          font-size: 0.85em;
        "
        >{ }</code
      >
      button in the toolbar to open the token picker and insert dynamic data tokens.
    </p>

    <va-card class="mb-4">
      <va-card-content>
        <!-- Tab bar -->
        <va-tabs v-model="activeTab" class="mb-4">
          <va-tab name="editor">Editor</va-tab>
          <va-tab name="preview">Preview</va-tab>
        </va-tabs>

        <!-- Editor tab -->
        <div v-show="activeTab === 'editor'">
          <TokenizedEditor v-model="editorContent" :registry="registry" />
        </div>

        <!-- Preview tab -->
        <div v-show="activeTab === 'preview'">
          <TokenRenderer :content="editorContent" :values="context" />
        </div>
      </va-card-content>
    </va-card>

    <!-- Context editor (simulate token values) -->
    <va-card>
      <va-card-title>
        <va-icon name="tune" class="mr-2" />
        Token Context Values
        <span
          class="ml-2"
          style="font-size: 0.75em; font-weight: 400; color: var(--va-text-secondary)"
        >
          Edit these to simulate how tokens resolve for different users/plans/apps
        </span>
      </va-card-title>
      <va-card-content>
        <div
          style="
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 16px;
          "
        >
          <div
            v-for="key in referencedKeys"
            :key="key"
            style="display: flex; flex-direction: column; gap: 4px"
          >
            <label
              :for="`ctx-${key}`"
              style="font-size: 0.75em; color: var(--va-text-secondary)"
            >
              <code>{{ key }}</code>
              <span class="ml-1">— {{ tokenLabels[key] ?? key }}</span>
            </label>
            <va-input
              :id="`ctx-${key}`"
              v-model="context[key]"
              :placeholder="`Value for ${key}`"
              size="small"
            />
          </div>

          <div
            v-if="referencedKeys.length === 0"
            style="color: var(--va-text-secondary); font-size: 0.875em"
          >
            No tokens in the current content.
          </div>
        </div>
      </va-card-content>
    </va-card>

    <!-- Raw content inspector (collapsed) -->
    <va-collapse class="mt-4" header="Raw stored content ({{token}} format)">
      <pre
        style="
          font-size: 0.75em;
          background: var(--va-background-element);
          padding: 12px;
          border-radius: 6px;
          overflow: auto;
          white-space: pre-wrap;
          word-break: break-all;
        "
        >{{ editorContent }}</pre
      >
    </va-collapse>
  </div>
</template>
