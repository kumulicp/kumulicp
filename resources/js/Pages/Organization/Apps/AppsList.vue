<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { Link, useForm, router } from '@inertiajs/vue3'

</script>

<template>
  <Head>
    <title>{{ $t('organization.apps.appsTitle') }} - Control Panel</title>
  </Head>
  <va-card class="mb-4">
    <va-card-title>{{ $t('organization.apps.appsTitle') }}</va-card-title>
    <va-card-content>
      <div v-if="$page.props.auth.status !== 'deactivated'" class="row justify-center">
        <Link href="/discover"><va-button class="">{{ $t('organization.apps.discoverMore') }}</va-button></Link>
      </div>
      <div class="va-table-responsive">
        <va-scroll-container
          color="primary"
          horizontal
        >
          <table class="va-table va-table--hoverable mt-3">
            <thead>
              <tr>
                <th style="width: 1%" class="va-text-center">{{ $t('status.enabled') }}</th>
                <th>{{ $t('admin.apps.appWord') }}</th>
                <th v-if="multiple_orgs">{{ $t('organization.organization') }}</th>
                <th v-if="$page.props.auth.status !== 'deactivated'" style="width: 30rem" class="va-text-right"></th>
                <th v-if="$page.props.auth.status !== 'deactivated'" style="width: 1%" class="va-text-center hidden xl:table-cell"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(app, i) in apps" :key="i">
                <td class="va-text-center" style="vertical-align: middle">
                  <va-icon
                      :name="'fa-' + status[app.status]"
                      :color="
                        app.status === 'active'
                          ? 'success'
                          : app.status === 'updating'
                          ? 'warning'
                          : app.status === 'deactivating' || app.status === 'deactivated'
                          ? 'danger'
                          : app.status === 'deleting'
                          ? 'danger'
                          : 'primary'
                      "
                      :spin="app.status == 'updating'"
                      :title="app.status"
                    />
                </td>
                <td class="vertical-middle">
                  <Link :href="'/apps/'+app.id+'/edit'" v-if="$page.props.auth.status !== 'deactivated' && !deactivated_statuses.includes(app.status)">
                    {{ app.name }}
                  </Link>
                  <div v-else>
                    {{ app.name }}
                  </div>
                  <va-list-item-label caption v-for="(task, index) in app.tasks" :key="index">
                    {{ task.description }}
                  </va-list-item-label>
                </td>
                <td v-if="multiple_orgs" class="vertical-middle">
                  {{ app.organization.name }}
                </td>
                <td class="va-text-right vertical-middle hidden xl:table-cell">
                  <template v-if="$page.props.auth.status !== 'deactivated' && !deactivated_statuses.includes(app.status)">
                    <a v-if="app.address && app.has_admin_address" :href="app.address" target="_blank">
                      {{ $t('organization.apps.visitWebsite') }}
                    </a>
                    <a v-else-if="app.address && ! app.has_admin_address" :href="app.address" target="_blank">
                      {{ $t('organization.apps.loginToApp') }}
                    </a>
                    <a v-if="app.has_admin_address" :href="app.admin_address" class="ml-3" target="_blank">
                      {{ $t('organization.apps.loginToApp') }}
                    </a>
                  </template>
                </td>
                <td class="va-text-center vertical-middle hidden xl:table-cell">
                  <va-button
                    color="danger"
                  :disabled="deactivated_statuses.includes(app.status)"
                  @click="deactivateApp(app)">
                  {{ $t('plan.deactivate') }}
                  </va-button>
                </td>
              <td class="xl:hidden va-text-right">
                <va-button-dropdown
                  id="actions"
                  :label="$t('organization.apps.actions')"
                >
                  <a v-if="app.address && app.has_admin_address" :href="app.address" target="_blank" class="mr-3"><div class="py-1">{{ $t('organization.apps.visitWebsite') }}</div></a>
                  <a v-else-if="app.address && ! app.has_admin_address" :href="app.address" target="_blank" class="mr-3"><div class="py-1">{{ $t('organization.apps.loginToApp') }}</div></a>
                  <a v-if="app.has_admin_address" :href="app.admin_address" target="_blank" class="mr-3"><div class="py-1">{{ $t('organization.apps.loginToApp') }}</div></a>
                  <a href="#" @click="deactivateApp(app)"><div class="py-1" color="danger">{{ $t('modal.deactivate') }}</div></a>
                </va-button-dropdown>
              </td>
              </tr>
            </tbody>
          </table>
        </va-scroll-container>
      </div>
    </va-card-content>
  </va-card>
  <va-modal v-model="showDeactivateModal" hide-default-actions>
    <template #content>
      <va-card-title class="m-0"> {{ $t('organization.apps.deactivateTitle', { name: appToDeactivate.name }) }} </va-card-title>
      <va-card-content class="m-0">
        <p class="va-p mb-2">{{ $t('messages.deactivate', {app: appToDeactivate.name}) }}</p>
        <p class="va-p mb-2 va-text-bold">{{ $t('messages.typeToDeactivate', {app: appToDeactivate.name}) }}</p>
        <va-input v-model="deactivateName" required-mark class="mt-3" />
      </va-card-content>
      <va-card-actions align="right" class="">
        <va-button color="backgroundSecondary" @click="showDeactivateModal = false">
          {{ $t('common.cancel') }}
        </va-button>
        <va-button id="deactivate" color="danger"
          :disabled="deactivateName !== appToDeactivate.name"
          @click="deactivate.delete('/apps/'+appToDeactivate.id); showDeactivateModal = !showDeactivateModal">
            {{ $t('plan.deactivate') }}
        </va-button>
      </va-card-actions>
    </template>
  </va-modal>
</template>

<script lang="ts">
export default {
  layout: (h, page) => h(AppLayout, [page]),
  props: {
    apps: Object,
    multiple_orgs: Boolean
  },
  data () {
    return {
      deactivated_statuses: ['activating', 'deactivated', 'deactivating', 'deleting', 'updating'],
      showDeactivateModal: false,
      appToDeactivate: {
        name: ''
      },
      deactivateName: '',
      status: {
        active: 'check-circle',
        activating: 'rocket',
        updating: 'spinner',
        deactivating: 'explosion',
        deactivated: 'ban',
        deleting: 'trash'
      },
      deactivate: useForm({}),
      interval: ''
    }
  },
  mounted () {
    this.interval = setInterval(this.updateApps, 30000)
  },
  unmounted () {
    clearInterval(this.interval)
  },
  methods: {
    deactivateApp (app) {
      this.showDeactivateModal = true
      this.appToDeactivate = app
    },
    updateApps () {
      router.reload({ only: ['apps'] })
    }
  }
}
</script>

<style lang="scss">
</style>
