<script setup lang="ts">
interface Person { username: string, name: string, email: string, reason?: string }
interface Readiness {
  ready: boolean
  switching_count: number
  with_secondary_count: number
  without_secondary: Person[]
  without_secondary_count: number
  already_count: number
  cannot_switch: Person[]
  cannot_switch_count: number
  recovery_on_domain: Person[]
  recovery_on_domain_count: number
  offers_waiting: number
}

defineProps<{ readiness: Readiness }>()
</script>

<template>
  <div id="domainReadinessSummary">
    <p v-if="readiness.switching_count === 0 && readiness.cannot_switch_count === 0" id="nobodySwitching" class="mb-3">
      {{ $t('domainReadiness.nobody') }}
    </p>

    <template v-if="readiness.switching_count > 0">
      <va-alert id="switchingSummary" color="info" outline class="mb-3">
        <p>{{ $t('domainReadiness.switching', { count: readiness.switching_count }) }}</p>
        <p id="secondaryCounts" class="mt-1">
          {{ $t('domainReadiness.secondaryCounts', { with_count: readiness.with_secondary_count, without_count: readiness.without_secondary_count }) }}
        </p>
      </va-alert>

      <template v-if="readiness.without_secondary_count > 0">
        <h6 class="va-h6 mb-1">{{ $t('domainReadiness.withoutSecondary') }}</h6>
        <p class="va-text-secondary mb-2">{{ $t('domainReadiness.withoutSecondaryHelp') }}</p>
        <ul class="mb-2" id="withoutSecondary">
          <li v-for="person in readiness.without_secondary" :key="person.username">{{ person.name }} ({{ person.email }})</li>
        </ul>
        <p v-if="readiness.without_secondary_count > readiness.without_secondary.length" class="va-text-secondary mb-3">
          {{ $t('domainReadiness.andMore', { count: readiness.without_secondary_count - readiness.without_secondary.length }) }}
        </p>
      </template>
    </template>

    <template v-if="readiness.cannot_switch_count > 0">
      <va-alert id="cannotSwitchAlert" color="warning" outline class="mb-3">
        {{ $t('domainReadiness.cannotSwitch', { count: readiness.cannot_switch_count }) }}
      </va-alert>
      <ul class="mb-3" id="cannotSwitch">
        <li v-for="person in readiness.cannot_switch" :key="person.username">
          {{ person.name }} ({{ person.email }})
          <small class="va-text-secondary">{{ $t('domainReadiness.reason.' + person.reason) }}</small>
        </li>
      </ul>
    </template>

    <template v-if="readiness.recovery_on_domain_count > 0">
      <h6 class="va-h6 mb-2">{{ $t('domainReadiness.recoveryTitle') }}</h6>
      <p class="mb-2">{{ $t('domainReadiness.recoveryText') }}</p>
      <ul class="mb-3" id="recoveryOnDomain">
        <li v-for="person in readiness.recovery_on_domain" :key="person.username">{{ person.name }} ({{ person.email }})</li>
      </ul>
    </template>
  </div>
</template>
