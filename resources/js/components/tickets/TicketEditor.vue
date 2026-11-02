<script setup>
// Lean TinyMCE for ticket messages: basic formatting, lists, links, quotes and
// code. No file browser (laravel-filemanager is a shared folder and must not be
// used for tickets). The server sanitizes everything again; this only shapes
// what people can easily produce.
import 'tinymce'
import 'tinymce/themes/silver/theme'
import 'tinymce/skins/ui/oxide/skin'
import 'tinymce/skins/ui/oxide/content'
import 'tinymce/icons/default'
import 'tinymce/models/dom/model'
import 'tinymce/plugins/lists'
import 'tinymce/plugins/advlist'
import 'tinymce/plugins/link'
import 'tinymce/plugins/autolink'
import 'tinymce/plugins/codesample'
import 'tinymce/plugins/code'
import Editor from '@tinymce/tinymce-vue'
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'

const props = defineProps({
  modelValue: { type: String, default: '' },
  minHeight: { type: Number, default: 220 },
  // When set, the text is saved in this browser as a draft and restored later.
  draftKey: { type: String, default: null },
  error: { type: String, default: null },
})
const emit = defineEmits(['update:modelValue'])

const draftRestored = ref(false)
let saveTimer = null

function storage () {
  try {
    return window.localStorage
  } catch {
    return null
  }
}

function readDraft () {
  if (!props.draftKey) return null
  try {
    return storage()?.getItem(props.draftKey) || null
  } catch {
    return null
  }
}

function writeDraft (value) {
  if (!props.draftKey) return
  try {
    if (value && value.trim() !== '') {
      storage()?.setItem(props.draftKey, value)
    } else {
      storage()?.removeItem(props.draftKey)
    }
  } catch {
    // Storage can be blocked or full; drafts are a convenience only
  }
}

function clearDraft () {
  draftRestored.value = false
  writeDraft('')
}

function discardDraft () {
  clearDraft()
  emit('update:modelValue', '')
}

onMounted(() => {
  const draft = readDraft()
  if (draft && !props.modelValue) {
    emit('update:modelValue', draft)
    draftRestored.value = true
  }
})

watch(() => props.modelValue, (value) => {
  clearTimeout(saveTimer)
  saveTimer = setTimeout(() => writeDraft(value), 500)
})

onBeforeUnmount(() => clearTimeout(saveTimer))

defineExpose({ clearDraft })
</script>
<template>
  <div class="ticket-editor">
    <Editor
      :model-value="modelValue"
      @update:model-value="emit('update:modelValue', $event)"
      license-key="gpl"
      :init="{
        plugins: 'lists advlist link autolink codesample code',
        toolbar: 'blocks | bold italic underline strikethrough | bullist numlist | blockquote codesample code | link | removeformat | undo redo',
        block_formats: 'Paragraph=p; Heading=h3; Subheading=h4; Preformatted=pre',
        valid_elements: 'p,br,strong/b,em/i,u,s/strike,ul,ol,li,h3,h4,blockquote,pre,code,a[href|title]',
        link_target_list: false,
        link_title: false,
        menubar: false,
        statusbar: false,
        promotion: false,
        branding: false,
        min_height: minHeight,
        relative_urls: false,
        paste_as_text: false,
      }"
    />
    <div v-if="error" class="va-message-list__message text-danger mt-1">{{ error }}</div>
    <div v-if="draftRestored" class="va-message-list__message mt-1">
      {{ $t('tickets.draftRestored') }}
      <a href="#" @click.prevent="discardDraft">{{ $t('tickets.discardDraft') }}</a>
    </div>
  </div>
</template>
<style>
  .text-danger {
    color: var(--va-danger);
  }
</style>
