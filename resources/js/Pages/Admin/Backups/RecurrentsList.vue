<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import BackupsLayout from './BackupsLayout.vue'
import { Link, useForm } from '@inertiajs/vue3'

</script>

<template>
  <Head>
    <title>{{ $t('admin.backups.backups') }} - Control Panel</title>
  </Head>
    <div class="row justify-center">
      <va-button @click="showAddBackup = !showAddBackup">{{ $t('admin.backups.addRecurringBackups') }}</va-button>
      <va-modal v-model="showAddBackup" no-outside-dismiss no-padding>
        <template #content="{ ok }">
          <form @submit.prevent="form.post('/admin/server/backup_scheduler/recurring', { onComlete: showAddBackup = false })" resetOnSuccess>
            <va-card-title>{{ $t('admin.backups.addBackup') }}</va-card-title>
            <va-card-content>
              <va-select v-model="form.recurrence"
                :options="recurrences"
                class="mb-3"
                required-mark
                immediateValidation
                :label="$t('admin.backups.recurrence')"
                value-by="value"
                text-by="text"
                :error-messages="$page.props.errors.recurrence"
                :error="$page.props.errors.recurrence"
                />
              <va-time-input v-model="form.time"
                class="mb-3"
                required-mark
                immediateValidation
                :label="$t('admin.backups.time')"
                :error="$page.props.errors.time"
                :error-messages="$page.props.errors.time"
                />
              <va-input v-model="form.keep_for"
                required-mark
                immediateValidation
                type="number"
                max="120"
                min="1"
                :label="$t('admin.backups.keep')"
                class="mb-3"
                :error="$page.props.errors.keep_for"
                :error-messages="$page.props.errors.keep_for"
                >
                <template #appendInner>
                  <va-select
                    v-model="form.keep_interval"
                    :options="intervals"
                    text-by="text"
                    value-by="value"
                    immediateValidation
                    :error="$page.props.errors.keep_interval"
                    :error-messages="$page.props.errors.keep_interval"
                  />
                </template>
              </va-input>
              <va-select v-model="form.server"
                :options="servers"
                class="mb-3"
                required-mark
                immediateValidation
                :label="$t('admin.backups.server')"
                value-by="id"
                text-by="name"
                :error-messages="$page.props.errors.server"
                :error="$page.props.errors.server"
                />
              <va-select v-model="form.organization"
                :options="organizations"
                class="mb-3"
                immediateValidation
                :label="$t('admin.backups.organizations')"
                value-by="id"
                text-by="name"
                :error-messages="$page.props.errors.organization"
                :error="$page.props.errors.organization"
                />
              <va-select v-model="form.application"
                :options="applications"
                class="mb-3"
                :label="$t('admin.backups.application')"
                immediateValidation
                value-by="id"
                text-by="name"
                :error-messages="$page.props.errors.application"
                :error="$page.props.errors.application"
                />
            </va-card-content>
            <va-card-actions align="right">
              <va-button color="textInverted" :disabled="form.processing" @click="ok">{{ $t('modal.cancel') }}</va-button>
              <va-button type="submit" class="mr-2 mb-2" :disabled="form.processing">{{ $t('form.submit') }}</va-button>
            </va-card-actions>
          </form>
        </template>
      </va-modal>
    </div>
    <va-scroll-container
      color="primary"
      horizontal
    >
      <table class="va-table va-table--hoverable mt-3">
        <thead>
          <tr>
            <th>{{ $t('admin.backups.recurrence') }}</th>
            <th>{{ $t('admin.backups.deleteAfter') }}</th>
            <th>{{ $t('organization.organization') }}</th>
            <th>{{ $t('admin.backups.application') }}</th>
            <th>{{ $t('admin.backups.server') }}</th>
            <th>{{ $t('admin.backups.time') }}</th>
            <th>{{ $t('admin.backups.type') }}</th>
            <th>{{ $t('admin.backups.lastScheduledAt') }}</th>
            <th style="width:50px"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(backup, index) in backups.slice((curPageValue - 1), (curPageValue - 1 + pageSize))" :key="index">
            <td>
              <Link :href="'/admin/organizations/'+backup.organization.id">{{ backup.recurrence }}</Link>
            </td>
            <td>{{ backup.delete_after }} {{ backup.delete_interval }}</td>
            <td><Link :href="'/admin/organizations/'+backup.organization.id">{{ backup.organization.name }}</Link></td>
            <td><Link :href="'/admin/apps/'+backup.application.id">{{ backup.application.name }}</Link></td>
            <td><Link :href="'/admin/server/servers/'+backup.server.id">{{ backup.server.name }}</Link></td>
            <td>{{ backup.time }}</td>
            <td>{{ backup.type }}</td>
            <td>
              {{ backup.status }}
              <Link v-if="backup.status == 'active'" :href="'/admin/server/backup_scheduler/recurring/'+backup.id+'/deactivate'"><va-icon name="fa-toggle-on" /></Link>
              <Link v-else :href="'/admin/server/backup_scheduler/recurring/'+backup.id+'/activate'"><va-icon name="fa-toggle-off" /></Link>
            </td>
            <td class="va-text-center">
              <va-icon name="entypo-cancel" color="danger" class="clickable-icon"
                @click="showRemoveBackupModal(backup)" />
            </td>
          </tr>
        </tbody>
      </table>
    </va-scroll-container>
    <va-pagination v-if="backups.length > pageSize" class="mt-3 mb-3 justify-center" v-model="curPageValue" :total="backups.length" boundary-numbers
      :page-size="pageSize" />
    <va-modal v-model="showRemoveBackup" hide-default-actions :title="$t('admin.backups.removeTitle', { name: removeBackup.name })"
      :message="$t('admin.backups.removeMessage', { date: removeBackup.scheduled_at })">
      <template #footer="{ cancel }">
        <va-button color="backgroundSecondary" @click="cancel">
          {{ $t('modal.cancel') }}
        </va-button>
        <va-button color="danger"
          @click="remove.delete('/admin/server/backup_scheduler/' + removeBackup.id); showRemoveBackup = !showRemoveBackup">{{ $t('modal.delete') }}</va-button>
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
    organizations: Object
  },
  data () {
    return {
      curPageValue: 1,
      pageSize: 50,
      removeBackup: '',
      showRemoveBackup: false,
      showAddBackup: false,
      form: useForm({
        recurrence: 'daily',
        date: new Date(),
        time: '',
        keep_for: 1,
        keep_interval: 'backups',
        organization: '',
        server: '',
        application: ''
      }),
      remove: useForm({}),
      recurrences: [
        { value: 'daily', text: this.$t('admin.backups.daily') },
        { value: 'monthly', text: this.$t('admin.backups.monthly') }
      ],
      intervals: [
        { value: 'backups', text: this.$t('admin.backups.backupsInterval') },
        { value: 'days', text: this.$t('admin.backups.daysInterval') },
        { value: 'months', text: this.$t('admin.backups.monthsInterval') }
      ]
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

.clickable-icon {
  transition: 0.3s;

  &:hover {
    opacity: 0.25;
    cursor: pointer;
  }
}
</style>
