<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import ServerLayout from './ServerLayout.vue'
import AdminSettings from '@/components/AdminSettings.vue'
import { Link, useForm } from '@inertiajs/vue3'

</script>
<template>
  <Head>
    <title>{{ $t('admin.servers.server') }} - Control Panel</title>
  </Head>
  <div class="row justify-center" v-if="server.status != 'active' && can.activate">
    <Link :href="'/admin/server/servers/'+server.id+'/confirm'"><va-button>{{ $t('admin.servers.confirmServerSettings') }}</va-button></Link>
  </div>
  <div v-else-if="server.status != 'active' && !can.activate">
    <va-alert
      color="info"
      icon="info"
      outline
      class="mb-3"
    >
      {{ $t('admin.servers.runTestInstructions') }}
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
      <h5 class="va-h5">{{ $t('admin.servers.setupInstructions') }}</h5>
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
          <h5>{{ $t('admin.servers.appInstance') }}</h5>
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
          <h5>{{ $t('admin.servers.type') }}</h5>
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
          <h5>{{ $t('admin.servers.interface') }}</h5>
        </va-list-item-label>
      </va-list-item-section>
      <va-list-item-section>
        {{ server.interface }}
      </va-list-item-section>
    </va-list-item>
  </va-list>
  <va-list-separator class="my-1" fit />
  <AdminSettings>
    <template #name>{{ $t('admin.servers.serverConnectionInfo') }}</template>
    <template #description></template>
    <template #settings>
      <va-input v-model="form.name"
        :label="$t('admin.servers.serverName')"
        id="name"
        class="mb-2"
        immediateValidation
        :error="$page.props.errors.name"
        :error-messages="$page.props.errors.name"
      />
      <template v-if="server.interface !== 'helm_k8s'">
        <va-input v-model="form.host"
          :label="$t('admin.servers.host')"
          :messages="server.description.host"
          id="host"
          class="mb-2"
          immediateValidation
          :error="$page.props.errors.host"
          :error-messages="$page.props.errors.host"
        />
        <va-input v-model="form.address"
          :label="$t('admin.servers.address')"
          :messages="server.description.address"
          id="address"
          class="mb-2"
          immediateValidation
          :error="$page.props.errors.address"
          :error-messages="$page.props.errors.address"
        />
        <va-input v-model="form.api_key"
          :label="$t('admin.servers.apiKey')"
          :messages="server.description.api_key"
          :placeholder="server.has_api_key ? $t('admin.servers.leaveBlankToKeep') : ''"
          id="apiKey"
          class="mb-2"
          immediateValidation
          :error="$page.props.errors.api_key"
          :error-messages="$page.props.errors.api_key"
        />
        <va-input v-model="form.api_secret"
          :label="$t('admin.servers.apiSecret')"
          :messages="server.description.api_secret"
          :placeholder="server.has_api_secret ? $t('admin.servers.leaveBlankToKeep') : ''"
          id="apiSecret"
          class="mb-2"
          immediateValidation
          :error="$page.props.errors.api_secret"
          :error-messages="$page.props.errors.api_secret"
        />
        <va-input v-model="form.ip"
          :label="$t('admin.servers.ip')"
          :messages="server.description.ip"
          id="ip"
          class="mb-2"
          immediateValidation
          :error="$page.props.errors.ip"
          :error-messages="$page.props.errors.ip"
        />
        <va-input v-model="form.internal_address"
          :label="$t('admin.servers.internalAddress')"
          :messages="server.description.internal_address"
          id="internalAddress"
          class="mb-2"
          immediateValidation
          :error="$page.props.errors.internal_address"
          :error-messages="$page.props.errors.internal_address"
        />
      </template>
      <template v-else>
        <va-input v-model="form.k8s_api_server"
          :label="$t('admin.servers.k8sApiServer')"
          :messages="server.description.k8s_api_server"
          id="k8sApiServer"
          class="mb-2"
          immediateValidation
          :error="$page.props.errors.k8s_api_server"
          :error-messages="$page.props.errors.k8s_api_server"
        />
        <va-textarea v-model="form.k8s_ca_cert"
          :label="$t('admin.servers.k8sCaCert')"
          :messages="server.description.k8s_ca_cert"
          id="k8sCaCert"
          class="mb-2"
          min-rows="3"
          immediateValidation
          :error="$page.props.errors.k8s_ca_cert"
          :error-messages="$page.props.errors.k8s_ca_cert"
        />
        <va-checkbox v-model="form.k8s_tls_verify"
          :label="$t('admin.servers.k8sTlsVerify')"
          :messages="server.description.k8s_tls_verify"
          id="k8sTlsVerify"
          class="mb-2"
          immediateValidation
        />
        <va-input v-model="form.k8s_ingress_class"
          :label="$t('admin.servers.k8sIngressClass')"
          :messages="server.description.k8s_ingress_class"
          id="k8sIngressClass"
          class="mb-2"
          immediateValidation
          :error="$page.props.errors.k8s_ingress_class"
          :error-messages="$page.props.errors.k8s_ingress_class"
        />
        <va-select v-model="form.k8s_auth_type"
          :label="$t('admin.servers.k8sAuthType')"
          :messages="server.description.k8s_auth_type"
          id="k8sAuthType"
          class="mb-2"
          :options="[{ value: 'bearer_token', text: $t('admin.servers.k8sAuthTypeBearerToken') }, { value: 'client_cert', text: $t('admin.servers.k8sAuthTypeClientCert') }]"
          value-by="value"
          text-by="text"
          immediateValidation
          :error="$page.props.errors.k8s_auth_type"
          :error-messages="$page.props.errors.k8s_auth_type"
        />
        <va-input v-if="form.k8s_auth_type === 'bearer_token'" v-model="form.k8s_bearer_token"
          type="password"
          :label="$t('admin.servers.k8sBearerToken')"
          :messages="server.description.k8s_bearer_token"
          :placeholder="server.has_k8s_bearer_token ? $t('admin.servers.leaveBlankToKeep') : ''"
          id="k8sBearerToken"
          class="mb-2"
          immediateValidation
          :error="$page.props.errors.k8s_bearer_token"
          :error-messages="$page.props.errors.k8s_bearer_token"
        />
        <template v-if="form.k8s_auth_type === 'client_cert'">
          <va-textarea v-model="form.k8s_client_cert"
            :label="$t('admin.servers.k8sClientCert')"
            :messages="server.description.k8s_client_cert"
            :placeholder="server.has_k8s_client_cert ? $t('admin.servers.leaveBlankToKeep') : ''"
            id="k8sClientCert"
            class="mb-2"
            min-rows="3"
            immediateValidation
            :error="$page.props.errors.k8s_client_cert"
            :error-messages="$page.props.errors.k8s_client_cert"
          />
          <va-textarea v-model="form.k8s_client_key"
            :label="$t('admin.servers.k8sClientKey')"
            :messages="server.description.k8s_client_key"
            :placeholder="server.has_k8s_client_key ? $t('admin.servers.leaveBlankToKeep') : ''"
            id="k8sClientKey"
            class="mb-2"
            min-rows="3"
            immediateValidation
            :error="$page.props.errors.k8s_client_key"
            :error-messages="$page.props.errors.k8s_client_key"
          />
        </template>
        <va-input v-model="form.k8s_impersonate_user"
          :label="$t('admin.servers.k8sImpersonateUser')"
          id="k8sImpersonateUser"
          class="mb-2"
          immediateValidation
          :error="$page.props.errors.k8s_impersonate_user"
          :error-messages="$page.props.errors.k8s_impersonate_user"
        />
        <va-input v-model="form.k8s_impersonate_group"
          :label="$t('admin.servers.k8sImpersonateGroup')"
          id="k8sImpersonateGroup"
          class="mb-2"
          immediateValidation
          :error="$page.props.errors.k8s_impersonate_group"
          :error-messages="$page.props.errors.k8s_impersonate_group"
        />
      </template>
      <va-select v-model="form.default_backup_server"
        :label="$t('admin.servers.defaultBackupServer')"
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
        :label="$t('admin.servers.isBackupServer')"
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
    <template #name>{{ $t('admin.servers.settings') }}</template>
    <template #description>{{ server.description.settings}}</template>
    <template #settings>
      <template v-for="(setting, index) in settings" :key="index">
        <div class="row">
          <div class="flex flex-col lg4">
            <va-input v-model="settings[index]['name']"
              :label="$t('admin.plans.name')"
              immediateValidation
              @change="updateSettings()"
              :error="$page.props.errors.settings"
              :error-messages="$page.props.errors.settings"
              />
          </div>
          <div class="flex flex-col lg7">
            <va-input v-model="settings[index]['value']"
              :label="$t('admin.plans.value')"
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
      <va-button @click="addNewSetting()">{{ $t('admin.servers.addSetting') }}</va-button>
    </template>
  </AdminSettings>
  <va-button type="submit"
    id="submit"
    :disabled="form.processing"
    class="mr-2 mb-2"
  >
    {{ $t('common.update') }}
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
        { value: 'web', text: this.$t('admin.servers.web') },
        { value: 'database', text: this.$t('admin.servers.database') },
        { value: 'email', text: this.$t('admin.servers.email') }
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
        // Secrets are never sent from the backend after saving — these
        // start blank; submitting blank means "leave unchanged".
        api_key: '',
        api_secret: '',
        default_backup_server: this.server.default_backup_server,
        is_backup_server: this.server.is_backup_server,
        k8s_api_server: this.server.k8s_api_server,
        k8s_ca_cert: this.server.k8s_ca_cert,
        k8s_tls_verify: this.server.k8s_tls_verify,
        k8s_ingress_class: this.server.k8s_ingress_class,
        k8s_auth_type: this.server.k8s_auth_type || 'bearer_token',
        k8s_bearer_token: '',
        k8s_client_cert: '',
        k8s_client_key: '',
        k8s_impersonate_user: this.server.k8s_impersonate_user,
        k8s_impersonate_group: this.server.k8s_impersonate_group
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
