<script setup lang="ts">
import { computed } from 'vue'
import { useForm } from '@inertiajs/vue3'

interface Person { username: string, name: string, email: string, can_offer?: boolean }
interface Readiness {
  ready: boolean
  without_mailbox: Person[]
  without_mailbox_count: number
  recovery_on_domain: Person[]
  recovery_on_domain_count: number
  offers_waiting: number
}

const props = defineProps<{ domainName: string, readiness: Readiness }>()

const form = useForm({})
const can_offer_any = computed(() => props.readiness.without_mailbox.some(person => person.can_offer))
</script>

<template>
  <va-card id="domainReadiness" class="mb-4">
    <va-card-title>{{ $t('domainReadiness.title') }}</va-card-title>
    <va-card-content>
      <p class="mb-3">{{ $t('domainReadiness.intro', { domain: domainName }) }}</p>
      <va-alert id="readinessStatus" :color="readiness.ready ? 'success' : 'warning'" outline class="mb-3">
        {{ readiness.ready ? $t('domainReadiness.ready') : $t('domainReadiness.notReady', { count: readiness.without_mailbox_count }) }}
      </va-alert>

      <template v-if="readiness.without_mailbox_count > 0">
        <h6 class="va-h6 mb-2">{{ $t('domainReadiness.withoutMailbox') }}</h6>
        <ul class="mb-2" id="withoutMailbox">
          <li v-for="person in readiness.without_mailbox" :key="person.username">
            {{ person.name }} ({{ person.email }})
            <small v-if="!person.can_offer" class="va-text-secondary">{{ $t('domainReadiness.cannotHave') }}</small>
          </li>
        </ul>
        <p v-if="readiness.without_mailbox_count > readiness.without_mailbox.length" class="va-text-secondary mb-2">
          {{ $t('domainReadiness.andMore', { count: readiness.without_mailbox_count - readiness.without_mailbox.length }) }}
        </p>
        <va-button id="offerMailboxes" :disabled="form.processing || !can_offer_any" class="mb-3"
          @click="form.post('/settings/domains/' + domainName + '/offer_mailboxes')">
          {{ $t('domainReadiness.offerAll') }}
        </va-button>
      </template>

      <template v-if="readiness.recovery_on_domain_count > 0">
        <h6 class="va-h6 mb-2">{{ $t('domainReadiness.recoveryTitle') }}</h6>
        <p class="mb-2">{{ $t('domainReadiness.recoveryText') }}</p>
        <ul class="mb-3" id="recoveryOnDomain">
          <li v-for="person in readiness.recovery_on_domain" :key="person.username">{{ person.name }} ({{ person.email }})</li>
        </ul>
      </template>

      <p v-if="readiness.offers_waiting > 0" id="offersWaiting" class="mb-2">
        {{ $t('domainReadiness.offersWaiting', { count: readiness.offers_waiting }) }}
      </p>
      <p class="va-text-secondary">{{ $t('domainReadiness.history') }}</p>
    </va-card-content>
  </va-card>
</template>
