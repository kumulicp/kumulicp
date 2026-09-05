<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import SettingsLayout from '../SettingsLayout.vue'
import { useForm } from '@inertiajs/vue3'

</script>
<template>
  <Head>
    <title>{{ $t('admin.repoSecrets.repoSecrets') }} - Control Panel</title>
  </Head>
  <div class="row justify-center">
    <va-button id="createRepoSecret" class="mr-2" @click="showAddRepoSecret = !showAddRepoSecret">{{ $t('admin.repoSecrets.addRepoSecret') }}</va-button>
    <va-button id="massMigrate" preset="secondary" :disabled="repo_secrets.length < 2" @click="showMassMigrate = !showMassMigrate">{{ $t('admin.repoSecrets.massMigrate') }}</va-button>

    <va-modal v-model="showAddRepoSecret" no-outside-dismiss no-padding size="small" class="p-0">
      <template #content="{ ok }">
        <form @submit.prevent="form.post('/admin/settings/repo-secrets', { onSuccess: () => { showAddRepoSecret = false; form.reset() } })">
          <va-card-title class="m-0"> {{ $t('admin.repoSecrets.addRepoSecret') }} </va-card-title>
          <va-card-content class="m-0">
            <va-select
              v-model="form.type"
              immediateValidation
              id="type"
              required-mark
              :label="$t('admin.repoSecrets.type')"
              class="mb-3"
              :options="types"
              text-by="text"
              value-by="value"
              :error="$page.props.errors.type"
              :error-messages="$page.props.errors.type" />
            <va-input v-model="form.name"
              immediateValidation
              id="name"
              required-mark
              :label="$t('admin.repoSecrets.name')"
              class="mb-3"
              :messages="$t('admin.repoSecrets.nameMessage')"
              :error="$page.props.errors.name"
              :error-messages="$page.props.errors.name" />
            <va-input v-model="form.registry"
              immediateValidation
              id="registry"
              required-mark
              :label="$t('admin.repoSecrets.registry')"
              class="mb-3"
              :messages="$t('admin.repoSecrets.registryMessage')"
              :error="$page.props.errors.registry"
              :error-messages="$page.props.errors.registry" />
            <va-input v-model="form.username"
              immediateValidation
              id="username"
              :label="$t('admin.repoSecrets.username')"
              class="mb-3"
              :error="$page.props.errors.username"
              :error-messages="$page.props.errors.username" />
            <va-input v-model="form.password"
              type="password"
              immediateValidation
              id="password"
              :label="$t('admin.repoSecrets.password')"
              class="mb-3"
              :messages="$t('admin.repoSecrets.passwordMessage')"
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
        <form @submit.prevent="migrateForm.post('/admin/settings/repo-secrets/mass-migrate', { onSuccess: () => { showMassMigrate = false; migrateForm.reset() } })">
          <va-card-title class="m-0"> {{ $t('admin.repoSecrets.massMigrateTitle') }} </va-card-title>
          <va-card-content class="m-0">
            <p class="mb-3">{{ $t('admin.repoSecrets.massMigrateDescription') }}</p>
            <va-select
              v-model="migrateForm.from_id"
              :label="$t('admin.repoSecrets.migrateFrom')"
              id="from_id"
              class="mb-3"
              immediateValidation
              :options="repo_secrets"
              text-by="name"
              value-by="id"
              :error="$page.props.errors.from_id"
              :error-messages="$page.props.errors.from_id" />
            <va-select
              v-model="migrateForm.to_id"
              :label="$t('admin.repoSecrets.migrateTo')"
              id="to_id"
              class="mb-3"
              immediateValidation
              :options="migrateToOptions"
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
          <th>{{ $t('admin.repoSecrets.type') }}</th>
          <th>{{ $t('admin.repoSecrets.name') }}</th>
          <th>{{ $t('admin.repoSecrets.registry') }}</th>
          <th>{{ $t('admin.repoSecrets.authRequired') }}</th>
          <th>{{ $t('admin.repoSecrets.usedBy', { count: '' }) }}</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="(repo_secret, i) in repo_secrets" :key="i" class="table-row">
          <td>{{ typeLabel(repo_secret.type) }}</td>
          <td>{{ repo_secret.name }}</td>
          <td>{{ repo_secret.registry }}</td>
          <td>{{ repo_secret.has_auth ? $t('admin.repoSecrets.authRequired') : $t('admin.repoSecrets.publicRegistry') }}</td>
          <td>{{ repo_secret.version_count }}</td>
          <td>
            <va-button
              size="small"
              color="danger"
              :disabled="!repo_secret.can_delete"
              @click="showRemoveRepoSecretModal(repo_secret)">{{ $t('common.delete') }}</va-button>
          </td>
        </tr>
      </tbody>
    </table>
  </va-scroll-container>

  <va-modal v-model="showRemoveRepoSecret" hide-default-actions :title="$t('admin.repoSecrets.removeTitle', { name: removeRepoSecretName })"
    :message="$t('admin.repoSecrets.removeMessage', { name: removeRepoSecretName })">
    <template #footer>
      <va-button color="backgroundSecondary" @click="showRemoveRepoSecret = false">
        {{ $t('common.cancel') }}
      </va-button>
      <va-button id="delete" color="danger"
        @click="remove.delete('/admin/settings/repo-secrets/' + removeRepoSecretId); showRemoveRepoSecret = false">{{ $t('common.delete') }}</va-button>
    </template>
  </va-modal>
</template>

<script lang="ts">
export default {
  layout: (h, page) => {
    return h(AppLayout, () => h(SettingsLayout, () => page))
  },
  props: {
    repo_secrets: Array,
    errors: Object
  },
  data () {
    return {
      showAddRepoSecret: false,
      showMassMigrate: false,
      showRemoveRepoSecret: false,
      removeRepoSecretId: null,
      removeRepoSecretName: '',
      types: [
        { value: 'image', text: this.$t('admin.repoSecrets.typeImage') },
        { value: 'helm', text: this.$t('admin.repoSecrets.typeHelm') },
        { value: 'both', text: this.$t('admin.repoSecrets.typeBoth') }
      ],
      form: useForm({
        type: 'image',
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
  computed: {
    migrateToOptions () {
      const from = this.repo_secrets.find(s => s.id === this.migrateForm.from_id)
      if (!from) return this.repo_secrets
      return this.repo_secrets.filter(s => s.type === from.type && s.id !== from.id)
    }
  },
  methods: {
    showRemoveRepoSecretModal (repo_secret) {
      this.removeRepoSecretId = repo_secret.id
      this.removeRepoSecretName = repo_secret.name
      this.showRemoveRepoSecret = true
    },
    typeLabel (type) {
      const found = this.types.find(t => t.value === type)
      return found ? found.text : type
    }
  }
}
</script>

<style lang="scss">
.table-row {
  height: 55px;
}
</style>
