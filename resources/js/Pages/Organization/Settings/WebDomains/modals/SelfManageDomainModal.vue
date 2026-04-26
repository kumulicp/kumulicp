<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'

</script>
<template>
  <va-modal
    v-model="show"
    :title="$t('organization.webDomains.selfManage')"
    hide-default-actions
  >
    <template #default>
      <p>{{ $t('organization.webDomains.selfManageDesc') }}</p>
      <ul class="va-unordered">
          <li>{{ $t('organization.webDomains.selfManageReason1') }}</li>
          <li>{{ $t('organization.webDomains.selfManageReason2') }}</li>
          <li>{{ $t('organization.webDomains.selfManageReason3') }}</li>
      </ul>
      <p><b>{{ $t('organization.webDomains.warning') }}:</b> {{ $t('organization.webDomains.selfManageWarning') }}</p>
      <p v-html="$t('organization.webDomains.selfManageDocs')"></p>
    </template>
    <template #footer>
      <va-button
        color="backgroundSecondary"
        @click="show = false"
      >
        {{ $t('common.cancel') }}
      </va-button>
      <va-button
        @click="form.post('/settings/domains/'+domain.name+'/self_manage'); show = false"
      >
        {{ $t('organization.webDomains.selfManage') }}
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
