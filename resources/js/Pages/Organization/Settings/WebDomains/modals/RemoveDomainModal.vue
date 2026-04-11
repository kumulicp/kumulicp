<script setup>
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <va-modal
    v-model="show"
    title="Remove Domain"
    hide-default-actions
  >
    <template #default>
      <p v-if="primary_app">Currently, {{ domain.name }} is redirected to {{ primary_app.name }}. If you remove this domain, anyone that goes to this website will no longer be redirected to the right place.</p>
      <p>Are you sure you want to remove this domain?</p>
    </template>
    <template #footer>
      <va-button
        color="backgroundSecondary"
        @click="show = false"
      >
        Cancel
      </va-button>
      <va-button
        color="danger"
        id="remove"
        @click="form.post('/settings/domains/'+domain.name+'/remove'); show = false"
      >
        Remove Domain
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
