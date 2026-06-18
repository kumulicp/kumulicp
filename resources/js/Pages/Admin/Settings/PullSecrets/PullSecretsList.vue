<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import SettingsLayout from '../SettingsLayout.vue'
import { useForm } from '@inertiajs/vue3'

</script>
<template>
  <Head>
    <title>{{ $t('admin.pullSecrets.pullSecrets') }} - Control Panel</title>
  </Head>
  <div class="row justify-center">
    <va-button id="createPullSecret" class="mr-2" @click="showAddPullSecret = !showAddPullSecret">{{ $t('admin.pullSecrets.addPullSecret') }}</va-button>
    <va-button id="massMigrate" preset="secondary" :disabled="pull_secrets.length < 2" @click="showMassMigrate = !showMassMigrate">{{ $t('admin.pullSecrets.massMigrate') }}</va-button>

    <va-modal v-model="showAddPullSecret" no-outside-dismiss no-padding size="small" class="p-0">
      <template #content="{ ok }">
        <form @submit.prevent="form.post('/admin/settings/pull-secrets', { onSuccess: () => { showAddPullSecret = false; form.reset() } })">
          <va-card-title class="m-0"> {{ $t('admin.pullSecrets.addPullSecret') }} </va-card-title>
          <va-card-content class="m-0">
            <va-input v-model="form.name"
              immediateValidation
              id="name"
              required-mark
              :label="$t('admin.pullSecrets.name')"
              class="mb-3"
              :messages="$t('admin.pullSecrets.nameMessage')"
              :error="$page.props.errors.name"
              :error-messages="$page.props.errors.name" />
            <va-input v-model="form.registry"
              immediateValidation
              id="registry"
              required-mark
              :label="$t('admin.pullSecrets.registry')"
              class="mb-3"
              :messages="$t('admin.pullSecrets.registryMessage')"
              :error="$page.props.errors.registry"
              :error-messages="$page.props.errors.registry" />
            <va-input v-model="form.username"
              immediateValidation
              id="username"
              :label="$t('admin.pullSecrets.username')"
              class="mb-3"
              :error="$page.props.errors.username"
              :error-messages="$page.props.errors.username" />
            <va-input v-model="form.password"
              type="password"
              immediateValidation
              id="password"
              :label="$t('admin.pullSecrets.password')"
              class="mb-3"
              :messages="$t('admin.pullSecrets.passwordMessage')"
              :error="$page.props.errors.password"
              :error-messages="$page.props.errors.password" />
          </va-card-content>
          <va-card-actions align="right" class="">
            <va-button color="textInverted" :disabled="form.processing" @click="ok">{{ $t('common.cancel') }}</va-button>
            <va-button type="submit" :disabled="form.processing" id="submit" class="mr-2 mb-2">{{ $t('common.submit') }}</va-button>
          </va-card-actions>
        </form>
      </template>
    </va-modal>

    <va-modal v-model="showMassMigrate" no-outside-dismiss no-padding size="small" class="p-0">
      <template #content="{ ok }">
        <form @submit.prevent="migrateForm.post('/admin/settings/pull-secrets/mass-migrate', { onSuccess: () => { showMassMigrate = false; migrateForm.reset() } })">
          <va-card-title class="m-0"> {{ $t('admin.pullSecrets.massMigrateTitle') }} </va-card-title>
          <va-card-content class="m-0">
            <p class="mb-3">{{ $t('admin.pullSecrets.massMigrateDescription') }}</p>
            <va-select
              v-model="migrateForm.from_id"
              :label="$t('admin.pullSecrets.migrateFrom')"
              id="from_id"
              class="mb-3"
              immediateValidation
              :options="pull_secrets"
              text-by="name"
              value-by="id"
              :error="$page.props.errors.from_id"
              :error-messages="$page.props.errors.from_id" />
            <va-select
              v-model="migrateForm.to_id"
              :label="$t('admin.pullSecrets.migrateTo')"
              id="to_id"
              class="mb-3"
              immediateValidation
              :options="pull_secrets"
              text-by="name"
              value-by="id"
              :error="$page.props.errors.to_id"
              :error-messages="$page.props.errors.to_id" />
          </va-card-content>
          <va-card-actions align="right" class="">
            <va-button color="textInverted" :disabled="migrateForm.processing" @click="ok">{{ $t('common.cancel') }}</va-button>
            <va-button type="submit" :disabled="migrateForm.processing" id="submitMigrate" class="mr-2 mb-2">{{ $t('common.submit') }}</va-button>
          </va-card-actions>
        </form>
      </template>
    </va-modal>
  </div>

  <va-scroll-container color="primary" horizontal>
    <table class="va-table va-table--hoverable mt-3">
      <thead>
        <tr>
          <th>{{ $t('admin.pullSecrets.name') }}</th>
          <th>{{ $t('admin.pullSecrets.registry') }}</th>
          <th>{{ $t('admin.pullSecrets.authRequired') }}</th>
          <th>{{ $t('admin.pullSecrets.usedBy', { count: '' }) }}</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="(pull_secret, i) in pull_secrets" :key="i" class="table-row">
          <td>{{ pull_secret.name }}</td>
          <td>{{ pull_secret.registry }}</td>
          <td>{{ pull_secret.has_auth ? $t('admin.pullSecrets.authRequired') : $t('admin.pullSecrets.publicRegistry') }}</td>
          <td>{{ pull_secret.version_count }}</td>
          <td>
            <va-button
              size="small"
              color="danger"
              :disabled="!pull_secret.can_delete"
              @click="showRemovePullSecretModal(pull_secret)">{{ $t('common.delete') }}</va-button>
          </td>
        </tr>
      </tbody>
    </table>
  </va-scroll-container>

  <va-modal v-model="showRemovePullSecret" hide-default-actions :title="$t('admin.pullSecrets.removeTitle', { name: removePullSecretName })"
    :message="$t('admin.pullSecrets.removeMessage', { name: removePullSecretName })">
    <template #footer>
      <va-button color="backgroundSecondary" @click="showRemovePullSecret = false">
        {{ $t('common.cancel') }}
      </va-button>
      <va-button id="delete" color="danger"
        @click="remove.delete('/admin/settings/pull-secrets/' + removePullSecretId); showRemovePullSecret = false">{{ $t('common.delete') }}</va-button>
    </template>
  </va-modal>
</template>

<script lang="ts">
export default {
  layout: (h, page) => {
    return h(AppLayout, () => h(SettingsLayout, () => page))
  },
  props: {
    pull_secrets: Array,
    errors: Object
  },
  data () {
    return {
      showAddPullSecret: false,
      showMassMigrate: false,
      showRemovePullSecret: false,
      removePullSecretId: null,
      removePullSecretName: '',
      form: useForm({
        name: '',
        registry: '',
        username: '',
        password: ''
      }),
      migrateForm: useForm({
        from_id: null,
        to_id: null
      }),
      remove: useForm({})
    }
  },
  methods: {
    showRemovePullSecretModal (pull_secret) {
      this.removePullSecretId = pull_secret.id
      this.removePullSecretName = pull_secret.name
      this.showRemovePullSecret = true
    }
  }
}
</script>

<style lang="scss">
.table-row {
  height: 55px;
}
</style>
