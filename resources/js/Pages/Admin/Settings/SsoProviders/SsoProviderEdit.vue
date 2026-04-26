<script setup lang="ts">
import AdminSettings from '@/components/AdminSettings.vue'
import { useForm } from '@inertiajs/vue3'

</script>
<template>
  <Head>
    <title>{{ $t('settings.sso.editSsoProvider') }} - Control Panel</title>
  </Head>
  <va-card>
    <va-card-title>{{ $t('settings.sso.editSsoProvider') }}</va-card-title>
    <va-card-content>
      <div class="row">
          <div class="flex xs12">
          <h5 class="va-h5 mt-0 pt-0"></h5>
          </div>
      </div>
      <form @submit.prevent="form.put('/admin/settings/sso-providers/'+provider.id)">
        <AdminSettings>
          <template #name></template>
          <template #settings>
            <va-checkbox v-model="form.enabled"
              immediateValidation
              id="enabled"
              required-mark
              :label="$t('status.enabled')"
              class="mb-3"
              :error="$page.props.errors.enabled"
              :error-messages="$page.props.errors.enabled" />
            <va-input v-model="form.name"
              immediateValidation
              id="name"
              required-mark
              :label="$t('form.name')"
              class="mb-3"
              :messages="$t('settings.sso.publicFacingLabel')"
              :error="$page.props.errors.name"
              :error-messages="$page.props.errors.name" />
            <va-input v-model="form.label"
              immediateValidation
              id="label"
              required-mark
              :label="$t('settings.sso.label')"
              class="mb-3"
              :messages="$t('settings.sso.publicFacingLabel')"
              :error="$page.props.errors.label"
              :error-messages="$page.props.errors.label" />
            <va-input v-model="form.client_id"
              immediateValidation
              id="client_id"
              required-mark
              :label="$t('settings.sso.clientId')"
              class="mb-3"
              :messages="$t('settings.sso.publicFacingLabel')"
              :error="$page.props.errors.client_id"
              :error-messages="$page.props.errors.client_id" />
            <va-input v-model="form.client_secret"
              immediateValidation
              id="client_secret"
              required-mark
              :label="$t('settings.sso.clientSecret')"
              class="mb-3"
              :messages="$t('settings.sso.publicFacingLabel')"
              :error="$page.props.errors.client_secret"
              :error-messages="$page.props.errors.client_secret" />
            <va-input v-model="form.base_url"
              immediateValidation
              id="base_url"
              required-mark
              :label="$t('settings.sso.baseUrl')"
              class="mb-3"
              :messages="$t('settings.sso.publicFacingLabel')"
              :error="$page.props.errors.base_url"
              :error-messages="$page.props.errors.base_url" />
            <va-input v-model="form.redirect_url"
              immediateValidation
              id="redirect_url"
              required-mark
              :label="$t('settings.sso.redirectUrl')"
              class="mb-3"
              :messages="$t('settings.sso.publicFacingLabel')"
              :error="$page.props.errors.redirect_url"
              :error-messages="$page.props.errors.redirect_url" />
            <va-input v-model="form.scopes"
              immediateValidation
              id="scopes"
              required-mark
              :label="$t('settings.sso.scopes')"
              class="mb-3"
              :messages="$t('settings.sso.publicFacingLabel')"
              :error="$page.props.errors.scopes"
              :error-messages="$page.props.errors.scopes" />
          </template>
        </AdminSettings>
        <va-button type="submit" id="submit" class="mr-2 mb-2" :disabled="form.processing">{{ $t('form.update') }}</va-button>
      </form>
    </va-card-content>
  </va-card>
</template>

<script lang="ts">
export default {
  props: {
    provider: Object,
    errors: Object
  },
  data () {
    return {
      form: useForm({
        name: this.provider.name,
        label: this.provider.label,
        client_id: this.provider.client_id,
        client_secret: this.provider.client_secret,
        base_url: this.provider.base_url,
        redirect_url: this.provider.redirect_url,
        scopes: this.provider.scopes,
        enabled: this.provider.enabled
      })
    }
  }
}
</script>
