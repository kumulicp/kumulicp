<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <va-modal
    v-model="show"
    title="Request Transfer"
    hide-default-actions
  >
    <template #default>
      <p>By requesting a domain transfer, you would like to use another domain registrar and are able to manage your domain and DNS settings yourself. Reasons you might want to do this include:</p>
      <ul class="va-unordered">
          <li>Wanting to discontinue use of the Control Panel</li>
          <li>Needing more control over your DNS settings than this Control Panel is currently able to offer</li>
      </ul>
      <p><b>Warning:</b> By transfering domains, we are no longer able to automatically update the DNS settings accordingly. If there is any change in our servers requiring DNS record updates, you'll have to follow those announcements and be able to update your DNS records accordingly.</p>
      <p>To find out what DNS host records you need to have, please visit <a href="/docs/controlpanel/register-custom-domain" target="_blank">our documentation</a></p>
    </template>
    <template #footer>
      <va-button
        color="backgroundSecondary"
        @click="show = !show"
      >
        Cancel
      </va-button>
      <va-button
        @click="form.post('/settings/domains/'+domain.name+'/request_transfer')"
        :disabled="form.processing"
      >
        Request Transfer
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
