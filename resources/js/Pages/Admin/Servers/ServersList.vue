<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { Link, useForm } from '@inertiajs/vue3'
import axios from 'axios'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>

<template>
  <Head>
    <title>Servers - Control Panel</title>
  </Head>
  <va-card class="mb-4">
    <va-card-title>Servers</va-card-title>
    <va-card-content>
      <div class="row justify-center">
        <va-button id="addServer" @click="showAddServer = !showAddServer">Add Server</va-button>
      </div>
        <va-modal v-model="showAddServer" no-outside-dismiss no-padding>
          <template #content="{ ok }">
            <form @submit.prevent="form.post('/admin/server/servers')">
              <va-card-title>Add Server</va-card-title>
              <va-card-content>
                <va-input v-model="form.name"
                  id="name"
                  required-mark
                  immediateValidation
                  label="Server Name"
                  class="mb-3"
                  :error="$page.props.errors.name"
                  :error-messages="$page.props.errors.name"
                />
                <va-select v-model="form.type"
                  id="type"
                  :options="server_types"
                  required-mark
                  immediateValidation
                  value-by="value"
                  text-by="text"
                  label="Type"
                  class="mb-3"
                  @update:modelValue="form.interface = ''"
                  :error="$page.props.errors.type"
                  :error-messages="$page.props.errors.type"
                />
                <va-select v-model="form.interface"
                  id="interface"
                  :options="interfaces[form.type]"
                  required-mark
                  immediateValidation
                  label="Server Interface"
                  class="mb-3"
                  :error="$page.props.errors.interface"
                  :error-messages="$page.props.errors.interface"
                />
                <va-select v-model="form.location"
                  id="interface"
                  :options="locations"
                  required-mark
                  immediateValidation
                  label="Server Location"
                  class="mb-3"
                  value-by="value"
                  text-by="text"
                  :error="$page.props.errors.location"
                  :error-messages="$page.props.errors.location"
                />
                <va-select v-if="form.location == 'internal'" v-model="form.app"
                  id="interface"
                  :options="applications"
                  required-mark
                  immediateValidation
                  label="Application"
                  class="mb-3"
                  value-by="value"
                  text-by="text"
                  :error="$page.props.errors.app"
                  :error-messages="$page.props.errors.app"
                  @update:model-value="getPlans()"
                />
                <va-select v-if="form.location == 'internal'" v-model="form.plan"
                  id="interface"
                  :options="plans"
                  required-mark
                  immediateValidation
                  label="Plan"
                  class="mb-3"
                  value-by="value"
                  text-by="text"
                  :error="$page.props.errors.plan"
                  :error-messages="$page.props.errors.plan"
                />
              </va-card-content>
              <va-card-actions align="right">
                <va-button color="textInverted" :disabled="form.processing" @click="ok">Cancel</va-button>
                <va-button type="submit" id="submit" class="mr-2 mb-2" :disabled="form.processing">Submit</va-button>
              </va-card-actions>
            </form>
          </template>
        </va-modal>
        <va-scroll-container
          color="primary"
          horizontal
        >
          <table class="va-table va-table--hoverable mt-3">
            <thead>
              <tr>
                <th style="width: 50%">Name</th>
                <th>Type</th>
                <th>Host</th>
                <th>Status</th>
                <th>Remove</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="server in servers.slice((curPageValue - 1), (curPageValue - 1 + pageSize))" :key="'server' + server">
                <td>
                  <Link :href="'/admin/server/servers/'+server.id">{{ server.name }}</Link>
                </td>
                <td>
                  {{ server.type }}
                </td>
                <td>
                  {{ server.host }}
                </td>
                <td>
                  {{ server.status }}
                </td>
                <td class="va-text-center">
                  <va-icon name="entypo-cancel" color="danger" class="clickable-icon delete-server"
                    v-if="server.status == 'inactive'"
                    @click="showRemoveServerModal(server)" />
                </td>
              </tr>
            </tbody>
          </table>
        </va-scroll-container>
        <va-pagination v-if="servers.length > pageSize" class="mt-3 mb-3 justify-center" v-model="curPageValue" :total="servers.length" direction-links
          :page-size="pageSize" />
    </va-card-content>
  </va-card>
  <va-modal v-model="showRemoveServer" hide-default-actions :title="'Remove ' + removeServer.name + '?'"
    :message="'Are you sure you want to remove '+ removeServer.name +'? This action is permanent.'">
    <template #footer="{ cancel }">
      <va-button color="backgroundSecondary" @click="cancel">
        Cancel
      </va-button>
      <va-button color="danger"
        id="delete"
        @click="remove.delete('/admin/server/servers/' + removeServer.id); showRemoveServer = !showRemoveServer">Delete</va-button>
    </template>
  </va-modal>
</template>

<script lang="ts">
export default {
  layout: (h, page) => h(AppLayout, [page]),
  props: {
    interfaces: Object,
    servers: Object,
    applications: Object,
    errors: Object
  },
  data () {
    return {
      curPageValue: 1,
      pageSize: 10,
      showAddServer: false,
      showRemoveServer: false,
      removeServer: '',
      server_types: [
        { value: 'web', text: 'Web' },
        { value: 'database', text: 'Database' },
        { value: 'email', text: 'Email' },
        { value: 'sso', text: 'SSO' }
      ],
      locations: [
        { value: 'external', text: 'External' },
        { value: 'internal', text: 'Internal' }
      ],
      plans: [],
      form: useForm({
        name: '',
        type: 'web',
        interface: '',
        location: 'external',
        app: '',
        plan: ''
      }),
      remove: useForm({})
    }
  },
  methods: {
    showRemoveServerModal (server) {
      this.removeServer = server
      this.showRemoveServer = true
    },
    getPlans () {
      this.plan = ''
      const vueState = this
      axios.get('/admin/apps/' + this.form.app + '/plans/retrieve')
        .then(function (response) {
          vueState.plans = response.data
        })
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

.servers-list {
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
