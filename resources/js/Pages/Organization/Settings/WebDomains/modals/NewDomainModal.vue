<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <va-modal
    v-model="show"
    title="New Domain"
    hide-default-actions
    >
    <template #default>
      <va-alert border="top" border-color="primary" >Choose how you would like your web domain managed.</va-alert>
      <va-list class="mt-3">
        <va-list-item class="mt-3">
          <va-list-item-section icon>
            <Link id="register" href="/settings/domains/availability"><va-button>Select</va-button></Link>
          </va-list-item-section>

          <va-list-item-section>
            <va-list-item-label label> Register Domain </va-list-item-label>
            <va-list-item-label caption lines="3">
              Register a new domain name. Choose this option if your organization doesn't already own it's domain name
              and needs to register one for the first time.
            </va-list-item-label>
          </va-list-item-section>
        </va-list-item>

        <va-list-separator fit spaced />

        <va-list-item class="mt-3">
          <va-list-item-section icon>
            <Link id="transfer" href="/settings/domains/transfer"><va-button>Select</va-button></Link>
          </va-list-item-section>

          <va-list-item-section>
            <va-list-item-label label> Transfer Domain </va-list-item-label>
            <va-list-item-label caption lines="3">
              Transfer domain from another provide. Choose this option if you already own your organization's domain,
              but would like for we manage it for you. We'll help you transfer your domain to our services.
            </va-list-item-label>
          </va-list-item-section>
        </va-list-item>

        <va-list-separator fit spaced />

        <va-list-item class="mt-3">
          <va-list-item-section icon>
            <Link id="connect" href="/settings/domains/connect"><va-button>Select</va-button></Link>
          </va-list-item-section>

          <va-list-item-section>
            <va-list-item-label label> Connect Existing Domain </va-list-item-label>
            <va-list-item-label caption lines="3">
              Set your domain name. Choose this option if you already own your domain name, and want to continue managing it yourself.
            </va-list-item-label>
          </va-list-item-section>
        </va-list-item>
      </va-list>
    </template>
    <template #footer>
      <va-button
        color="textInverted"
        @click="show = !show"
        >
        Cancel
      </va-button>
    </template>
  </va-modal>
</template>
<script lang="ts">
export default {
  props: {
    showModal: Boolean
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
  }
}
</script>
