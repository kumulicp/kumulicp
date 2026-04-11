<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import OrganizationLayout from '../OrganizationLayout.vue'
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <Head>
    <title>{{ organization.name }} Backups - Control Panel</title>
  </Head>
  <div class="row justify-center">
    <va-button class="" @click="showScheduleBackup = !showScheduleBackup">Schedule Backup</va-button>
    <va-modal v-model="showScheduleBackup" no-outside-dismiss no-padding size="small" class="p-0">
      <template #content>
        <form @submit.prevent="form.post('/admin/organizations/'+organization.id+'/backups', {onFinish: () => backupScheduled()})">
          <va-card-title class="m-0"> Schedule Backup </va-card-title>
          <va-card-content class="m-0">
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
            <va-input type="number"
              v-model="form.keep_for"
              required-mark
              label="Keep For (days)"
              class="mb-3"
              :error="$page.props.errors.keep_for"
              :error-messages="$page.props.errors.keep_for"
              >
              <template #appendInner>
                days
              </template>
            </va-input>
            <va-select
              v-model="form.backup_type"
              class="mb-3"
              label="Backup Type"
              :options="backup_types"
              text-by="text"
              value-by="value"
              required-mark
              immediateValidation
              auto-select-first-option
            />
            <va-select
              v-model="form.backup"
              class="mb-3"
              label="Backup"
              :options="backup_options"
              text-by="text"
              value-by="value"
              required-mark
              immediateValidation
              auto-select-first-option
            />
          </va-card-content>
          <va-card-actions align="right" class="">
            <va-button color="textInverted" :disabled="form.processing" @click="ok">Cancel</va-button>
            <va-button type="submit" :disabled="form.processing" class="mr-2 mb-2">Submit</va-button>
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
          <th>Action</th>
          <th>Scheduled At</th>
          <th>Name</th>
          <th>Type</th>
          <th>Status</th>
          <th>Completed At</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="backup in backups" :key="backup.id" style="min-height:300px;">
          <td>{{ backup.action }}</td>
          <td>{{ backup.scheduled_at }}</td>
          <td>{{ backup.name }}</td>
          <td>{{ backup.type }}</td>
          <td>{{ backup.status }}</td>
          <td>{{ backup.completed_at }}</td>
          <td class="justify-center" style="width:50px; text-align: center">
            <va-icon v-if="backup.status == 'completed'" color="primary" name="entypo-ccw" class="clickable-icon"
              @click="showRestoreModal(backup)" />
            <va-icon v-else-if="backup.status == 'scheduled'" color="danger" name="entypo-cancel" class="clickable-icon"
              @click="showDeleteModal(backup)" />
          </td>
        </tr>
      </tbody>
    </table>
  </va-scroll-container>

  <va-pagination v-if="backups.length > pageSize" class="mt-3 mb-3 justify-center" v-model="curPageValue" :total="backups.length" :direction-links="false" :page-size="pageSize" />

  <va-modal v-model="showRestore" no-padding size="small" class="p-0">
    <template #content>
      <va-card-title class="m-0"> Restore Backup </va-card-title>
      <va-card-content class="m-0">
        Are you sure you want to restore {{ organization.name }}'s {{ selected_backup.app.name }} {{ selected_backup.type }} from {{ selected_backup.scheduled_at }}?
      </va-card-content>
      <va-card-actions align="right" class="">
        <va-button color="textInverted" @click="showRestore = false">Cancel</va-button>
        <va-button :href="'/admin/organizations/'+organization.id+'/backups/'+selected_backup.id+'/restore'" class="mr-2 mb-2" @click="showRestore = false">Restore</va-button>
      </va-card-actions>
    </template>
  </va-modal>

  <va-modal v-model="showDelete" no-padding size="small" class="p-0">
    <template #content>
      <va-card-title class="m-0"> Delete Scheduled Backup </va-card-title>
      <va-card-content class="m-0">
        Are you sure you want to delete {{ organization.name }}'s {{ selected_backup.app.name }} {{ selected_backup.type }} from {{ selected_backup.scheduled_at }}?
      </va-card-content>
      <va-card-actions align="right" class="">
        <va-button color="textInverted" @click="showDelete = false">Cancel</va-button>
        <va-button class="mr-2 mb-2" @click="deleteBackup(selected_backup.id)">Delete</va-button>
      </va-card-actions>
    </template>
  </va-modal>
</template>

<script lang="ts">
export default {
  layout: (h, page) => {
    return h(AppLayout, () => h(OrganizationLayout, () => page))
  },
  props: {
    organization: Object,
    apps: Object,
    backups: Object,
    errors: Object,
    meta: Object
  },
  data () {
    return {
      curPageValue: this.meta.page,
      pageSize: 30,
      pages: this.meta.pages,
      showScheduleBackup: false,
      form: useForm({
        date: new Date(),
        time: '',
        date_time: new Date(),
        keep_for: '',
        backup_type: 'database',
        backup: ''
      }),
      backup_types: [
        { text: 'Email', value: 'email' },
        { text: 'App Database', value: 'database' }
      ],
      selected_backup: '',
      showRestore: false,
      showDelete: false
    }
  },
  computed: {
    backup_options () {
      this.form.backup = ''
      if (this.form.backup_type === 'email') {
        return this.email_domains
      } else {
        return this.apps
      }
    }
  },
  methods: {
    backupScheduled () {
      this.form.reset()
      this.showScheduleBackup = false
    },
    showRestoreModal (backup) {
      this.showRestore = true
      this.selected_backup = backup
    },
    showDeleteModal (backup) {
      this.showDelete = true
      this.selected_backup = backup
    },
    deleteBackup (backup) {
      this.showDelete = false
      this.$inertia.delete('/admin/organizations/' + this.organization.id + '/backups/' + backup)
    },
    updateDateTime () {
      const date = new Intl.DateTimeFormat('en-US').format(this.form.date)
      const time = [this.form.time.getHours(), this.form.time.getMinutes(), this.form.time.getSeconds()]
      this.form.date_time = new Date(date + ' ' + time.join(':'))
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
