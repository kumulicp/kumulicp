<script setup>
import { useForm } from '@inertiajs/vue3'

</script>
<template>
  <va-modal
    v-model="show"
    :title="$t('organization.webDomains.removeDomain')"
    hide-default-actions
  >
    <template #default>
      <p v-if="primary_app">{{ $t('organization.webDomains.removeDomainRedirectWarning', { name: domain.name, app: primary_app.name }) }}</p>
      <p>{{ $t('organization.webDomains.removeDomainConfirm') }}</p>
    </template>
    <template #footer>
      <va-button
        color="backgroundSecondary"
        @click="show = false"
      >
        {{ $t('common.cancel') }}
      </va-button>
      <va-button
        color="danger"
        id="remove"
        @click="form.post('/settings/domains/'+domain.name+'/remove'); show = false"
      >
        {{ $t('organization.webDomains.removeDomain') }}
      </va-button>
    </template>
  </va-modal>
</template>

<script>
export default {
  props: {
    showModal: Boolean,
    domain: Object,
    primary_app: Object
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
