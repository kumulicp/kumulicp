<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { Link, useForm } from '@inertiajs/vue3'

defineProps<{
  ldap_enabled: boolean
  aliases: { enabled: boolean, attribute: string | null }
  recovery: { attribute: string | null, sync_enabled: boolean, count: number }
  sync_result?: {
    checked: number
    updated: number
    unchanged: number
    missing: string[]
    failed: Record<string, string>
    orphans: string[]
  } | null
}>()

const sync_form = useForm({})
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

      <h6 class="va-h6 mb-2">{{ $t('orgEmail.recoveryTitle') }}</h6>
      <p class="mb-2">{{ $t('orgEmail.recoveryCount', { count: recovery.count }) }}</p>
      <p id="recoveryStatus" class="mb-3">
        <template v-if="!ldap_enabled">{{ $t('orgEmail.recoveryNoLdap') }}</template>
        <template v-else-if="!recovery.sync_enabled">{{ $t('orgEmail.recoveryNoAttribute') }}</template>
        <template v-else>{{ $t('orgEmail.recoveryAttribute', { attribute: recovery.attribute }) }}</template>
      </p>

      <va-button id="syncRecoveryEmails"
        :disabled="!recovery.sync_enabled"
        :loading="sync_form.processing"
        @click="sync_form.post('/admin/org/email/sync')"
      >
        {{ $t('orgEmail.syncButton') }}
      </va-button>
      <Link v-if="ldap_enabled && !recovery.sync_enabled" href="/admin/settings/ldap" class="ml-3">
        {{ $t('orgEmail.ldapSettingsLink') }}
      </Link>

      <template v-if="sync_result">
        <h6 id="syncResult" class="va-h6 mt-4 mb-2">{{ $t('orgEmail.resultTitle') }}</h6>
        <div class="mb-2">
          <va-badge color="success" :text="$t('orgEmail.resultUpdated', { count: sync_result.updated })" class="mr-2" />
          <va-badge color="info" :text="$t('orgEmail.resultUnchanged', { count: sync_result.unchanged })" />
        </div>
        <template v-if="sync_result.missing.length > 0">
          <h6 class="va-h6 mt-3 mb-1">{{ $t('orgEmail.resultMissing') }}</h6>
          <ul>
            <li v-for="username in sync_result.missing" :key="username">{{ username }}</li>
          </ul>
        </template>
        <template v-if="Object.keys(sync_result.failed).length > 0">
          <h6 class="va-h6 mt-3 mb-1">{{ $t('orgEmail.resultFailed') }}</h6>
          <ul>
            <li v-for="(message, username) in sync_result.failed" :key="username">{{ username }}: {{ message }}</li>
          </ul>
        </template>
        <template v-if="sync_result.orphans.length > 0">
          <h6 class="va-h6 mt-3 mb-1">{{ $t('orgEmail.resultOrphans') }}</h6>
          <ul>
            <li v-for="username in sync_result.orphans" :key="username">{{ username }}</li>
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
