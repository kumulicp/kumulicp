<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <va-modal
    v-model="show"
     no-outside-dismiss
     no-padding
     size="small"
     class="p-0"
  >
    <template #content="{ ok }">
      <va-card-title class="m-0"> {{ t('organization.webDomains.enableEmailAccounts') }} </va-card-title>
      <va-card-content class="m-0">
        {{ t('organization.webDomains.enableEmailConfirm', { name: domain.name }) }}
      </va-card-content>
      <va-card-actions align="right">
        <va-button
          color="backgroundSecondary"
          @click="ok"
        >
          {{ t('common.cancel') }}
        </va-button>
        <va-button
          @click="form.post('/settings/domains/'+domain.name+'/enable_email', {
            onSuccess: () => show = false,
          })"
          :disabled="form.processing"
        >
          {{ t('organization.webDomains.enableEmailAccounts') }}
        </va-button>
      </va-card-actions>
    </template>
  </va-modal>
</template>

<script lang="ts">
export default {
  props: {
    showModal: Boolean,
    domain: Object
  },
  emits: ['update:showModal'],
  computed: {
    show: {
      get () {
        return this.showModal
      },
      set (value) {
        this.$emit('update:showModal', value)
      }
    }
  },
  data () {
    return {
      form: useForm({})
    }
  }
}
</script>

<style lang="scss">
</style>
