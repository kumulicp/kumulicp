<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import BackupsLayout from './BackupsLayout.vue'
import { Link, useForm, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>

<template>
  <Head>
    <title>Backups - Control Panel</title>
  </Head>
    <div class="row justify-center">
      <va-button @click="showAddBackup = !showAddBackup">Add Backup</va-button>
      <va-modal v-model="showAddBackup" no-outside-dismiss no-padding>
        <template #content="{ ok }">
          <form @submit.prevent="form.post('/admin/server/backup_scheduler', {onSuccess: () => backupScheduled()})">
            <va-card-title>Add Backup</va-card-title>
            <va-card-content>
              <va-date-input v-model="form.date"
                required-mark
                immediateValidation
                label="Date"
                class="w-48 mb-3"
                :error="$page.props.errors.date"
                :error-messages="$page.props.errors.date"
                @update:modelValue="updateDateTime()"
                />
              <va-time-input v-model="form.time"
                class="w-28 mb-3"
                required-mark
                immediateValidation
                label="Time"
                :error="$page.props.errors.time"
                :error-messages="$page.props.errors.time"
                @update:modelValue="updateDateTime()"
                />
              <va-input v-model="form.keep_for"
                required-mark
                immediateValidation
                type="number"
                max="120"
                min="1"
                label="Keep For"
                class="mb-3"
                :error="$page.props.errors.keep_for"
                :error-messages="$page.props.errors.keep_for"
                >
                <template #appendInner>
                  days
                </template>
              </va-input>
              <va-select v-model="form.server"
                :options="servers"
                class="mb-3"
                required-mark
                immediateValidation
                label="Server"
                value-by="id"
                text-by="name"
                :error-messages="$page.props.errors.server"
                :error="$page.props.errors.server"
                />
              <va-select v-model="form.organization"
                :options="organizations"
                class="mb-3"
                label="Organizations"
                immediateValidation
                value-by="id"
                text-by="name"
                :error-messages="$page.props.errors.organization"
                :error="$page.props.errors.organization"
                />
              <va-select v-model="form.application"
                :options="applications"
                class="mb-3"
                label="Application"
                immediateValidation
                value-by="id"
                text-by="name"
                :error-messages="$page.props.errors.application"
                :error="$page.props.errors.application"
                />
            </va-card-content>
            <va-card-actions align="right">
              <va-button color="textInverted" :disabled="form.processing" @click="ok">Cancel</va-button>
              <va-button type="submit" class="mr-2 mb-2" :disabled="form.processing">Submit</va-button>
            </va-card-actions>
          </form>
        </template>
      </va-modal>
    </div>
    <table class="va-table va-table--hoverable mt-3">
      <thead>
        <tr>
          <th style="width:200px">Scheduled At</th>
          <th>Apps to Backup</th>
          <th style="width:50px"></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="(backup, index) in backups" :key="index">
          <td>
            <Link :href="'/admin/server/backup_scheduler/'+backup.id">{{ backup.scheduled_at }}</Link>
          </td>
          <td>
            {{ backup.apps }}
          </td>
          <td class="va-text-center">
            <va-icon name="entypo-cancel" color="danger" class="clickable-icon"
              @click="showRemoveBackupModal(backup)" />
          </td>
        </tr>
      </tbody>
    </table>
    <va-pagination v-if="meta.total > pageSize" class="mt-3 mb-3 justify-center" v-model="curPageValue" :pages="pages" input @update:modelValue="changePage" />
    <va-modal v-model="showRemoveBackup" hide-default-actions :title="'Remove ' + removeBackup.name + '?'"
      :message="'Are you sure you want to delete from the backup scheduled for '+ removeBackup.scheduled_at+'? This action is permanent.'">
      <template #footer="{ cancel }">
        <va-button color="backgroundSecondary" @click="cancel">
          Cancel
        </va-button>
        <va-button color="danger"
          @click="remove.delete('/admin/server/backup_scheduler/'+removeBackup.id); showRemoveBackup = !showRemoveBackup">Delete</va-button>
      </template>
    </va-modal>
</template>

<script lang="ts">
export default {
  layout: (h, page) => {
    return h(AppLayout, () => h(BackupsLayout, () => page))
  },
  props: {
    backups: Object,
    errors: Object,
    servers: Object,
    applications: Object,
    organizations: Object,
    meta: Object
  },
  data () {
    return {
      curPageValue: this.meta.page,
      pageSize: 50,
      pages: this.meta.pages,
      removeBackup: '',
      showRemoveBackup: false,
      showAddBackup: false,
      form: useForm({
        date: new Date(),
        time: '',
        date_time: new Date(),
        keep_for: 1,
        organization: '',
        server: '',
        application: ''
      }),
      remove: useForm({})
    }
  },
  methods: {
    showRemoveBackupModal (backup) {
      this.showRemoveBackup = true
      this.removeBackup = backup
    },
    backupScheduled () {
      this.form.reset()
      this.showAddBackup = false
    },
    updateDateTime () {
      const date = new Intl.DateTimeFormat('en-US').format(this.form.date)
      const time = [this.form.time.getHours(), this.form.time.getMinutes(), this.form.time.getSeconds()]
      this.form.date_time = new Date(date + ' ' + time.join(':'))
    },
    changePage () {
      const url = location.protocol + '//' + location.host + location.pathname
      router.visit(url + '?page=' + this.curPageValue, { method: 'get', preserveScroll: true })
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
