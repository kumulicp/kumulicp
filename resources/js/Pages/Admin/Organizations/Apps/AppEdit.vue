<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { Link, useForm } from '@inertiajs/vue3'
import AppsLayout from './AppsLayout.vue'

</script>
<template>
  <Head>
    <title>{{ $t('admin.apps.pageTitle') }}</title>
  </Head>
    <div class="row justify-center">
        <VaButtonGroup>
        <va-button @click="showUpdateAppModal = true">{{ $t('admin.apps.update') }}</va-button>
        <va-button @click="showUpgradeAppModal = true">{{ $t('admin.apps.upgrade') }}</va-button>
        <va-button v-if="app.database_server" @click="showMigrateDatabaseModal = true">{{ $t('admin.apps.migrateDatabase') }}</va-button>
        <va-button color="danger" @click="showDeleteAppModal = true">{{ $t('admin.apps.delete') }}</va-button>
        </VaButtonGroup>
    </div>
    <va-list-separator class="my-4" fit />

    <va-list>
        <va-list-item class="py-3">
        <va-list-item-section label>
            <va-list-item-label>
            <h5>{{ $t('admin.apps.apiPassword') }}</h5>
            </va-list-item-label>
        </va-list-item-section>
        <va-list-item-section>
            <va-list-item-label>
            <va-input
                :model-value="app.password"
                :type="isPasswordVisible ? 'text' : 'password'"
                placeholder="#########"
                immediateValidation
                @click-append-inner="isPasswordVisible = !isPasswordVisible"
                readonly
            >
                <template #appendInner>
                <va-icon
                    :name="isPasswordVisible ? 'visibility_off' : 'visibility'"
                    size="small"
                    color="primary"
                />
                </template>
            </va-input>
            </va-list-item-label>
        </va-list-item-section>
        </va-list-item>
    </va-list>
    <form @submit.prevent="form.put('/admin/organizations/'+organization.id+'/apps/'+app.id)">
        <div class="row mt-3">
        <va-textarea
            v-model="form.settings"
            class="flex flex-col sm12"
            :label="$t('admin.apps.jsonSettings')"
            rows="50"
            placeholder="{}"
            min-rows="30"
            max-rows="50"
            :error="$page.props.errors.settings"
            :error-messages="$page.props.errors.settings"
            />
        <div v-if="$page.props.errors.settings">
            {{ $page.props.errors.settings }}
        </div>
        </div>
        <va-button type="submit" id="submit" class="mr-2 mb-2" :disabled="form.processing">{{ $t('common.update') }}</va-button>
    </form>
    <va-modal v-model="showUpdateAppModal"
    hide-default-actions
    no-padding
    class="p-0"
    >
      <template #content="{ ok }">
        <va-card-title class="m-0">{{ $t('admin.apps.update') }}</va-card-title>
        <va-card-content class="m-0 p-0">
          {{ $t('admin.apps.updateModal') }}
        </va-card-content>
        <va-card-actions align="right" class="">
          <va-button color="textInverted" :disabled="form.processing" @click="ok">{{ $t('common.cancel') }}</va-button>
          <Link :href="'/admin/organizations/'+organization.id+'/apps/'+app.id+'/update'"><va-button type="submit" id="submit" class="mr-2" :disabled="form.processing">{{ $t('common.yes') }}</va-button></Link>
        </va-card-actions>
      </template>
    </va-modal>
    <va-modal v-model="showUpgradeAppModal"
    hide-default-actions
    no-padding
    class="p-0"
    >
      <template #content="{ ok }">
        <va-card-title class="m-0">{{ $t('admin.apps.upgrade') }}</va-card-title>
        <va-card-content class="m-0 p-0">
            {{ $t('admin.apps.upgradeModal') }}

            <va-radio v-model="upgradeType" class="mt-3" :options="upgradeTypes" value-by="value" />
            <va-select
              v-if="upgradeType === 'version'"
              v-model="upgradeVersionId"
              class="mt-3"
              :label="$t('admin.apps.upgradeVersion')"
              :options="versionOptions"
              text-by="text"
              value-by="value"
              required-mark
            />
        </va-card-content>
        <va-card-actions align="right" class="">
            <va-button color="textInverted" @click="ok">{{ $t('common.cancel') }}</va-button>
            <Link :href="upgradeUrl"><va-button type="submit" id="submit" class="mr-2" :disabled="upgradeType === 'version' && !upgradeVersionId">{{ $t('common.yes') }}</va-button></Link>
        </va-card-actions>
      </template>
    </va-modal>
    <va-modal v-model="showMigrateDatabaseModal"
    hide-default-actions
    no-padding
    class="p-0"
    >
      <template #content="{ ok }">
        <form @submit.prevent="migrateForm.post('/admin/organizations/'+organization.id+'/apps/'+app.id+'/migrate-database', { onSuccess: () => { showMigrateDatabaseModal = false } })">
          <va-card-title class="m-0">{{ $t('admin.apps.migrateDatabaseTitle') }}</va-card-title>
          <va-card-content class="m-0 p-0">
            <p v-if="app.database_server" class="mb-2">{{ $t('admin.apps.migrateDatabaseCurrent', { server: app.database_server }) }}</p>
            <p>{{ $t('admin.apps.migrateDatabaseDescription') }}</p>
            <p class="mt-2"><strong>{{ $t('admin.apps.migrateDatabaseWarning') }}</strong></p>
            <p v-if="app.status !== 'active'" class="mt-2">{{ $t('admin.apps.migrateDatabaseNotActive') }}</p>
            <p v-else-if="database_servers.length === 0" class="mt-2">{{ $t('admin.apps.migrateDatabaseNoServers') }}</p>
            <va-select
              v-else
              v-model="migrateForm.database_server_id"
              class="mt-3"
              :label="$t('admin.apps.migrateDatabaseTarget')"
              :options="database_servers"
              text-by="text"
              value-by="value"
              required-mark
              :error="!!$page.props.errors.database_server_id"
              :error-messages="$page.props.errors.database_server_id"
            />
          </va-card-content>
          <va-card-actions align="right" class="">
            <va-button color="textInverted" :disabled="migrateForm.processing" @click="ok">{{ $t('common.cancel') }}</va-button>
            <va-button type="submit" class="mr-2" :disabled="migrateForm.processing || !migrateForm.database_server_id || app.status !== 'active'">{{ $t('admin.apps.migrateDatabase') }}</va-button>
          </va-card-actions>
        </form>
      </template>
    </va-modal>
    <va-modal v-model="showDeleteAppModal"
    hide-default-actions
    no-padding
    class="p-0"
    >
      <template #content="{ ok }">
        <va-card-title class="m-0">{{ $t('admin.apps.delete') }}</va-card-title>
        <va-card-content class="m-0 p-0">
            {{ $t('admin.apps.deleteModal') }}

            <div class="row mt-3">
              <div class="flex flex-col xs12">
                {{ $t('admin.apps.deleteNowOrLater') }}
              </div>
            </div>
            <va-radio v-model="deleteApp.when" :options="deleteWhen" value-by="value" />
            <div v-if="deleteApp.when === 'later'" class="row mt-3">
              <div class="flex flex-col xs6 pr-2">
                <va-input v-model="deleteApp.start_time"
                  :label="$t('admin.apps.deleteStart')"
                  type="time"
                  />
              </div>
              <div class="flex flex-col xs6 pl-2">
                <va-input v-model="deleteApp.end_time"
                  :label="$t('admin.apps.deleteEnd')"
                  type="time"
                  />
              </div>
            </div>
        </va-card-content>
        <va-card-actions align="right" class="">
            <va-button color="textInverted" :disabled="form.processing" @click="ok">{{ $t('common.cancel') }}</va-button>
            <va-button color="danger" type="submit" id="submit" class="mr-2" @click="deleteApp.delete('/admin/organizations/'+organization.id+'/apps/'+app.id)" :disabled="form.processing">{{ $t('common.yes') }}</va-button>
        </va-card-actions>
      </template>
    </va-modal>
