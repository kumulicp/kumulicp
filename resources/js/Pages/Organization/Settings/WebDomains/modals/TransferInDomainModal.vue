<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'

</script>
<template>
  <va-modal
    v-model="show"
    :title="$t('organization.webDomains.transferToControlPanel')"
    hide-default-actions
  >
    <template #default>
      <div class="row">
        <div class="flex md9">
          <p class="mb-2">{{ $t('organization.webDomains.transferInConfirm', { name: domain.name }) }}</p>
          <p class="mb-3">{{ $t('organization.webDomains.transferInEppDesc') }}</p>
        </div>
        <div class="flex md3">
          <h5 class="va-h5 va-text-center mt-0" style="text-align:center">${{ domain.transfer_price }}</h5>
          <p class="va-text-center">{{ $t('organization.webDomains.price') }}</p>
        </div>
      </div>

      <va-input
        v-model="form.epp_code"
        class="mb-6"
        :label="$t('organization.webDomains.eppAuthCode')"
        immediateValidation
        :error="$page.props.errors.epp_code"
        :error-messages="$page.props.errors.epp_code"
      />
    </template>
    <template #footer>
      <va-button
        color="backgroundSecondary"
        @click="show = false"
      >
        {{ $t('common.cancel') }}
      </va-button>
      <va-button
        @click="form.post('/settings/domains/'+domain.name+'/transfer_in'); show = false">
        {{ $t('organization.webDomains.transferToControlPanel') }}
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
        years: 1,
        epp_code: ''
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
