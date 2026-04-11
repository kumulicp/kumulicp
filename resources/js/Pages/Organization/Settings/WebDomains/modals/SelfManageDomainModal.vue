<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <va-modal
    v-model="show"
    title="Self Manage"
    hide-default-actions
  >
    <template #default>
      <p>Self managing your domain means that you register your domain yourself and manage your DNS settings yourself. Reasons you might want to do this include:</p>
      <ul class="va-unordered">
          <li>Wanting to discontinue use of the Control Panel</li>
          <li>Needing more control over your DNS settings than this Control Panel is currently able to offer</li>
          <li>Not being able to transfer your domain to the Control Panel for technical reasons</li>
      </ul>
      <p><b>Warning:</b> By self managing your domain, we are no longer able to automatically update the DNS settings accordingly. If there is any change in our servers requiring DNS record updates, you'll have to follow those announcements and be able to update your DNS records accordingly.</p>
      <p>To find out what DNS host records you need to have, please visit <a href="/docs/controlpanel/register-custom-domain" target="_blank">our documentation</a></p>
    </template>
    <template #footer>
      <va-button
        color="backgroundSecondary"
        @click="show = false"
      >
        Cancel
      </va-button>
      <va-button
        @click="form.post('/settings/domains/'+domain.name+'/self_manage'); show = false"
      >
        Self manage
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
