<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { computed } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'

interface SyncResult {
  checked: number
  updated: number
  unchanged: number
  missing: string[]
  failed: Record<string, string>
  orphans: string[]
}

const props = defineProps<{
  ldap_enabled: boolean
  aliases: { enabled: boolean, attribute: string | null, sync_enabled: boolean, count: number }
  recovery: { attribute: string | null, sync_enabled: boolean, count: number, missing: number }
  sync_result?: SyncResult | null
  alias_sync_result?: SyncResult | null
}>()

const sync_form = useForm({})
const can_sync = computed(() => props.recovery.sync_enabled || props.aliases.sync_enabled)
// What the last sync did, for each kind of data it covered
const results = computed(() => [
  { key: 'recovery', title: 'orgEmail.resultRecovery', data: props.sync_result },
  { key: 'aliases', title: 'orgEmail.resultAliases', data: props.alias_sync_result },
].filter((result): result is { key: string, title: string, data: SyncResult } => !!result.data))
</script>
<template>
  <Head>
    <title>{{ $t('orgEmail.title') }} - Control Panel</title>
  </Head>
  <va-card class="mb-4">
    <va-card-title>{{ $t('orgEmail.title') }}</va-card-title>
    <va-card-content>
      <p class="mb-4">{{ $t('orgEmail.description') }}</p>

      <h6 class="va-h6 mb-2">{{ $t('orgEmail.aliasesTitle') }}</h6>
      <va-alert id="aliasesStatus" :color="aliases.enabled ? 'success' : 'warning'" class="mb-4">
        <template v-if="!ldap_enabled">{{ $t('orgEmail.aliasesNoLdap') }}</template>
        <template v-else-if="aliases.enabled">{{ $t('orgEmail.aliasesLdap', { attribute: aliases.attribute }) }}</template>
        <template v-else>{{ $t('orgEmail.aliasesDisabled') }}</template>
      </va-alert>
      <p v-if="aliases.enabled" id="aliasesCount" class="mb-4">{{ $t('orgEmail.aliasesCount', { count: aliases.count }) }}</p>

      <h6 class="va-h6 mb-2">{{ $t('orgEmail.recoveryTitle') }}</h6>
      <p class="mb-2">{{ $t('orgEmail.recoveryCount', { count: recovery.count }) }}</p>
      <p v-if="recovery.missing > 0" id="recoveryMissing" class="mb-2 va-text-danger">{{ $t('orgEmail.recoveryMissing', { count: recovery.missing }) }}</p>
      <p id="recoveryStatus" class="mb-3">
        <template v-if="!ldap_enabled">{{ $t('orgEmail.recoveryNoLdap') }}</template>
        <template v-else-if="!recovery.sync_enabled">{{ $t('orgEmail.recoveryNoAttribute') }}</template>
        <template v-else>{{ $t('orgEmail.recoveryAttribute', { attribute: recovery.attribute }) }}</template>
      </p>

      <va-button id="syncRecoveryEmails"
        :disabled="!can_sync"
        :loading="sync_form.processing"
        @click="sync_form.post('/admin/email/sync')"
      >
        {{ $t('orgEmail.syncButton') }}
      </va-button>
      <Link v-if="ldap_enabled && !can_sync" href="/admin/settings/ldap" class="ml-3">
        {{ $t('orgEmail.ldapSettingsLink') }}
      </Link>

      <template v-for="(result, index) in results" :key="result.key">
        <h6 :id="index === 0 ? 'syncResult' : undefined" class="va-h6 mt-4 mb-2">{{ $t('orgEmail.resultTitle') }}: {{ $t(result.title) }}</h6>
        <div class="mb-2">
          <va-badge color="success" :text="$t('orgEmail.resultUpdated', { count: result.data.updated })" class="mr-2" />
          <va-badge color="info" :text="$t('orgEmail.resultUnchanged', { count: result.data.unchanged })" />
        </div>
        <template v-if="result.data.missing.length > 0">
          <h6 class="va-h6 mt-3 mb-1">{{ $t('orgEmail.resultMissing') }}</h6>
          <ul>
            <li v-for="name in result.data.missing" :key="name">{{ name }}</li>
          </ul>
        </template>
        <template v-if="Object.keys(result.data.failed).length > 0">
          <h6 class="va-h6 mt-3 mb-1">{{ $t('orgEmail.resultFailed') }}</h6>
          <ul>
            <li v-for="(message, name) in result.data.failed" :key="name">{{ name }}: {{ message }}</li>
          </ul>
        </template>
        <template v-if="result.data.orphans.length > 0">
          <h6 class="va-h6 mt-3 mb-1">{{ $t('orgEmail.resultOrphans') }}</h6>
          <ul>
            <li v-for="name in result.data.orphans" :key="name">{{ name }}</li>
          </ul>
        </template>
      </template>
    </va-card-content>
  </va-card>
</template>

<script lang="ts">
export default {
  layout: (h, page) => h(AppLayout, [page])
}
</script>

<style></style>
