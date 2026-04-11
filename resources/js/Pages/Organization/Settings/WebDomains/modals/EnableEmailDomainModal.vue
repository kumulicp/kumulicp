<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <va-modal
    v-model="show"
     no-outside-dismiss
     no-padding
     size="small"
     class="p-0"
  >
    <template #content="{ ok }">
      <va-card-title class="m-0"> Enable Email Accounts </va-card-title>
      <va-card-content class="m-0">
        Enabling email accounts for {{ domain.name }} allows you add email accounts with @{{ domain.name }} at the end. Are you sure you want to enable this feature?
      </va-card-content>
      <va-card-actions align="right">
        <va-button
          color="backgroundSecondary"
          @click="ok"
        >
          Cancel
        </va-button>
        <va-button
          @click="form.post('/settings/domains/'+domain.name+'/enable_email', {
            onSuccess: () => show = false,
          })"
          :disabled="form.processing"
        >
          Enable Email Accounts
        </va-button>
      </va-card-actions>
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
</style>
