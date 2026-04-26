<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import AdminSettings from '@/components/AdminSettings.vue'
import { useForm } from '@inertiajs/vue3'

</script>

<template>
  <Head>
    <title>{{ t('admin.sharedApps.appSettings') }} - Control Panel</title>
  </Head>
  <va-card class="mb-4">
    <va-card-title>{{ t('admin.sharedApps.appLabelSettings', { label: app.label }) }} </va-card-title>
    <va-card-content>
      <form @submit.prevent="form.put('/admin/service/shared-apps/'+app.id, {onSuccess: () => appUpdated()})">
        <AdminSettings>
          <template #name>{{ t('admin.sharedApps.aboutApp') }}</template>
          <template #settings>
            <va-select
              v-model="form.domain"
              :label="t('admin.sharedApps.domain')"
              :options="domains"
              text-by="name"
              value-by="id"
              placement="auto"
              class="mb-3"
              immediateValidation
              :error="$page.props.errors.domain"
              :error-messages="$page.props.errors.domain"
            />
            <template v-if="form.domain == 'new'">
              <va-input v-model="form.subdomain"
                :label="t('admin.sharedApps.subdomain')"
                v-if="parent_domains.length > 0"
                immediateValidation
                :error="$page.props.errors.subdomain"
                :error-messages="$page.props.errors.subdomain"
                class="mb-3"
                :placeholder="t('admin.sharedApps.subdomainPlaceholder')"
              >
                <template #append>
                  <va-select
                    v-model="form.parent_domain"
                    :options="parent_domains"
                    text-by="name"
                    value-by="id"
                    immediateValidation
                    :error="$page.props.errors.parent_domain"
                    :error-messages="$page.props.errors.parent_domain"
                    :placeholder="t('admin.sharedApps.primaryDomainPlaceholder')"
                  >
                    <template #prepend>
                      <div class="mx-1">.</div>
                    </template>
                  </va-select>
                </template>
              </va-input>
              <p v-else class="text-color-danger">
                {{ t('admin.sharedApps.noDomainsWarning') }}
              </p>
            </template>
            <va-select
              v-model="form.version"
              :label="t('admin.sharedApps.version')"
              :options="versions"
              text-by="name"
              value-by="id"
              placement="auto"
              class="mb-3"
              immediateValidation
              :error="$page.props.errors.version"
              :error-messages="$page.props.errors.version"
            />
            <va-select
              v-model="form.plan"
              :label="t('admin.sharedApps.plan')"
              :options="plans"
              text-by="name"
              value-by="id"
              placement="auto"
              class="mb-3"
              immediateValidation
              :error="$page.props.errors.plan"
              :error-messages="$page.props.errors.plan"
            />
          </template>
        </AdminSettings>
        <div class="row">
          <div class="flex flex-col xs12">
            <div>
              <va-button type="submit"
                id="submit"
                :disabled="form.processing"
              >
                {{ t('common.update') }}
              </va-button>
            </div>
          </div>
        </div>
      </form>
    </va-card-content>
  </va-card>
</template>

<script lang="ts">
export default {
  layout: (h, page) => h(AppLayout, [page]),
  props: {
    app: Object,
    domains: Object,
    errors: Object,
    versions: Object,
    plans: Object,
    parent_domains: Object
  },
  data () {
    return {
      form: useForm({
        domain: this.app.domain,
        label: this.app.label,
        parent_domain: null,
        subdomain: '',
        plan: this.app.plan,
        version: this.app.version
      })
    }
  },
  methods: {
    appUpdated () {
      this.form.domain = this.app.domain
      this.form.subdomain = ''
      this.form.parent_domain = null
    }
  }
}
</script>

<style></style>
