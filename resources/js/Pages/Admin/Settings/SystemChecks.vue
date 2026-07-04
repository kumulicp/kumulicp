<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import SettingsLayout from './SettingsLayout.vue'
import { useForm } from '@inertiajs/vue3'

</script>
<template>
  <Head>
    <title>{{ $t('settings.systemChecks') }} - Control Panel</title>
  </Head>
  <va-card outlined>
    <va-card-title>{{ $t('systemChecks.ldapModelValidation') }}</va-card-title>
    <va-card-content>
      <p class="mb-3">{{ $t('systemChecks.ldapModelValidationDescription') }}</p>
      <va-button id="runLdapModelValidation"
        :loading="runForm.processing"
        @click="runForm.post('/admin/settings/system-checks/ldap-models')"
      >
        {{ $t('systemChecks.runCheck') }}
      </va-button>

      <template v-if="ldap_model_results">
        <va-alert v-if="ldap_model_results.issues.length === 0" color="success" class="mt-3">
          {{ $t('systemChecks.ldapModelValidationPassed') }}
        </va-alert>
        <template v-else>
          <va-alert color="warning" class="mt-3">
            {{ $t('systemChecks.ldapModelValidationFailed', { count: ldap_model_results.issues.length }) }}
          </va-alert>

          <va-button id="correctLdapModels"
            class="mt-3"
            preset="secondary"
            :loading="correctForm.processing"
            @click="correctForm.post('/admin/settings/system-checks/ldap-models/correct')"
          >
            {{ $t('systemChecks.correctEntries') }}
          </va-button>

          <template v-if="ldap_model_results.corrections">
            <h6 class="va-h6 mt-3 mb-1">{{ $t('systemChecks.correctionResults') }}</h6>
            <va-scroll-container color="primary" horizontal>
              <table class="va-table va-table--hoverable mt-1">
                <thead>
                  <tr>
                    <th>{{ $t('systemChecks.dn') }}</th>
                    <th>{{ $t('systemChecks.status') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(correction, i) in ldap_model_results.corrections" :key="i">
                    <td>{{ correction.dn }}</td>
                    <td>
                      <va-badge v-if="correction.fixed" color="success" :text="$t('systemChecks.correctionFixed')" />
                      <template v-else>
                        <va-badge color="danger" :text="$t('systemChecks.correctionNotFixed')" />
                        <div class="mt-1">{{ correction.message }}</div>
                      </template>
                    </td>
                  </tr>
                </tbody>
              </table>
            </va-scroll-container>
          </template>

          <h6 class="va-h6 mt-3 mb-1">{{ $t('systemChecks.problemEntries') }}</h6>
          <va-scroll-container color="primary" horizontal>
            <table class="va-table va-table--hoverable mt-1">
              <thead>
                <tr>
                  <th>{{ $t('systemChecks.dn') }}</th>
                  <th>{{ $t('systemChecks.objectClasses') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(issue, i) in ldap_model_results.issues" :key="i">
                  <td>{{ issue.dn }}</td>
                  <td>{{ issue.object_classes.join(', ') }}</td>
                </tr>
              </tbody>
            </table>
          </va-scroll-container>
        </template>
      </template>
    </va-card-content>
  </va-card>
</template>

<script lang="ts">
export default {
  layout: (h, page) => {
    return h(AppLayout, () => h(SettingsLayout, () => page))
  },
  props: {
    ldap_model_results: Object
  },
  data () {
    return {
      runForm: useForm({}),
      correctForm: useForm({})
    }
  }
}
</script>

<style></style>
