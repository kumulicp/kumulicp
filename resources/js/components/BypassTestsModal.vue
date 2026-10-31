<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { computed } from 'vue'

const props = defineProps<{
  modelValue: boolean
  app: { slug: string }
  version: { version: string }
}>()

const emit = defineEmits(['update:modelValue'])

const form = useForm({
  reason: '',
  acknowledge: false
})

const show = computed({
  get: () => props.modelValue,
  set: (value: boolean) => emit('update:modelValue', value)
})

const submit = () => {
  form.post('/admin/apps/' + props.app.slug + '/versions/' + props.version.version + '/tests/bypass', {
    preserveScroll: true,
    onSuccess: () => {
      form.reset()
      show.value = false
    }
  })
}
</script>
<template>
  <va-modal v-model="show" no-outside-dismiss no-padding size="small" class="p-0">
    <template #content="{ ok }">
      <form @submit.prevent="submit">
        <va-card-title class="m-0">{{ $t('admin.versionTests.bypass.title', { version: version.version }) }}</va-card-title>
        <va-card-content class="m-0">
          <va-alert color="warning" border="top" border-color="warning" class="mb-3">
            <div class="font-bold mb-1">{{ $t('admin.versionTests.bypass.warning') }}</div>
            <ul class="ml-4" style="list-style: disc">
              <li>{{ $t('admin.versionTests.bypass.risk1') }}</li>
              <li>{{ $t('admin.versionTests.bypass.risk2') }}</li>
              <li>{{ $t('admin.versionTests.bypass.risk3') }}</li>
              <li>{{ $t('admin.versionTests.bypass.risk4') }}</li>
            </ul>
          </va-alert>
          <va-textarea
            v-model="form.reason"
            id="bypassReason"
            :label="$t('admin.versionTests.bypass.reason')"
            class="mb-3"
            required-mark
            :min-rows="3"
            :error="!!form.errors.reason"
            :error-messages="form.errors.reason"
          />
          <va-checkbox
            v-model="form.acknowledge"
            id="bypassAcknowledge"
            :label="$t('admin.versionTests.bypass.acknowledge')"
            :error="!!form.errors.acknowledge"
            :error-messages="form.errors.acknowledge"
          />
        </va-card-content>
        <va-card-actions align="right" class="">
          <va-button color="textInverted" :disabled="form.processing" @click="ok">{{ $t('common.cancel') }}</va-button>
          <va-button type="submit" id="confirmBypass" color="danger" :disabled="form.processing || !form.acknowledge || form.reason.trim().length < 5" class="mr-2 mb-2">{{ $t('admin.versionTests.bypass.confirm') }}</va-button>
        </va-card-actions>
      </form>
    </template>
  </va-modal>
</template>
