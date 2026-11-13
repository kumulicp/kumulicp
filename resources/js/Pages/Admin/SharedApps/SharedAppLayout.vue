<script setup lang="ts">
import SharedAppSetupCard from './SharedAppSetupCard.vue'
import { Link, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

// What every page of a shared app is drawn in, whichever of them it is: the
// pages of the shared app itself, of its app instance, and of its plan. The
// activation steps sit on top until it is activated, and the tabs between
// those pages above the page's own card. A page that isn't a shared app's
// is left as it is.
const props = defineProps<{
  // The tab the page belongs to
  section: 'view' | 'instance' | 'plan'
}>()

const { t } = useI18n()
const page = usePage()
const sharedApp = computed(() => page.props.shared_app as Record<string, any> | null)

// An app instance's pages only mean something once it is deployed, and an
// externally managed app has no plan to set up: those tabs wait for them.
const tabs = computed(() => {
  const urls = sharedApp.value?.urls ?? {}

  return [
    { key: 'view', title: t('common.view'), url: urls.view },
    // All an externally managed app has to change is its domain
    { key: 'instance', title: t(sharedApp.value?.state === 'external' ? 'admin.sharedApps.tabDomain' : 'admin.sharedApps.tabInstance'), url: urls.instance },
    { key: 'plan', title: t('admin.sharedApps.tabPlan'), url: urls.plan }
  ].filter(tab => tab.url)
})
</script>

<template>
  <template v-if="sharedApp">
    <SharedAppSetupCard v-if="sharedApp.setup" :shared-app="sharedApp" />
    <va-alert v-if="sharedApp.update_required" id="updateRequired" color="info" icon="info" class="mb-4">
      <div class="flex items-center justify-between flex-wrap gap-2">
        <span>{{ $t('admin.sharedApps.updateRequired', { app: sharedApp.label }) }}</span>
        <Link :href="sharedApp.urls.edit">
          <va-button size="small" preset="secondary" border-color="info">{{ $t('admin.sharedApps.goToInstance') }}</va-button>
        </Link>
      </div>
    </va-alert>
    <va-tabs :model-value="props.section" hide-slider class="shared-app-tabs mb-4">
      <template #tabs>
        <Link v-for="tab in tabs" :key="tab.key" :href="tab.url">
          <va-tab :name="tab.key">{{ tab.title }}</va-tab>
        </Link>
      </template>
    </va-tabs>
  </template>
  <slot></slot>
</template>
