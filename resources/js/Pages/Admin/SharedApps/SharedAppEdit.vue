<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import AdminSettings from '@/components/AdminSettings.vue'
import SharedAppDomainFields from './SharedAppDomainFields.vue'
import { useForm } from '@inertiajs/vue3'

const props = defineProps<{
  app: { id: number, label: string, domain: number }
  domains: Array<{ id: number | string, name: string }>
  parent_domains: Array<{ id: number, name: string }>
}>()

const form = useForm({
  label: props.app.label,
  domain: props.app.domain as number | string,
  parent_domain: null as number | null,
  subdomain: ''
})

const reset = () => {
  form.domain = props.app.domain
  form.subdomain = ''
  form.parent_domain = null
}
</script>

<script lang="ts">
import SharedAppLayout from './SharedAppLayout.vue'

export default {
  layout: (h, page) => h(AppLayout, () => h(SharedAppLayout, { section: 'settings' }, () => page))
}
</script>

<template>
  <Head>
    <title>{{ $t('admin.sharedApps.appSettings') }} - Control Panel</title>
  </Head>
  <va-card class="mb-4">
    <va-card-content>
      <form @submit.prevent="form.put('/admin/service/shared-apps/' + app.id, { onSuccess: reset })">
        <AdminSettings>
          <template #name>{{ $t('admin.sharedApps.aboutApp') }}</template>
          <template #settings>
            <va-input
              v-model="form.label"
              id="label"
              required-mark
              :label="$t('admin.sharedApps.label')"
              class="mb-3"
              immediateValidation
              :error="!!form.errors.label"
              :error-messages="form.errors.label"
            />
            <SharedAppDomainFields
              v-model:domain="form.domain"
              v-model:subdomain="form.subdomain"
              v-model:parent-domain="form.parent_domain"
              :domains="domains"
              :parent-domains="parent_domains"
            />
          </template>
        </AdminSettings>
        <div class="row">
          <div class="flex flex-col xs12">
            <div>
              <va-button type="submit" id="submit" :disabled="form.processing">
                {{ $t('common.update') }}
              </va-button>
            </div>
          </div>
        </div>
      </form>
    </va-card-content>
  </va-card>
</template>
