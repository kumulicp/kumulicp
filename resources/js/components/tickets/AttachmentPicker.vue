<script setup>
// Attach files to a message. Each file is uploaded on its own as soon as it is
// picked (so there is a progress bar and nothing is lost if the form fails
// validation) and the ids are sent with the message. The list is v-model, so
// images pasted into the editor can be added to it by the parent as well.
import axios from 'axios'
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps({
  modelValue: { type: Array, default: () => [] },
  uploadUrl: { type: String, default: '/support/tickets/uploads' },
  maxFiles: { type: Number, default: 10 },
  maxFileMb: { type: Number, default: 10 },
})
const emit = defineEmits(['update:modelValue'])
const { t } = useI18n()

const input = ref(null)
const dragging = ref(false)
// Files still uploading: { key, name, progress, error }
const uploading = ref([])

function formatSize (bytes) {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}

function pick (event) {
  addFiles(Array.from(event.target.files || []))
  // Allow picking the same file again after removing it
  event.target.value = ''
}

function drop (event) {
  dragging.value = false
  addFiles(Array.from(event.dataTransfer?.files || []))
}

function addFiles (files) {
  for (const file of files) {
    if (props.modelValue.length + uploading.value.filter(item => !item.error).length >= props.maxFiles) {
      uploading.value.push({ key: Symbol(), name: file.name, progress: 0, error: t('tickets.attachments.tooMany', { max: props.maxFiles }) })
      continue
    }
    if (file.size > props.maxFileMb * 1024 * 1024) {
      uploading.value.push({ key: Symbol(), name: file.name, progress: 0, error: t('tickets.attachments.tooLarge', { max: props.maxFileMb }) })
      continue
    }
    upload(file)
  }
}

function upload (file) {
  const item = { key: Symbol(), name: file.name, progress: 0, error: null }
  uploading.value.push(item)
  const data = new FormData()
  data.append('file', file)

  axios.post(props.uploadUrl, data, {
    onUploadProgress: (event) => {
      item.progress = event.total ? Math.round((event.loaded / event.total) * 100) : 0
    },
  })
    .then((response) => {
      uploading.value = uploading.value.filter(entry => entry.key !== item.key)
      emit('update:modelValue', [...props.modelValue, response.data])
    })
    .catch((error) => {
      item.error = error.response?.data?.errors?.file?.[0] || error.response?.data?.message || t('tickets.attachments.uploadFailed')
    })
}

function remove (attachment) {
  emit('update:modelValue', props.modelValue.filter(entry => entry.id !== attachment.id))
  // The upload is private to this person, so removing it also deletes the file
  axios.delete(`${props.uploadUrl}/${attachment.id}`).catch(() => {})
}

function dismiss (item) {
  uploading.value = uploading.value.filter(entry => entry.key !== item.key)
}

function formatSizeOf (attachment) {
  return formatSize(attachment.size)
}
</script>
<template>
  <div
    class="attachment-picker"
    :class="{ 'attachment-picker--dragging': dragging }"
    @dragover.prevent="dragging = true"
    @dragleave.prevent="dragging = false"
    @drop.prevent="drop"
  >
    <div class="attachment-picker__actions">
      <va-button size="small" color="secondary" icon="attach_file" @click="input.click()">{{ $t('tickets.attachments.add') }}</va-button>
      <span class="attachment-picker__hint ml-2">{{ $t('tickets.attachments.hint', { files: maxFiles, size: maxFileMb }) }}</span>
      <input ref="input" type="file" multiple class="attachment-picker__input" @change="pick">
    </div>

    <ul v-if="modelValue.length || uploading.length" class="attachment-picker__list">
      <li v-for="attachment in modelValue" :key="attachment.id" class="attachment-picker__item">
        <va-icon :name="attachment.is_image ? 'image' : 'description'" size="small" class="mr-1" />
        <span class="attachment-picker__name">{{ attachment.name }}</span>
        <span class="attachment-picker__size ml-2">{{ formatSizeOf(attachment) }}</span>
        <span v-if="attachment.inline" class="attachment-picker__size ml-2">({{ $t('tickets.attachments.inText') }})</span>
        <va-button preset="plain" size="small" color="danger" class="ml-2" :aria-label="$t('common.remove')" @click="remove(attachment)">{{ $t('common.remove') }}</va-button>
      </li>
      <li v-for="item in uploading" :key="item.key" class="attachment-picker__item">
        <span class="attachment-picker__name">{{ item.name }}</span>
        <template v-if="item.error">
          <span class="attachment-picker__error ml-2">{{ item.error }}</span>
          <va-button preset="plain" size="small" class="ml-2" @click="dismiss(item)">{{ $t('common.close') }}</va-button>
        </template>
        <va-progress-bar v-else :model-value="item.progress" size="small" class="ml-2 attachment-picker__progress" />
      </li>
    </ul>
  </div>
</template>
<style lang="scss">
  .attachment-picker {
    border: 1px dashed var(--va-background-border);
    border-radius: 4px;
    padding: 0.75em;

    &--dragging {
      border-color: var(--va-primary);
      background: color-mix(in srgb, var(--va-primary) 6%, transparent);
    }

    &__input { display: none; }
    &__hint, &__size { color: var(--va-secondary); font-size: 0.85em; }
    &__error { color: var(--va-danger); font-size: 0.9em; }
    &__list { list-style: none; padding: 0; margin: 0.5em 0 0; }
    &__item { display: flex; align-items: center; padding: 0.25em 0; }
    &__name { overflow-wrap: anywhere; }
    &__progress { width: 8em; }
  }
</style>