</template>

<script lang="ts">
export default {
  layout: (h, page) => {
    return h(AppLayout, () => h(AppsLayout, () => page))
  },
  props: {
    app: Object,
    organization: Object,
    versions: Object,
    database_servers: Array
  },
  computed: {
    versionOptions () {
      return this.versions.map(version => ({ text: version.version, value: version.id }))
    },
    upgradeUrl () {
      const versionId = this.upgradeType === 'version' ? this.upgradeVersionId : this.app.version.id

      return '/admin/organizations/' + this.organization.id + '/apps/' + this.app.id + '/upgrade/' + versionId
    }
  },
  data () {
    const settings = this.app.settings ? JSON.stringify(this.app.settings, '', 2) : '{}'

    return {
      upgradeType: 'current',
      upgradeVersionId: null,
      upgradeTypes: [
        {
          text: this.$t('admin.apps.upgradeCurrent'),
          value: 'current'
        },
        {
          text: this.$t('admin.apps.upgradeNewVersion'),
          value: 'version'
        }
      ],
      form: useForm({
        settings
      }),
      isPasswordVisible: false,
      showUpdateAppModal: false,
      showUpgradeAppModal: false,
      showDeleteAppModal: false,
      showMigrateDatabaseModal: false,
      migrateForm: useForm({
        database_server_id: null
      }),
      deleteApp: useForm({
        when: 'later',
        start_time: '',
        end_time: ''
      }),
      deleteWhen: [
        {
          text: this.$t('admin.apps.deleteNow'),
          value: 'now'
        },
        {
          text: this.$t('admin.apps.deleteLater'),
          value: 'later'
        }
      ]
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
