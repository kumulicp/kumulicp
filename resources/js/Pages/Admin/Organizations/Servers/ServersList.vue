<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import OrganizationLayout from '../OrganizationLayout.vue'
import { useForm } from '@inertiajs/vue3'
</script>

<template>
  <Head>
    <title>{{ organization.name }} {{ $t('admin.servers.servers') }} - Control Panel</title>
  </Head>

  <va-scroll-container color="primary" horizontal>
    <table class="va-table va-table--hoverable mt-3">
      <thead>
        <tr>
          <th>{{ $t('admin.servers.name') }}</th>
          <th>{{ $t('admin.servers.type') }}</th>
          <th>{{ $t('admin.servers.backupServer') }}</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="server in org_servers" :key="server.id">
          <td>{{ server.name }}</td>
          <td>{{ server.type }}</td>
          <td>{{ server.backup_server_name ?? $t('admin.servers.none') }}</td>
          <td>
            <va-button size="small" @click="openEditModal(server)">{{ $t('common.edit') }}</va-button>
          </td>
        </tr>
      </tbody>
    </table>
  </va-scroll-container>

  <va-modal v-model="showEditModal" hide-default-actions no-padding class="p-0">
    <template #content="{ ok }">
      <va-card-title class="m-0">{{ $t('admin.servers.editBackupServer') }}</va-card-title>
      <va-card-content>
        <p class="mb-3">{{ selectedServer?.name }}</p>
        <va-select
          v-model="editForm.backup_server_id"
          :label="$t('admin.servers.backupServer')"
          :options="backup_servers"
          text-by="name"
          value-by="id"
          clearable
          :error="!!$page.props.errors.backup_server_id"
          :error-messages="$page.props.errors.backup_server_id"
        />
      </va-card-content>
      <va-card-actions align="right">
        <va-button color="textInverted" :disabled="editForm.processing" @click="ok">{{ $t('common.cancel') }}</va-button>
        <va-button
          class="mr-2"
          :disabled="editForm.processing"
          @click="submitEdit(ok)"
        >{{ $t('common.save') }}</va-button>
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
    org_servers: Array,
    backup_servers: Array,
  },
  data () {
    return {
      showEditModal: false,
      selectedServer: null,
      editForm: useForm({
        backup_server_id: null
      })
    }
  },
  methods: {
    openEditModal (server) {
      this.selectedServer = server
      this.editForm.backup_server_id = server.backup_server_id ?? null
      this.showEditModal = true
    },
    submitEdit (ok) {
      this.editForm.put(
        '/admin/organizations/' + this.organization.id + '/servers/' + this.selectedServer.id,
        {
          onSuccess: () => {
            ok()
          }
        }
      )
    }
  }
}
</script>
