<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { Link, useForm } from '@inertiajs/vue3'
import AppsLayout from './AppsLayout.vue'
import AppDomainFields from '@/components/AppDomainFields.vue'
import ChangeSummary from '@/components/updates/ChangeSummary.vue'

</script>
<template>
  <Head>
    <title>{{ $t('admin.apps.pageTitle') }}</title>
  </Head>
    <va-list>
      <va-list-item class="py-3">
        <va-list-item-section label>
          <va-list-item-label>
            <h5>{{ $t('admin.apps.domain') }}</h5>
          </va-list-item-label>
        </va-list-item-section>
        <va-list-item-section>
          <va-list-item-label id="domain">
            {{ domain.name }}
            <va-icon
              id="editDomain"
              name="edit"
              size="small"
              color="primary"
              class="clickable-icon ml-2"
              role="button"
              :aria-label="$t('admin.apps.editDomain')"
              @click="showDomainModal = true"
            />
          </va-list-item-label>
        </va-list-item-section>
      </va-list-item>
    </va-list>
    <va-list-separator class="my-4" fit />

    <template v-if="!app.managed_externally">
    <va-alert v-if="updates.length > 0" id="outOfDate" color="warning" icon="warning" class="mb-3">
      <div>{{ $t('admin.updates.appOutOfDate', { aspects: updates.map(update => update.label).join(', ') }) }}</div>
      <div v-for="change in changes" :key="change ? change.id : 'none'" class="secondary">
        <ChangeSummary :change="change" />
      </div>
      <div>{{ $t('admin.updates.appOutOfDateHelp') }}</div>
    </va-alert>
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
    </template>
    <va-modal v-model="showDomainModal"
    hide-default-actions
    no-padding
    class="p-0"
    >
      <template #content="{ ok }">
        <form @submit.prevent="domainForm.put('/admin/organizations/'+organization.id+'/apps/'+app.id+'/domain', { onSuccess: () => { showDomainModal = false } })">
          <va-card-title class="m-0">{{ $t('admin.apps.editDomain') }}</va-card-title>
          <va-card-content class="m-0">
            <va-alert v-if="domain.deployed" id="domainRestartWarning" color="warning" icon="warning" class="mb-3">
              {{ $t('admin.apps.domainRestartWarning') }}
            </va-alert>
            <va-alert v-if="!domain.editable" id="domainNotEditableWarning" color="danger" icon="error" class="mb-3">
              {{ $t('admin.apps.domainNotEditableWarning') }}
            </va-alert>
            <AppDomainFields
              v-model:domain="domainForm.domain"
              v-model:subdomain="domainForm.subdomain"
              v-model:parent-domain="domainForm.parent_domain"
              :domains="domain.domains"
              :parent-domains="domain.parent_domains"
            />
          </va-card-content>
          <va-card-actions align="right" class="">
            <va-button color="textInverted" :disabled="domainForm.processing" @click="ok">{{ $t('common.cancel') }}</va-button>
            <va-button type="submit" id="saveDomain" class="mr-2" :disabled="domainForm.processing">{{ $t('common.update') }}</va-button>
          </va-card-actions>
        </form>
      </template>
    </va-modal>
    <va-modal v-model="showUpdateAppModal"
    hide-default-actions
    no-padding
    class="p-0"
    >
      <template #content="{ ok }">
        <va-card-title class="m-0">{{ $t('admin.apps.update') }}</va-card-title>
        <va-card-content class="m-0">
          <p class="mb-3">{{ $t('admin.apps.updateModal') }}</p>
          <p v-if="update_steps.length === 0">{{ $t('admin.apps.updateNoSteps') }}</p>
          <template v-else>
            <va-checkbox
              v-model="allStepsSelected"
              :indeterminate="someStepsSelected && !allStepsSelected"
              :label="$t('admin.apps.updateSelectAll')"
              class="mb-2"
            />
            <va-divider class="my-2" />
            <div v-for="step in update_steps" :key="step.key" class="mb-2">
              <va-checkbox v-model="updateForm.steps" :array-value="step.key" :label="step.label" />
              <va-badge v-if="step.out_of_date" :id="'outOfDate-' + step.key" class="ml-2" color="warning" :text="$t('admin.apps.updateOutOfDate')" />
              <div v-if="step.description" class="va-text-secondary ml-8">{{ step.description }}</div>
            </div>
          </template>
        </va-card-content>
        <va-card-actions align="right" class="">
          <va-button color="textInverted" :disabled="updateForm.processing" @click="ok">{{ $t('common.cancel') }}</va-button>
          <va-button
            class="mr-2"
            :disabled="updateForm.processing || updateForm.steps.length === 0"
            @click="runUpdate(ok)"
          >{{ $t('common.yes') }}</va-button>
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
    domain: Object,
    versions: Object,
    update_steps: {
      type: Array,
      default: () => []
    },
    updates: {
      type: Array,
      default: () => []
    },
    database_servers: Array
  },
  computed: {
    // Each edit once, however many things it put out of date
    changes () {
      const seen = new Map()
      this.updates.forEach(update => seen.set(update.change ? update.change.id : 'none', update.change))

      return [...seen.values()]
    },
    allStepsSelected: {
      get () {
        return this.update_steps.length > 0 && this.updateForm.steps.length === this.update_steps.length
      },
      set (selected) {
        this.updateForm.steps = selected ? this.update_steps.map(step => step.key) : []
      }
    },
    someStepsSelected () {
      return this.updateForm.steps.length > 0
    },
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
      updateForm: useForm({
        steps: this.update_steps.filter(step => step.checked).map(step => step.key)
      }),
      showDomainModal: false,
      domainForm: useForm({
        domain: this.domain.id,
        subdomain: '',
        parent_domain: null
      }),
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
  },
  methods: {
    runUpdate (close) {
      this.updateForm.post('/admin/organizations/' + this.organization.id + '/apps/' + this.app.id + '/update', {
        onSuccess: () => close()
      })
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
