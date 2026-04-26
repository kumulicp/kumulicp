<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import AppsLayout from './AppsLayout.vue'
import AdminSettings from '@/components/AdminSettings.vue'
import TinymceEditor from '@/components/FormInputs/TinymceEditor.vue'
import { useForm } from '@inertiajs/vue3'
</script>
<template>
  <Head>
    <title>{{ $t('admin.apps.editPageTitle') }} - Control Panel</title>
  </Head>
  <div class="row justify-center">
    <va-button class="" @click="showEnableDisable = !showEnableDisable">{{ app.toggle.label }}</va-button>
  </div>
  <va-modal v-model="showEnableDisable"
    :title="app.toggle.label+' '+$t('admin.apps.appWord')"
    hide-default-actions
    >
    <template #default>
        <div v-if="app.toggle.state == 'disable'">
          {{ $t('admin.apps.disableWarning') }}
        </div>
        <div v-if="app.toggle.state == 'enable'">
          <div v-if="!app.default_version.id">
            <p class="mb-3">{{ $t('admin.apps.noDefaultVersion') }}</p>
            <va-select v-model="toggle.version"
              :options="app.versions"
              value-by="id"
              text-by="version"
              class="mb-3"
              immediateValidation
              :error="$page.props.errors.version"
              :error-messages="$page.props.errors.version"
            />
          </div>
          {{ $t('admin.apps.enableWarning') }}
        </div>
      </template>
      <template #footer="{ ok }">
        <va-button color="textInverted" :disabled="form.processing" @click="ok">{{ $t('modal.cancel') }}</va-button>
        <va-button @click="toggle.post('/admin/apps/'+app.slug+'/'+app.toggle.state, {
            onSuccess: () => showEnableDisable = false
          })"
          :disabled="form.processing" class="mr-2 mb-2">{{ app.toggle.label }}</va-button>
    </template>
  </va-modal>
  <form @submit.prevent="form.post('/admin/apps/'+app.slug)">
    <AdminSettings>
      <template #name>{{ $t('admin.apps.aboutApp') }}</template>
      <template #settings>
        <va-input v-model="form.name"
          :label="$t('admin.apps.name')"
          :messages="$t('admin.apps.nameMessage')"
          class="my-2"
          id="name"
          required-mark
          immediateValidation
          :error="$page.props.errors.name"
          :error-messages="$page.props.errors.name"
          />
        <va-input v-model="form.category"
          :label="$t('admin.apps.category')"
          :messages="$t('admin.apps.categoryMessage')"
          id="category"
          class="my-2"
          required-mark
          immediateValidation
          :error="$page.props.errors.category"
          :error-messages="$page.props.errors.category"
          />
        <VaFileUpload
          v-model="form.image"
          :label="$t('admin.apps.image')"
          :messages="$t('admin.apps.imageMessage')"
          immediateValidation
          type="single"
          class="my-2"
          dropzone
          file-types="image/*"
          :error="$page.props.errors.image"
          :error-messages="$page.props.errors.image"
        />
        <va-textarea
          v-model="form.short_description"
          :label="$t('admin.apps.shortDescription')"
          :messages="$t('admin.apps.shortDescriptionMessage')"
          immediateValidation
          id="shortDescription"
          class="my-2 full-width"
          min-rows="5"
          max-length="125"
          counter
          autosize
          :error="$page.props.errors.short_description"
          :error-messages="$page.props.errors.short_description"
          />
      </template>
    </AdminSettings>
    <va-list-separator class="my-1" fit />
    <AdminSettings>
      <template #name>{{ $t('admin.apps.settings') }}</template>
      <template #settings>
        <va-checkbox v-model="form.primary_domain_allowed"
          :label="$t('admin.apps.primaryDomainAllowed')"
          :messages="$t('admin.apps.primaryDomainAllowedMessage')"
          class="my-2"
          immediateValidation
          :error="$page.props.errors.primary_domain_allowed"
          :error-messages="$page.props.errors.primary_domain_allowed"
          />
        <va-checkbox v-model="form.can_update_domain"
          :label="$t('admin.apps.canUpdateDomain')"
          :messages="$t('admin.apps.canUpdateDomainMessage')"
          immediateValidation
          :error="$page.props.errors.can_update_domain"
          :error-messages="$page.props.errors.can_update_domain"
          />
        <va-select v-model="form.domain_option"
          :label="$t('admin.apps.domainTypeAllowed')"
          :messages="$t('admin.apps.domainTypeAllowedMessage')"
          class="my-2"
          immediateValidation
          :options="domain_options"
          value-by="value"
          text-by="text"
          :error="$page.props.errors.domain_option"
          :error-messages="$page.props.errors.domain_option"
          />
        <va-select
          v-model="form.parent_app"
          :label="$t('admin.apps.parentApp')"
          :messages="$t('admin.apps.parentAppMessage')"
          immediateValidation
          id="parentApp"
          class="my-2"
          :options="apps"
          text-by="text"
          value-by="value"
          :placeholder="$t('admin.versions.none')"
          clearable
          :error="$page.props.errors.parent_app"
          :error-messages="$page.props.errors.parent_app"
        />
        <va-select
          v-model="form.access_type"
          :label="$t('admin.apps.accessType')"
          :messages="$t('admin.apps.accessTypeMessage')"
          immediateValidation
          id="accessType"
          class="my-2"
          :options="access_types"
          text-by="text"
          value-by="value"
          :error="$page.props.errors.access_type"
          :error-messages="$page.props.errors.access_type"
        />
      </template>
    </AdminSettings>
    <h4 class="va-h4">{{ $t('admin.apps.description') }}</h4>
    <div>
      {{ errors.description }}
    </div>
    <div class="mb-3">
      <tinymce-editor v-model:htmlContent="form.description" />
    </div>
    <va-button type="submit" id="submit" class="mr-2 mb-2" :disabled="form.processing">{{ $t('form.update') }}</va-button>
  </form>
</template>

<script lang="ts">
export default {
  layout: (h, page) => {
    return h(AppLayout, () => h(AppsLayout, () => page))
  },
  props: {
    app: Object,
    apps: Object,
    errors: Object
  },
  data () {
    return {
      showEnableDisable: false,
      form: useForm({
        id: this.app.id,
        name: this.app.name,
        category: this.app.category,
        image: this.app.image,
        parent_app: this.app.parent_app.id,
        access_type: this.app.access_type,
        primary_domain_allowed: this.app.primary_domain_allowed,
        short_description: this.app.short_description,
        description: this.app.description,
        domain_option: this.app.domain_option,
        can_update_domain: this.app.can_update_domain
      }),
      toggle: useForm({
        version: ''
      }),
      access_types: [
          { value: 'minimal', text: this.$t('admin.apps.accessTypeMinimal') },
          { value: 'basic', text: this.$t('admin.apps.accessTypeBasic') },
          { value: 'standard', text: this.$t('admin.apps.accessTypeStandard') }
      ],
      domain_options: [
          { value: 'none', text: this.$t('admin.apps.domainOptionNone') },
          { value: 'all', text: this.$t('admin.apps.domainOptionAll') },
          { value: 'subdomains', text: this.$t('admin.apps.domainOptionSubdomains') },
          { value: 'primary', text: this.$t('admin.apps.domainOptionPrimary') },
          { value: 'base', text: this.$t('admin.apps.domainOptionBase') },
          { value: 'parent', text: this.$t('admin.apps.domainOptionParent') }
        ]
    }
  }
}
</script>

<style>
.full-width {
  width: 100%
}
</style>
