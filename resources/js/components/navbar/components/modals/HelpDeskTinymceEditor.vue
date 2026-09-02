<script setup>
import 'tinymce'
import 'tinymce/themes/silver/theme'
import 'tinymce/skins/ui/oxide/skin'
import 'tinymce/skins/ui/oxide/content'
import 'tinymce/icons/default'
import 'tinymce/models/dom/model'
import 'tinymce/plugins/lists'
import 'tinymce/plugins/advlist'
import 'tinymce/plugins/link'
import Editor from '@tinymce/tinymce-vue'

defineProps({ modelValue: String })
const emit = defineEmits(['update:modelValue'])
</script>
<template>
  <Editor
    :model-value="modelValue"
    @update:model-value="emit('update:modelValue', $event)"
    license-key="gpl"
    :init="{
      plugins: 'lists advlist link',
      toolbar: 'bold italic underline | bullist numlist | link | removeformat',
      menubar: false,
      promotion: false,
      statusbar: false,
      min_height: 200,
    }"
  />
</template>
