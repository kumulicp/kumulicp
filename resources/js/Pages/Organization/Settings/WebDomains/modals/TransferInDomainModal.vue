<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <va-modal
    v-model="show"
    title="Transfer to Control Panel"
    hide-default-actions
  >
    <template #default>
      <div class="row">
        <div class="flex md9">
          <p class="mb-2">Are you sure you want to transfer {{ domain.name }} to the Control Panel?</p>
          <p class="mb-3">All you need is your EPP/Auth code and we'll take care of transferring your domain immediately</p>
        </div>
        <div class="flex md3">
          <h5 class="va-h5 va-text-center mt-0" style="text-align:center">${{ domain.transfer_price }}</h5>
          <p class="va-text-center">Price</p>
        </div>
      </div>

      <va-input
        v-model="form.epp_code"
        class="mb-6"
        label="EPP/Auth Code"
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
        Cancel
      </va-button>
      <va-button
        @click="form.post('/settings/domains/'+domain.name+'/transfer_in'); show = false">
        Transfer to Control Panel
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
