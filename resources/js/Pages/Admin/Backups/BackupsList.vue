<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { Link, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>

<template>
  <Head>
    <title>{{ t('admin.backups.backups') }} - Control Panel</title>
  </Head>
  <div class="backups-list">
    <div class="row">
      <div class="flex flex-col xs12 lg12">
        <va-card class="mb-4">
          <va-card-title>{{ t('admin.backups.scheduledBackups') }}</va-card-title>
          <va-card-content>
            <table class="va-table va-table--hoverable mt-3">
              <thead>
                <tr>
                  <th>{{ t('organization.organization') }}</th>
                  <th>{{ t('admin.apps.appWord') }}</th>
                  <th>{{ t('admin.backups.type') }}</th>
                  <th>{{ t('admin.backups.scheduledAt') }}</th>
                  <th>{{ t('admin.backups.completedAt') }}</th>
                  <th>{{ t('admin.apps.status') }}</th>
                  <th style="width:50px"></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(backup, index) in backups.slice((curPageValue - 1), (curPageValue - 1 + pageSize))" :key="index">
                  <td>
                    <Link :href="'/admin/organizations/'+backup.organization.id">{{ backup.organization.name }}</Link>
                  </td>
                  <td>{{ backup.app.label }}</td>
                  <td>{{ backup.type }}</td>
                  <td>{{ backup.scheduled_at }}</td>
                  <td>{{ backup.completed_at }}</td>
                  <td>{{ backup.status }}</td>
                  <td class="va-text-center">
                    <va-icon name="entypo-cancel" color="danger" class="clickable-icon"
                      @click="showRemoveBackupModal(backup)" />
                  </td>
                </tr>
              </tbody>
            </table>
            <va-pagination v-if="backups.length > pageSize" class="mt-3 mb-3 justify-center" v-model="curPageValue" :total="backups.length" boundary-numbers :page-size="pageSize" />
          </va-card-content>
        </va-card>
        <va-modal v-model="showRemoveBackup" hide-default-actions :title="t('admin.backups.removeTitle', { name: removeBackup.name })"
          :message="t('admin.backups.removeMessage', { date: removeBackup.scheduled_at })">
          <template #footer="{ cancel }">
            <va-button color="backgroundSecondary" @click="cancel">
              {{ t('modal.cancel') }}
            </va-button>
            <va-button color="danger"
              @click="remove.delete('/admin/organizations/'+removeBackup.organization.id+'/backups/'+removeBackup.id); showRemoveBackup = !showRemoveBackup">{{ t('modal.delete') }}</va-button>
          </template>
        </va-modal>
      </div>
    </div>
  </div>
</template>

<script lang="ts">
export default {
  layout: (h, page) => h(AppLayout, [page]),
  props: {
    backups: Object,
    scheduler: Object,
    errors: Object
  },
  data () {
    return {
      curPageValue: 1,
      pageSize: 50,
      removeBackup: '',
      showRemoveBackup: false,
      remove: useForm({
        backup_scheduler_id: this.scheduler.id
      })
    }
  },
  methods: {
    showRemoveBackupModal (backup) {
      this.showRemoveBackup = true
      this.removeBackup = backup
    }
  }
}
</script>

<style lang="scss">
.row-equal .flex {
  .va-card {
    height: 100%;
  }
}

.backups-list {
  .va-card {
    margin-bottom: 0 !important;

    &__title {
      display: flex;
      justify-content: space-between;
    }
  }
}

.clickable-icon {
  transition: 0.3s;

  &:hover {
    opacity: 0.25;
    cursor: pointer;
  }
}
</style>
