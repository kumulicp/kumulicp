<script setup lang="ts">
import DomainReadinessSummary from '@/components/DomainReadinessSummary.vue'
import { router, useForm } from '@inertiajs/vue3'

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
      <va-card-title class="m-0"> {{ $t('organization.webDomains.enableEmailAccounts') }} </va-card-title>
      <va-card-content class="m-0">
        <p class="mb-3">{{ $t('organization.webDomains.enableEmailConfirm', { name: domain.name }) }}</p>
        <h6 class="va-h6 mb-2">{{ $t('domainReadiness.enableTitle') }}</h6>
        <p class="va-text-secondary mb-3">{{ $t('domainReadiness.enableIntro') }}</p>
        <div v-if="preview === undefined" id="enablePreviewLoading" class="va-text-secondary">{{ $t('domainReadiness.loading') }}</div>
        <domain-readiness-summary v-else-if="preview" :readiness="preview" />
      </va-card-content>
      <va-card-actions align="right">
        <va-button
          color="backgroundSecondary"
          @click="ok"
        >
          {{ $t('common.cancel') }}
        </va-button>
        <va-button
          @click="form.post('/settings/domains/'+domain.name+'/enable_email', {
            onSuccess: () => show = false,
          })"
          :disabled="form.processing"
        >
          {{ $t('organization.webDomains.enableEmailAccounts') }}
        </va-button>
      </va-card-actions>
    </template>
  </va-modal>
</template>

<script lang="ts">
export default {
  props: {
    showModal: Boolean,
    domain: Object,
    // Who is on the domain, asked for when the dialog opens (undefined until it arrives)
    preview: Object
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
  watch: {
    showModal (value) {
      // Fresh each time it opens: people may have added their secondary recovery email since it was last open
      if (value) {
        router.reload({ only: ['enable_preview'], preserveScroll: true, preserveState: true })
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
