<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

// The tabs between every page of a shared app: the app itself, its app
// instance and its plan. They stand in for the tabs those pages have when
// they aren't a shared app's. Editing the chart values and viewing what they
// produce sit side by side. A page only has a tab once it means something:
// the server leaves out the URL of one that doesn't yet.
const { t } = useI18n()
const page = usePage()
const sharedApp = computed(() => page.props.shared_app as Record<string, any> | null)

const tabs = computed(() => {
  const urls = sharedApp.value?.urls ?? {}

  return [
    { title: t('admin.sharedApps.tabOverview'), url: urls.view },
    // All an externally managed app has to change is its domain
    { title: t(sharedApp.value?.state === 'external' ? 'admin.sharedApps.tabDomain' : 'admin.sharedApps.tabEditInstance'), url: urls.edit },
    { title: t('admin.sharedApps.tabPlan'), url: urls.plan },
    { title: t('admin.plans.serverSettings'), url: urls.plan_edit },
    { title: t('admin.plans.features'), url: urls.plan_features },
    { title: t('admin.plans.helmChartValues'), url: urls.plan_configurations },
    { title: t('admin.apps.helmValues'), url: urls.helm_values },
    { title: t('admin.plans.secrets'), url: urls.plan_secrets }
  ].filter(tab => tab.url)
})

const current = computed(() => {
  const path = page.url.split('?')[0]
  return tabs.value.find(tab => tab.url === path)?.url
})
</script>

<template>
  <template v-if="sharedApp">
    <va-tabs :model-value="current" hide-slider>
      <template #tabs>
        <Link v-for="tab in tabs" :key="tab.url" :href="tab.url">
          <va-tab :name="tab.url">{{ tab.title }}</va-tab>
        </Link>
      </template>
    </va-tabs>
    <va-separator />
  </template>
</template>
