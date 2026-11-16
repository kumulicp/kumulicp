<script setup lang="ts">
import { router } from '@inertiajs/vue3'

interface ReadinessAction {
  label: string
  url: string
  method: string
}

interface ReadinessItem {
  key: string
  blocking: boolean
  message: string
  actions: ReadinessAction[]
}

defineProps<{
  readiness: { status: string, items: ReadinessItem[] } | null
}>()

function run (action: ReadinessAction) {
  if (action.method === 'post') {
    router.post(action.url)
  } else {
    router.visit(action.url)
  }
}
</script>

<template>
  <va-alert
    v-if="readiness && readiness.items.length"
    id="app-readiness"
    :color="readiness.status === 'blocked' ? 'danger' : 'warning'"
    border="left"
    class="mb-4"
  >
    <div class="va-title mb-2">
      {{ readiness.status === 'blocked' ? $t('organization.discover.readiness.blockedTitle') : $t('organization.discover.readiness.reviewTitle') }}
    </div>
    <div
      v-for="item in readiness.items"
      :id="'readiness-' + item.key"
      :key="item.key"
      class="readiness-item mb-2"
    >
      <div class="mb-1">{{ item.message }}</div>
      <va-button
        v-for="action in item.actions"
        :key="action.url"
        size="small"
        preset="primary"
        class="mr-2"
        @click="run(action)"
      >
        {{ action.label }}
      </va-button>
    </div>
  </va-alert>
</template>
