<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import SharedAppLayout from './SharedAppLayout.vue'
import { Link } from '@inertiajs/vue3'
import { computed } from 'vue'

const props = defineProps<{
  app: {
    id: number
    organization_id: number
    label: string
    name: string
    state: 'setup' | 'external' | 'active'
    status: string
    version: string | null
    domain: string
    domain_chosen: boolean
  }
}>()

const isActive = computed(() => props.app.state === 'active')
</script>

<script lang="ts">
export default {
  layout: (h, page) => h(AppLayout, () => h(SharedAppLayout, { section: 'view' }, () => page))
}
</script>

<template>
  <Head>
    <title>{{ app.label }} - Control Panel</title>
  </Head>
  <va-card class="mb-4">
    <va-card-title>{{ app.label }}</va-card-title>
    <va-card-content>
      <va-alert v-if="app.state === 'setup'" id="setupInfo" color="warning" icon="warning" class="mb-4">
        {{ $t('admin.sharedApps.setupInfo', { app: app.name }) }}
      </va-alert>
      <va-alert v-else-if="app.state === 'external'" id="externalInfo" color="info" icon="info" class="mb-4">
        {{ $t('admin.sharedApps.externalInfo', { app: app.name }) }}
      </va-alert>

      <va-list>
        <va-list-item class="py-3">
          <va-list-item-section label>
            <va-list-item-label><h5>{{ $t('admin.sharedApps.application') }}</h5></va-list-item-label>
          </va-list-item-section>
          <va-list-item-section>
            <va-list-item-label id="application">{{ app.name }}</va-list-item-label>
          </va-list-item-section>
        </va-list-item>
        <va-list-separator class="my-1" fit />

        <va-list-item class="py-3">
          <va-list-item-section label>
            <va-list-item-label><h5>{{ $t('admin.sharedApps.status') }}</h5></va-list-item-label>
          </va-list-item-section>
          <va-list-item-section>
            <va-list-item-label id="status">
              <template v-if="app.state === 'setup'">
                <va-badge color="warning" :text="$t('admin.sharedApps.statusNeedsActivation')" />
              </template>
              <template v-else-if="app.state === 'external'">
                <va-badge color="info" :text="$t('admin.sharedApps.statusExternal')" />
              </template>
              <template v-else>
                {{ app.status }}
              </template>
            </va-list-item-label>
          </va-list-item-section>
        </va-list-item>
        <va-list-separator class="my-1" fit />

        <template v-if="isActive">
          <va-list-item class="py-3">
            <va-list-item-section label>
              <va-list-item-label><h5>{{ $t('admin.sharedApps.version') }}</h5></va-list-item-label>
            </va-list-item-section>
            <va-list-item-section>
              <va-list-item-label id="version">{{ app.version }}</va-list-item-label>
            </va-list-item-section>
          </va-list-item>
          <va-list-separator class="my-1" fit />
        </template>

        <va-list-item v-if="app.state !== 'setup'" class="py-3">
          <va-list-item-section label>
            <va-list-item-label><h5>{{ $t('admin.sharedApps.domain') }}</h5></va-list-item-label>
          </va-list-item-section>
          <va-list-item-section>
            <va-list-item-label id="domain">
              {{ app.domain }}
              <p v-if="!app.domain_chosen" id="noDomain" class="text-color-danger">
                {{ $t('admin.sharedApps.noDomainChosen') }}
                <Link :href="'/admin/organizations/' + app.organization_id + '/apps/' + app.id + '/edit'">{{ $t('common.edit') }}</Link>
              </p>
            </va-list-item-label>
          </va-list-item-section>
        </va-list-item>
      </va-list>
    </va-card-content>
  </va-card>
</template>
