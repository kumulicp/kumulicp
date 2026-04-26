<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'

</script>
<template>
  <va-modal
    v-model="show"
    :title="$t('organization.webDomains.requestTransfer')"
    hide-default-actions
  >
    <template #default>
      <p>{{ $t('organization.webDomains.requestTransferDesc') }}</p>
      <ul class="va-unordered">
          <li>{{ $t('organization.webDomains.requestTransferReason1') }}</li>
          <li>{{ $t('organization.webDomains.requestTransferReason2') }}</li>
      </ul>
      <p><b>{{ $t('organization.webDomains.warning') }}:</b> {{ $t('organization.webDomains.requestTransferWarning') }}</p>
      <p v-html="$t('organization.webDomains.requestTransferDocs')"></p>
    </template>
    <template #footer>
      <va-button
        color="backgroundSecondary"
        @click="show = !show"
      >
        {{ $t('common.cancel') }}
      </va-button>
      <va-button
        @click="form.post('/settings/domains/'+domain.name+'/request_transfer')"
        :disabled="form.processing"
      >
        {{ $t('organization.webDomains.requestTransfer') }}
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
      form: useForm({})
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
