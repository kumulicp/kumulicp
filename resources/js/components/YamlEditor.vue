<template>
  <div class="yaml-editor">
    <div ref="editor"></div>

    <div class="toolbar">
      <button @click.prevent="applyChanges">{{ t('form.cleanYaml') }}</button>
      <span v-if="applyError" class="error">
        {{ applyError }}
      </span>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, onBeforeUnmount, watch } from 'vue'

import { EditorState } from '@codemirror/state'
import { EditorView, keymap } from '@codemirror/view'
import { defaultKeymap, indentWithTab } from '@codemirror/commands'
import { yaml as yamlLanguage } from '@codemirror/lang-yaml'
import { linter, lintGutter } from '@codemirror/lint'
import { parseDocument, stringify } from 'yaml'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

/* -------------------------- */

const props = defineProps({
  modelValue: {
    type: Object,
    default: () => ({})
  }
})

const emit = defineEmits(['update:modelValue'])

/* -------------------------- */

const editor = ref(null)
const applyError = ref(null)

let view = null

/* --------------------------
   YAML Linter (instant)
--------------------------- */
function yamlLinter () {
  return linter((view) => {
    const diagnostics = []
    const doc = view.state.doc.toString()
    const parsed = parseDocument(doc)

    parsed.errors.forEach(error => {
      diagnostics.push({
        from: error.pos?.[0] ?? 0,
        to: error.pos?.[1] ?? 0,
        severity: 'error',
        message: error.message
      })
    })

    return diagnostics
  })
}

/* --------------------------
   Apply Button Handler
--------------------------- */
function applyChanges () {
  applyError.value = null

  const yamlText = view.state.doc.toString()
  const parsed = parseDocument(yamlText)

  if (parsed.errors.length) {
    applyError.value = parsed.errors[0].message
    return
  }

  try {
    const obj = parsed.toJS()
    emit('update:modelValue', obj)
  } catch (e) {
    applyError.value = e.message
  }
}

/* --------------------------
   Update editor from parent
   (ONLY when external change)
--------------------------- */
function updateEditorContent (newYaml) {
  if (!view) return

  const current = view.state.doc.toString()
  if (current === newYaml) return

  view.dispatch({
    changes: {
      from: 0,
      to: current.length,
      insert: newYaml
    }
  })
}

/* --------------------------
   Mount
--------------------------- */
onMounted(() => {
  const initialYaml = stringify(props.modelValue ?? {})

  const state = EditorState.create({
    doc: initialYaml,
    extensions: [
      keymap.of([
        indentWithTab,
        ...defaultKeymap
      ]),
      yamlLanguage(),
      yamlLinter(),
      lintGutter()
    ]
  })
  view = new EditorView({
    state,
    parent: editor.value
  })
})

/* --------------------------
   Watch for external object changes
--------------------------- */
watch(
  () => props.modelValue,
  (newVal) => {
    const newYaml = stringify(newVal ?? {})
    updateEditorContent(newYaml)
  },
  { deep: true }
)

/* -------------------------- */

onBeforeUnmount(() => {
  if (view) view.destroy()
})
</script>

<style scoped>
.yaml-editor {
  border: 1px solid #ccc;
  border-radius: 6px;
  overflow: hidden;
}

.yaml-editor .cm-editor {
  min-height: 300px;
  font-family: monospace;
}

.toolbar {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 8px;
  border-top: 1px solid #eee;
}

button {
  padding: 4px 12px;
  cursor: pointer;
}

.error {
  color: red;
  font-size: 0.9rem;
}
</style>
