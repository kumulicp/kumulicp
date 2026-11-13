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
  section: 'view' | 'settings' | 'instance' | 'plan'
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
    { key: 'settings', title: t('admin.sharedApps.settings'), url: urls.settings },
    { key: 'instance', title: t('common.edit'), url: urls.instance },
    { key: 'plan', title: t('admin.plans.plan'), url: urls.plan }
  ].filter(tab => tab.url)
})
</script>

<template>
  <template v-if="sharedApp">
    <SharedAppSetupCard v-if="sharedApp.setup" :shared-app="sharedApp" />
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
