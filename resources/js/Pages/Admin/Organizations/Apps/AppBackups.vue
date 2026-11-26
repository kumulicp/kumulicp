<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import AppsLayout from './AppsLayout.vue'

</script>
<template>
  <Head>
    <title>{{ app.name }} {{ $t('admin.backups.backups') }} - Control Panel</title>
  </Head>
  <div class="row justify-center items-center">
    <va-button :disabled="!can_backup" @click="showRun = true">{{ $t('admin.backups.runNow') }}</va-button>
    <p v-if="!can_backup" class="ml-3 mb-0">{{ $t('admin.backups.runNowDisabled') }}</p>
  </div>

  <va-scroll-container color="primary" horizontal>
    <table class="va-table va-table--hoverable mt-3">
      <thead>
        <tr>
          <th>{{ $t('admin.backups.action') }}</th>
          <th>{{ $t('admin.backups.scheduledAt') }}</th>
          <th>{{ $t('admin.backups.name') }}</th>
          <th>{{ $t('admin.backups.type') }}</th>
          <th>{{ $t('admin.backups.status') }}</th>
          <th>{{ $t('admin.backups.completedAt') }}</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="backup in backups" :key="backup.id">
          <td>{{ backup.action }}</td>
          <td>{{ backup.scheduled_at }}</td>
          <td>{{ backup.name }}</td>
          <td>{{ backup.type }}</td>
          <td>{{ backup.status }}</td>
          <td>{{ backup.completed_at }}</td>
          <td class="justify-center" style="width:50px; text-align: center">
            <va-icon v-if="backup.action === 'backup' && backup.status === 'completed'" color="primary" name="entypo-ccw" class="clickable-icon"
              :aria-label="$t('admin.backups.restore')" @click="showRestoreModal(backup)" />
          </td>
        </tr>
        <tr v-if="!backups.length">
          <td colspan="7" class="text-center">{{ $t('admin.backups.noBackups') }}</td>
        </tr>
      </tbody>
    </table>
  </va-scroll-container>

  <va-pagination v-if="meta.pages > 1" class="mt-3 mb-3 justify-center" :model-value="meta.page" :pages="meta.pages" :direction-links="false" @update:modelValue="goToPage" />

  <va-modal v-model="showRun" no-padding size="small" class="p-0">
    <template #content>
      <va-card-title class="m-0"> {{ $t('admin.backups.runNowTitle') }} </va-card-title>
      <va-card-content class="m-0">
        {{ $t('admin.backups.runNowConfirm', { org: organization.name, app: app.name }) }}
        <va-input
          v-model="keep_for"
          type="number"
          class="mt-3"
          :label="$t('admin.backups.keepForDays')"
        />
      </va-card-content>
      <va-card-actions align="right" class="">
        <va-button color="textInverted" @click="showRun = false">{{ $t('common.cancel') }}</va-button>
        <va-button class="mr-2 mb-2" @click="runBackup()">{{ $t('admin.backups.runNow') }}</va-button>
      </va-card-actions>
    </template>
  </va-modal>

  <va-modal v-model="showRestore" no-padding size="small" class="p-0">
    <template #content>
      <va-card-title class="m-0"> {{ $t('admin.backups.restoreBackup') }} </va-card-title>
      <va-card-content class="m-0">
        {{ $t('admin.backups.restoreConfirmApp', { app: app.name, scheduled: selected_backup?.scheduled_at }) }}
        <template v-if="database_servers.length > 0">
          <va-select
            v-model="restore_to"
            class="mt-3"
            :label="$t('admin.backups.restoreTo')"
            :options="restore_to_options"
            text-by="text"
            value-by="value"
          />
          <p v-if="restore_to !== 'original'" class="mt-2">{{ $t('admin.backups.restoreToNote') }}</p>
        </template>
      </va-card-content>
      <va-card-actions align="right" class="">
        <va-button color="textInverted" @click="showRestore = false">{{ $t('common.cancel') }}</va-button>
        <va-button class="mr-2 mb-2" @click="restoreBackup()">{{ $t('admin.backups.restore') }}</va-button>
      </va-card-actions>
    </template>
  </va-modal>
</template>

<script lang="ts">
// Backups settle on their own, once the backup runner has been through them
const SETTLED = ['completed', 'failed', 'deleted', 'no_driver', 'no_backup_driver']
const POLL_MS = 10000

export default {
  layout: (h, page) => {
    return h(AppLayout, () => h(AppsLayout, () => page))
  },
  props: {
    app: Object,
    organization: Object,
    backups: Array,
    can_backup: Boolean,
    keep_days: Number,
    database_servers: {
      type: Array,
      default: () => []
    },
    meta: Object
  },
  data () {
    return {
      showRun: false,
      showRestore: false,
      keep_for: this.keep_days,
      selected_backup: null,
      restore_to: 'original',
      poller: null
    }
  },
  computed: {
    base () {
      return '/admin/organizations/' + this.organization.id + '/apps/' + this.app.id + '/backups'
    },
    restore_to_options () {
      return [{ value: 'original', text: this.$t('admin.backups.restoreToOriginal') }, ...this.database_servers]
    },
    unsettled () {
      return this.backups.some(backup => !SETTLED.includes(backup.status))
    }
  },
  mounted () {
    this.poller = setInterval(() => {
      if (this.unsettled) {
        this.$inertia.reload({ only: ['backups', 'meta'] })
      }
    }, POLL_MS)
  },
  beforeUnmount () {
    clearInterval(this.poller)
  },
  methods: {
    runBackup () {
      this.showRun = false
      this.$inertia.post(this.base, { keep_for: this.keep_for })
    },
    showRestoreModal (backup) {
      this.selected_backup = backup
      this.restore_to = 'original'
      this.showRestore = true
    },
    restoreBackup () {
      this.showRestore = false
      this.$inertia.post(this.base + '/' + this.selected_backup.id + '/restore', {
        database_server_id: this.restore_to === 'original' ? null : this.restore_to
      })
    },
    goToPage (page) {
      this.$inertia.get(this.base, { page }, { preserveState: true })
    }
  }
}
</script>

<style lang="scss">
.clickable-icon {
  transition: 0.3s;

  &:hover {
    opacity: 0.25;
    cursor: pointer;
  }
}
</style>
