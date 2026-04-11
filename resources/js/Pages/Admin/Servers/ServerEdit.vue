<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import ServerLayout from './ServerLayout.vue'
import AdminSettings from '@/components/AdminSettings.vue'
import { Link, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <Head>
    <title>Server - Control Panel</title>
  </Head>
  <div class="row justify-center" v-if="server.status != 'active' && can.activate">
    <Link :href="'/admin/server/servers/'+server.id+'/confirm'"><va-button>Confirm Server Settings</va-button></Link>
  </div>
  <div v-else-if="server.status != 'active' && !can.activate">
    <va-alert
      color="info"
      icon="info"
      outline
      class="mb-3"
    >
      Before you can enable and use this server, you must successfully run a test. Instructions available here.
    </va-alert>
  </div>
  <va-alert
    v-if="server.description.general"
    color="info"
    outline
    class="mb-6"
  >
    <template #icon>
      <VaIcon
        name="info"
        color="info"
      />
    </template>
      <h5 class="va-h5">Setup Instructions</h5>
      <p v-for="(paragraph, index) in server.description.general" :key="index"
        class="py-1">
        {{ paragraph }}
      </p>
  </va-alert>
  <form @submit.prevent="form.put('/admin/server/servers/'+server.id)">
  <va-list>
    <va-list-item v-if="server.app_instance" class="py-3">
      <va-list-item-section label>
        <va-list-item-label>
          <h5>App Instance</h5>
        </va-list-item-label>
      </va-list-item-section>
      <va-list-item-section>
        <va-list-item-label>
          <Link :href="'/admin/organizations/'+server.app_instance.organization_id+'/apps/'+server.app_instance.id">{{ server.app_instance.label }}</Link>
        </va-list-item-label>
      </va-list-item-section>
    </va-list-item>
    <va-list-separator class="my-1" fit />
    <va-list-item class="py-3">
      <va-list-item-section label>
        <va-list-item-label>
          <h5>Type</h5>
        </va-list-item-label>
      </va-list-item-section>
      <va-list-item-section>
        {{ server.type }}
      </va-list-item-section>
    </va-list-item>
    <va-list-separator class="my-1" fit />
    <va-list-item class="py-3">
      <va-list-item-section label>
        <va-list-item-label>
          <h5>Interface</h5>
        </va-list-item-label>
      </va-list-item-section>
      <va-list-item-section>
        {{ server.interface }}
      </va-list-item-section>
    </va-list-item>
  </va-list>
  <va-list-separator class="my-1" fit />
  <AdminSettings>
    <template #name>Server Connection Info</template>
    <template #description></template>
    <template #settings>
      <va-input v-model="form.name"
        label="Server Name"
        id="name"
        class="mb-2"
        immediateValidation
        :error="$page.props.errors.name"
        :error-messages="$page.props.errors.name"
      />
      <va-input v-model="form.host"
        label="Host"
        :messages="server.description.host"
        id="host"
        class="mb-2"
        immediateValidation
        :error="$page.props.errors.host"
        :error-messages="$page.props.errors.host"
      />
      <va-input v-model="form.address"
        label="Address"
        :messages="server.description.address"
        id="address"
        class="mb-2"
        immediateValidation
        :error="$page.props.errors.address"
        :error-messages="$page.props.errors.address"
      />
      <va-input v-model="form.api_key"
        label="API Key"
        :messages="server.description.api_key"
        id="apiKey"
        class="mb-2"
        immediateValidation
        :error="$page.props.errors.api_key"
        :error-messages="$page.props.errors.api_key"
      />
      <va-input v-model="form.api_secret"
        label="API Secret"
        :messages="server.description.api_secret"
        id="apiSecret"
        class="mb-2"
        immediateValidation
        :error="$page.props.errors.api_secret"
        :error-messages="$page.props.errors.api_secret"
      />
      <va-input v-model="form.ip"
        label="IP"
        :messages="server.description.ip"
        id="ip"
        class="mb-2"
        immediateValidation
        :error="$page.props.errors.ip"
        :error-messages="$page.props.errors.ip"
      />
      <va-input v-model="form.internal_address"
        label="Internal Address"
        :messages="server.description.internal_address"
        id="internalAddress"
        class="mb-2"
        immediateValidation
        :error="$page.props.errors.internal_address"
        :error-messages="$page.props.errors.internal_address"
      />
      <va-select v-model="form.default_backup_server"
        label="Default Backup Server"
        id="defaultBackupServer"
        :options="backup_servers"
        immediateValidation
        value-by="id"
        class="mb-2"
        text-by="name"
        :error="$page.props.errors.default_backup_server"
        :error-messages="$page.props.errors.default_backup_server"
      />
      <va-checkbox v-model="form.is_backup_server"
        label="Is Backup Server"
        id="isBackupServer"
        class="mb-2"
        immediateValidation
        :error="$page.props.errors.is_backup_server"
        :error-messages="$page.props.errors.is_backup_server"
      />
    </template>
  </AdminSettings>
  <va-list-separator class="my-1" fit />
  <AdminSettings>
    <template #name>Settings</template>
    <template #description>{{ server.description.settings}}</template>
    <template #settings>
      <template v-for="(setting, index) in settings" :key="index">
        <div class="row">
          <div class="flex flex-col lg4">
            <va-input v-model="settings[index]['name']"
              :label="t('admin.plans.name')"
              immediateValidation
              @change="updateSettings()"
              :error="$page.props.errors.settings"
              :error-messages="$page.props.errors.settings"
              />
          </div>
          <div class="flex flex-col lg7">
            <va-input v-model="settings[index]['value']"
              :label="t('admin.plans.value')"
              immediateValidation
              @change="updateSettings()"
              :error="$page.props.errors.settings"
              :error-messages="$page.props.errors.settings"
              />
          </div>
          <div class="flex lg1">
            <div class="content-center align-center" @click="removeSetting(index)">
              <va-icon name="fa-x" color="danger" />
            </div>
          </div>
        </div>
      </template>
      <va-button @click="addNewSetting()">Add Setting</va-button>
    </template>
  </AdminSettings>
  <va-button type="submit"
    id="submit"
    :disabled="form.processing"
    class="mr-2 mb-2"
  >
    Update
  </va-button>
</form>
</template>

<script lang="ts">
export default {
  layout: (h, page) => {
    return h(AppLayout, () => h(ServerLayout, () => page))
  },
  props: {
    interfaces: Object,
    server: Object,
    errors: Object,
    can: Object,
    backup_servers: Object
  },
  data () {
    const settings = []

    if (typeof this.server.settings === 'object') {
      for (const [name, value] of Object.entries(this.server.settings)) {
        settings.push({
          name,
          value
        })
      }
    }

    return {
      settings,
      server_types: [
        { value: 'web', text: 'Web' },
        { value: 'database', text: 'Database' },
        { value: 'email', text: 'Email' }
      ],
      form: useForm({
        name: this.server.name,
        host: this.server.host,
        address: this.server.address,
        ip: this.server.ip,
        internal_address: this.server.internal_address,
        default_database_server: this.server.default_database_server,
        default_email_server: this.server.default_email_server,
        default_web_server: this.server.default_web_server,
        settings: this.server.settings,
        api_key: this.server.api_key,
        api_secret: this.server.api_secret,
        default_backup_server: this.server.default_backup_server,
        is_backup_server: this.server.is_backup_server
      })
    }
  },
  methods: {
    addNewSetting () {
      this.settings.push({
        name: '',
        value: ''
      })
    },
    updateSettings () {
      const settings = {}
      this.settings.forEach((setting) => {
        settings[setting.name] = setting.value
      })

      this.form.settings = settings
    },
    removeSetting (index) {
      const setting = this.settings[index]
      delete this.form.settings[setting.name]
      this.settings.splice(index, 1)
    }
  }
}
</script>

<style>
.full-width {
  width: 100%
}
</style>
