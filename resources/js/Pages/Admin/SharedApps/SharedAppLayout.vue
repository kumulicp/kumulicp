<script setup lang="ts">
import SharedAppSetupCard from './SharedAppSetupCard.vue'
import { Link, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'

// What every page of a shared app is drawn in, whichever of them it is: the
// pages of the shared app itself, of its app instance, and of its plan. The
// activation steps sit on top until it is activated, and the page's own card
// holds the tabs between them (see SharedAppTabs). A page that isn't a shared
// app's is left as it is.
const page = usePage()
const sharedApp = computed(() => page.props.shared_app as Record<string, any> | null)
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
  </template>
  <slot></slot>
</template>
