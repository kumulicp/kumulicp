<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import SettingsLayout from '../SettingsLayout.vue'
import { useForm } from '@inertiajs/vue3'

</script>
<template>
  <Head>
    <title>{{ $t('admin.secretStores.secretStores') }} - Control Panel</title>
  </Head>
  <p class="mb-3">{{ $t('admin.secretStores.description') }}</p>
  <div class="row justify-center">
    <va-button id="createSecretStore" class="mr-2" @click="showAddModal()">{{ $t('admin.secretStores.addSecretStore') }}</va-button>
  </div>

  <va-modal v-model="showForm" no-outside-dismiss no-padding size="small" class="p-0">
    <template #content="{ ok }">
      <form @submit.prevent="submit">
        <va-card-title class="m-0"> {{ editingId ? $t('admin.secretStores.editSecretStore') : $t('admin.secretStores.addSecretStore') }} </va-card-title>
        <va-card-content class="m-0">
          <va-input v-model="form.name"
            immediateValidation
            id="name"
            required-mark
            :label="$t('admin.secretStores.name')"
            class="mb-3"
            :error="$page.props.errors.name"
            :error-messages="$page.props.errors.name" />
          <va-select v-model="form.driver"
            :label="$t('admin.secretStores.driver')"
            id="driver"
            :options="[{ text: $t('admin.secretStores.driverDatabase'), value: 'database' }, { text: $t('admin.secretStores.driverOpenbao'), value: 'openbao' }]"
            text-by="text"
            value-by="value"
            immediateValidation
            class="mb-3"
            :error="$page.props.errors.driver"
            :error-messages="$page.props.errors.driver" />
          <template v-if="form.driver === 'openbao'">
            <va-input v-model="form.address"
              immediateValidation
              id="address"
              required-mark
              :label="$t('admin.secretStores.address')"
              :messages="$t('admin.secretStores.addressMessage')"
              class="mb-3"
              :error="$page.props.errors.address"
              :error-messages="$page.props.errors.address" />
            <va-input v-model="form.role_id"
              immediateValidation
              id="roleId"
              required-mark
              :label="$t('admin.secretStores.roleId')"
              class="mb-3"
              :error="$page.props.errors.role_id"
              :error-messages="$page.props.errors.role_id" />
            <va-input v-model="form.secret_id"
              type="password"
              immediateValidation
              id="secretId"
              required-mark
              :label="$t('admin.secretStores.secretId')"
              :messages="editingId ? $t('admin.secretStores.secretIdEditMessage') : ''"
              class="mb-3"
              :error="$page.props.errors.secret_id"
              :error-messages="$page.props.errors.secret_id" />
            <va-input v-model="form.mount_path"
              immediateValidation
              id="mountPath"
              :label="$t('admin.secretStores.mountPath')"
              :messages="$t('admin.secretStores.mountPathMessage')"
              class="mb-3"
              :error="$page.props.errors.mount_path"
              :error-messages="$page.props.errors.mount_path" />
            <va-input v-model="form.namespace"
              immediateValidation
              id="namespace"
              :label="$t('admin.secretStores.namespace')"
              class="mb-3"
              :error="$page.props.errors.namespace"
              :error-messages="$page.props.errors.namespace" />
          </template>
        </va-card-content>
        <va-card-actions align="right" class="">
          <va-button color="textInverted" :disabled="form.processing" @click="ok">{{ $t('common.cancel') }}</va-button>
          <va-button type="submit" :disabled="form.processing" id="submit" class="mr-2 mb-2">{{ $t('common.submit') }}</va-button>
        </va-card-actions>
      </form>
    </template>
  </va-modal>

  <va-scroll-container color="primary" horizontal>
    <table class="va-table va-table--hoverable mt-3">
      <thead>
        <tr>
          <th>{{ $t('admin.secretStores.name') }}</th>
          <th>{{ $t('admin.secretStores.driver') }}</th>
          <th>{{ $t('admin.secretStores.usedBy', { count: '' }) }}</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="(secret_store, i) in secret_stores" :key="i" class="table-row">
          <td>{{ secret_store.name }}<va-badge v-if="secret_store.is_default" class="ml-2" :text="$t('admin.secretStores.default')" /></td>
          <td>{{ secret_store.driver === 'openbao' ? $t('admin.secretStores.driverOpenbao') : $t('admin.secretStores.driverDatabase') }}</td>
          <td>{{ secret_store.server_count }}</td>
          <td>
            <va-button
              size="small"
              preset="secondary"
              class="mr-2"
              v-if="secret_store.driver === 'openbao'"
              @click="testForm.post('/admin/settings/secret-stores/' + secret_store.id + '/test')">{{ $t('admin.secretStores.testConnection') }}</va-button>
            <va-button
              size="small"
              preset="secondary"
              class="mr-2"
              @click="showEditModal(secret_store)">{{ $t('common.edit') }}</va-button>
            <va-button
              size="small"
              color="danger"
              :disabled="!secret_store.can_delete"
              @click="showRemoveModal(secret_store)">{{ $t('common.delete') }}</va-button>
          </td>
        </tr>
      </tbody>
    </table>
  </va-scroll-container>

  <va-modal v-model="showRemove" hide-default-actions :title="$t('admin.secretStores.removeTitle', { name: removeName })"
    :message="$t('admin.secretStores.removeMessage', { name: removeName })">
    <template #footer>
      <va-button color="backgroundSecondary" @click="showRemove = false">
        {{ $t('common.cancel') }}
      </va-button>
      <va-button id="delete" color="danger"
        @click="remove.delete('/admin/settings/secret-stores/' + removeId); showRemove = false">{{ $t('common.delete') }}</va-button>
    </template>
  </va-modal>
</template>

<script lang="ts">
export default {
  layout: (h, page) => {
    return h(AppLayout, () => h(SettingsLayout, () => page))
  },
  props: {
    secret_stores: Array,
    errors: Object
  },
  data () {
    return {
      showForm: false,
      showRemove: false,
      editingId: null,
      removeId: null,
      removeName: '',
      form: useForm({
        name: '',
        driver: 'database',
        address: '',
        role_id: '',
        secret_id: '',
        mount_path: '',
        namespace: ''
      }),
      testForm: useForm({}),
      remove: useForm({})
    }
  },
  methods: {
    showAddModal () {
      this.editingId = null
      this.form.reset()
      this.showForm = true
    },
    showEditModal (secret_store) {
      this.editingId = secret_store.id
      this.form.name = secret_store.name
      this.form.driver = secret_store.driver
      this.form.address = secret_store.address
      this.form.role_id = ''
      this.form.secret_id = ''
      this.form.mount_path = secret_store.mount_path
      this.form.namespace = secret_store.namespace
      this.showForm = true
    },
    showRemoveModal (secret_store) {
      this.removeId = secret_store.id
      this.removeName = secret_store.name
      this.showRemove = true
    },
    submit () {
      const onSuccess = () => { this.showForm = false; this.form.reset() }

      if (this.editingId) {
        this.form.put('/admin/settings/secret-stores/' + this.editingId, { onSuccess })
      } else {
        this.form.post('/admin/settings/secret-stores', { onSuccess })
      }
    }
  }
}
</script>

<style lang="scss">
.table-row {
  height: 55px;
}
</style>
