<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import OrganizationLayout from '../OrganizationLayout.vue'
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <Head>
    <title>{{ organization.name }} Domains - Control Panel</title>
  </Head>
  <va-scroll-container
      color="warning"
      horizontal
    >
    <table class="va-table va-table--hoverable mt-3">
      <thead>
        <tr>
          <th>Domaind Name</th>
          <th>App</th>
          <th>Type</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="domain in domains.slice((curPageValue - 1), (curPageValue - 1 + pageSize))" :key="domain.id" style="min-height:300px;">
          <td>{{ domain.name }}</td>
          <td>{{ domain.app.name }}</td>
          <td>{{ domain.type }}</td>
          <td>{{ domain.status }}</td>
        </tr>
      </tbody>
    </table>
  </va-scroll-container>

  <va-pagination v-if="domains.length > pageSize" class="mt-3 mb-3 justify-center" v-model="curPageValue" :total="domains.length" :direction-links="false" :page-size="pageSize" />
</template>

<script lang="ts">
export default {
  layout: (h, page) => {
    return h(AppLayout, () => h(OrganizationLayout, () => page))
  },
  props: {
    organization: Object,
    domains: Object
  },
  data () {
    return {
      curPageValue: 1,
      pageSize: 10,
      showScheduleBackup: false,
      form: useForm({
        scheduled_at: '',
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
