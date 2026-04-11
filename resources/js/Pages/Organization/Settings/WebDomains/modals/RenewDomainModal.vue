<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <va-modal
    v-model="show"
    :title="'Renew '+domain.name"
    hide-default-actions
  >
    <template #default>
      <va-alert v-if="form.processing" color="primary" icon="info" class="mb-4" border="left">
        Please be patient as we submit your renewal. This will only take a few seconds.
      </va-alert>
      If you are sure you want to renew {{ domain.name }}, please select how many years you want to renew for.
      <va-select
        v-model="form.years"
        :options="domain.renewal_price"
        label="Years"
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
        Cancel
      </va-button>
      <va-button
        @click="form.post('/settings/domains/'+domain.name+'/renew', {
          onFinish: () => show = !show
        })"
        :disabled="form.processing"
      >
        Renew
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
