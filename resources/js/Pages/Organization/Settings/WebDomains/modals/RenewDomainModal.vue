<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <va-modal
    v-model="show"
    :title="t('organization.webDomains.renewTitle', { name: domain.name })"
    hide-default-actions
  >
    <template #default>
      <va-alert v-if="form.processing" color="primary" icon="info" class="mb-4" border="left">
        {{ t('organization.webDomains.renewProcessing') }}
      </va-alert>
      {{ t('organization.webDomains.renewConfirm', { name: domain.name }) }}
      <va-select
        v-model="form.years"
        :options="domain.renewal_price"
        :label="t('organization.webDomains.years')"
        text-by="text"
        value-by="year"
        :error="$page.props.errors.years"
        :error-messages="$page.props.errors.years"
        required-mark
        immediateValidation
        :disabled="form.processing"
        class="mt-3"
      />
    </template>
    <template #footer>
      <va-button
        color="backgroundSecondary"
        @click="show = !show"
        :disabled="form.processing"
      >
        {{ t('common.cancel') }}
      </va-button>
      <va-button
        @click="form.post('/settings/domains/'+domain.name+'/renew', {
          onFinish: () => show = !show
        })"
        :disabled="form.processing"
      >
        {{ t('organization.webDomains.renew') }}
      </va-button>
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
      form: useForm({
        years: 1
      })
    }
  }
}
</script>

<style lang="scss">
  .row-equal .flex {
    .va-card {
      height: 100%;
    }
  }
</style>
