<script setup lang="ts">
/**
 * TokenizedEditor — TinyMCE editor with bracket-token insertion support.
 *
 * Tokens are stored as plain {{key}} in the database.
 * Inside the editor they are displayed as non-editable styled chip spans,
 * handled transparently by the 'tokens' TinyMCE plugin.
 *
 * Usage:
 *   <TokenizedEditor v-model="htmlContent" :registry="registry" />
 */
import tinymce from 'tinymce'

// TinyMCE core
import 'tinymce/themes/silver/theme'
import 'tinymce/skins/ui/oxide/skin'
import 'tinymce/skins/ui/oxide/content'
import 'tinymce/icons/default'
import 'tinymce/models/dom/model'

// Core plugins
import 'tinymce/plugins/lists'
import 'tinymce/plugins/advlist'
import 'tinymce/plugins/link'
import 'tinymce/plugins/table'
import 'tinymce/plugins/code'
import 'tinymce/plugins/help'
import 'tinymce/plugins/wordcount'

// Custom plugins
import '@/components/TinymcePlugins/persistentgrid'
import '@/components/TinymcePlugins/tokens'

import Editor from '@tinymce/tinymce-vue'
import { ref, onUnmounted } from 'vue'
import TokenPicker from '@/components/TokenPicker.vue'
import type { TokenRegistry } from '@/tokens/tokenRegistry'

const props = defineProps<{
  modelValue: string
  registry: TokenRegistry
}>()

const emit = defineEmits<{
  'update:modelValue': [value: string]
}>()

const content = ref(props.modelValue)
const pickerOpen = ref(false)
const tinymceCssFile = ref<string | null>(null)

let activeEditor: ReturnType<typeof tinymce.get> | null = null

loadAppCSS()

async function loadAppCSS() {
  try {
    const res = await fetch('/build/manifest.json')
    if (!res.ok) return
    const data = await res.json()
    tinymceCssFile.value = '/build/' + data['resources/js/app.js']['css'][0]
  } catch {
    // Silently ignore — editor falls back gracefully
  }
}

function onEditorInit(evt: unknown, editor: ReturnType<typeof tinymce.get>) {
  activeEditor = editor
  editor.on('token-picker-open', () => {
    pickerOpen.value = true
  })
}

function onTokenSelected(tokenKey: string) {
  if (activeEditor) {
    activeEditor.execCommand('mceInsertToken', false, tokenKey)
  }
}

function onContentChange(val: string) {
  content.value = val
  emit('update:modelValue', val)
}

onUnmounted(() => {
  activeEditor = null
})
</script>

<template>
  <div class="tokenized-editor">
    <template v-if="tinymceCssFile">
      <Editor
        :model-value="content"
        @update:model-value="onContentChange"
        :init="{
          license_key: 'gpl',
          plugins: 'lists advlist link table code help wordcount persistentgrid tokens',
          toolbar:
            'tokeninsert | undo redo | blocks | bold italic | alignleft aligncenter alignright | numlist bullist | link table | code',
          promotion: false,
          content_css: tinymceCssFile,
          relative_urls: false,
          extended_valid_elements: 'span[class|data-token|contenteditable|style]',
          setup: onEditorInit,
        }"
      />
    </template>
    <va-textarea
      v-else
      :model-value="content"
      @update:model-value="onContentChange"
      class="full-width mb-2"
    />

    <TokenPicker
      v-model="pickerOpen"
      :registry="registry"
      @select="onTokenSelected"
    />
  </div>
</template>
