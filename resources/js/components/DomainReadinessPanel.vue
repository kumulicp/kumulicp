<script setup lang="ts">
import DomainReadinessSummary from '@/components/DomainReadinessSummary.vue'

defineProps<{ domainName: string, readiness: any }>()
</script>

<template>
  <va-card id="domainReadiness" class="mb-4">
    <va-card-title>{{ $t('domainReadiness.title') }}</va-card-title>
    <va-card-content>
      <p class="mb-3">{{ $t('domainReadiness.intro', { domain: domainName }) }}</p>
      <domain-readiness-summary :readiness="readiness" />

      <p v-if="readiness.offers_waiting > 0" id="offersWaiting" class="mb-2">
        {{ $t('domainReadiness.offersWaiting', { count: readiness.offers_waiting }) }}
      </p>
      <p class="va-text-secondary">{{ $t('domainReadiness.history') }}</p>
    </va-card-content>
  </va-card>
</template>
