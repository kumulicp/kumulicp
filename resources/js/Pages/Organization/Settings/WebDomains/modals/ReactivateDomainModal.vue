<script setup>
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <va-modal
    v-model="show"
    :title="'Reactivate '+domain.name"
    hide-default-actions
  >
    <template #default>
      If you are sure you want to reactivate {{ domain.name }}, please select how many years you want to renew for.
      <va-input
        v-model="form.years"
        type="number"
        label="Years"
        immediateValidation
        :error="$page.props.errors.years"
        :error-messages="$page.props.errors.years"
        min="0"
        max="10"
        required-mark
      />
    </template>
    <template #footer>
      <va-card-actions>
        <va-button
          color="backgroundSecondary"
          @click="show = !show"
        >
          Cancel
        </va-button>
        <va-button
          @click="form.post('/settings/domains/'+domain.name+'/reactivate')"
          :disabled="form.processing"
        >
          Reactivate
        </va-button>
      </va-card-actions>
    </template>
  </va-modal>
</template>

<script>
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
      errors: this.$page.props.errors,
      form: useForm({
        years: 1
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
