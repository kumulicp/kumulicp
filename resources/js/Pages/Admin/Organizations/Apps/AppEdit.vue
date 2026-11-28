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
    <va-alert v-if="awaiting" id="awaiting" color="warning" icon="warning" class="mb-3">
      <div>{{ awaiting.message }}</div>
      <va-button id="confirmStep" size="small" class="mt-2" :disabled="confirmForm.processing" @click="confirmForm.post('/admin/organizations/'+organization.id+'/apps/'+app.id+'/confirm-step')">{{ $t('admin.apps.confirmStep') }}</va-button>
    </va-alert>
    <va-alert v-if="updates.length > 0" id="outOfDate" color="warning" icon="warning" class="mb-3">
      <div>{{ $t('admin.updates.appOutOfDate', { aspects: updates.map(update => update.label).join(', ') }) }}</div>
      <div v-for="change in changes" :key="change ? change.id : 'none'" class="secondary">
        <ChangeSummary :change="change" />
      </div>
      <div>{{ $t('admin.updates.appOutOfDateHelp') }}</div>
    </va-alert>
    <va-alert v-if="ignored_image_settings.length > 0" id="ignoredImageSettings" color="warning" icon="warning" class="mb-3">
      <div>{{ $t('admin.apps.ignoredImageSettings') }}</div>
      <ul class="ml-4" style="list-style: disc">
        <li v-for="item in ignored_image_settings" :key="item.setting">{{ $t('admin.apps.ignoredImageSetting', { setting: item.setting, value: item.value, image: item.image, used: item.used }) }}</li>
      </ul>
    </va-alert>
    <va-alert v-if="maintenance.enabled" id="maintenanceActive" color="warning" icon="build" class="mb-3">
      {{ $t('admin.apps.maintenanceActive') }}
    </va-alert>
    <div class="row justify-center">
        <VaButtonGroup>
        <va-button @click="showUpdateAppModal = true">{{ $t('admin.apps.update') }}</va-button>
        <va-button @click="showUpgradeAppModal = true">{{ $t('admin.apps.upgrade') }}</va-button>
        <va-button v-if="app.database_server" @click="showMigrateDatabaseModal = true">{{ $t('admin.apps.migrateDatabase') }}</va-button>
        <va-button
          v-if="maintenance.available"
          id="toggleMaintenance"
          :disabled="app.status !== 'active'"
          @click="showMaintenanceModal = true"
        >{{ maintenance.enabled ? $t('admin.apps.disableMaintenance') : $t('admin.apps.enableMaintenance') }}</va-button>
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
    <va-list id="servers" class="my-2">
      <va-list-item v-for="row in serverRows" :key="row.type" class="py-2">
        <va-list-item-section label>
          <va-list-item-label>
            <h5>{{ row.label }}</h5>
          </va-list-item-label>
        </va-list-item-section>
        <va-list-item-section>
          <va-list-item-label :id="row.type + 'Server'">
            {{ row.name ?? $t('admin.apps.noServer') }}
            <va-button v-if="row.type === 'database' && app.database_server" id="migrateDatabase" size="small" class="ml-3" @click="showMigrateDatabaseModal = true">{{ $t('admin.apps.migrateDatabase') }}</va-button>
            <Link v-if="row.type === 'web'" :href="'/admin/organizations/'+organization.id+'/apps/'+app.id+'/move'">
              <va-button id="moveWeb" size="small" class="ml-3">{{ $t('admin.apps.moveWeb') }}</va-button>
            </Link>
            <div v-for="entry in (row.type === 'web' ? old_web_entries : [])" :key="entry.id" class="oldWebEntry mt-1">
              {{ $t('admin.apps.oldWebEntry', { server: entry.server }) }}
              <va-button size="small" preset="secondary" class="ml-2" @click="askRemoveOldWeb(entry)">{{ $t('admin.apps.removeOldWeb') }}</va-button>
            </div>
            <va-button v-if="row.type === 'sso'" id="moveSso" size="small" class="ml-3" :disabled="!app.supports_sso" @click="showMoveSsoModal = true">{{ $t('admin.apps.moveSso') }}</va-button>
            <span v-if="row.type === 'sso' && !app.supports_sso" id="moveSsoUnsupported" class="secondary ml-2">{{ $t('admin.apps.moveSsoUnsupported') }}</span>
            <div v-for="entry in (row.type === 'sso' ? old_sso_entries : [])" :key="entry.id" class="oldSsoEntry mt-1">
              {{ $t('admin.apps.oldSsoEntry', { server: entry.server }) }}
              <va-button size="small" preset="secondary" class="ml-2" @click="askRemoveOldSso(entry)">{{ $t('admin.apps.removeOldSso') }}</va-button>
            </div>
          </va-list-item-label>
        </va-list-item-section>
      </va-list-item>
    </va-list>
    <va-collapse v-model="showRepair" id="reassignServers" :header="$t('admin.apps.reassignServers')" class="mb-3">
      <form @submit.prevent="repairForm.put('/admin/organizations/'+organization.id+'/apps/'+app.id)">
        <va-alert color="warning" icon="warning" class="mb-3">{{ $t('admin.apps.serversNote') }}</va-alert>
        <va-select
          v-model="repairForm.database_server_id"
          class="mb-3"
          :label="$t('admin.apps.databaseServer')"
          :options="server_options.database ?? []"
          text-by="name"
          value-by="id"
          :error="!!$page.props.errors.database_server_id"
          :error-messages="$page.props.errors.database_server_id"
        />
        <va-select
          v-model="repairForm.web_server_id"
          class="mb-3"
          :label="$t('admin.apps.appServer')"
          :options="server_options.web ?? []"
          text-by="name"
          value-by="id"
          :error="!!$page.props.errors.web_server_id"
          :error-messages="$page.props.errors.web_server_id"
        />
        <va-select
          v-model="repairForm.sso_server_id"
          class="mb-3"
          :label="$t('admin.apps.ssoServer')"
          :options="server_options.sso ?? []"
          text-by="name"
          value-by="id"
          :error="!!$page.props.errors.sso_server_id"
          :error-messages="$page.props.errors.sso_server_id"
        />
        <va-checkbox v-model="confirmRepair" id="confirmReassign" class="mb-3" :label="$t('admin.apps.reassignConfirm')" />
        <div>
          <va-button type="submit" id="reassignSubmit" :disabled="!confirmRepair || repairForm.processing">{{ $t('admin.apps.reassignSave') }}</va-button>
        </div>
      </form>
    </va-collapse>
    <va-list-separator class="my-3" fit />
    <va-card v-if="volumes.length" id="volumes" class="mt-3 mb-3">
      <va-card-title>{{ $t('admin.volumes.title') }}</va-card-title>
      <va-card-content>
        <div v-for="volume in volumes" :key="volume.name" :id="'volume-' + volume.name" class="mb-4">
          <h4 class="va-h6">{{ $t(volume.label) }}</h4>
          <ul class="mb-2">
            <li>{{ $t('admin.volumes.enabled') }}: {{ volume.effective.enabled ? $t('admin.volumes.on') : $t('admin.volumes.off') }}</li>
            <template v-if="volume.effective.enabled">
              <li v-if="volume.follows_quota">{{ $t('admin.volumes.quota_size', { size: volume.effective.size }) }}</li>
              <li v-else>{{ $t('admin.volumes.size') }}: {{ volume.effective.size }}</li>
              <li>{{ $t('admin.volumes.storage_class') }}: {{ volume.effective.storage_class || $t('admin.volumes.default') }}</li>
              <li v-if="volume.effective.access_mode">{{ $t('admin.volumes.access_mode') }}: {{ volume.effective.access_mode }}</li>
              <li v-if="volume.takes_claim">{{ $t('admin.volumes.claim') }}: {{ volume.effective.claim || $t('admin.volumes.none') }}</li>
            </template>
          </ul>
          <p v-if="volume.locked" class="va-text-secondary mb-2">{{ $t('admin.volumes.locked') }}</p>
          <va-button v-else :id="'set-up-' + volume.name" size="small" class="mr-2" @click="openVolume(volume)">{{ $t('admin.volumes.set_up') }}</va-button>
          <va-button v-if="volume.can_change_claim" :id="'change-claim-' + volume.name" size="small" preset="secondary" @click="openClaim(volume)">{{ $t('admin.volumes.change_claim') }}</va-button>
        </div>
      </va-card-content>
    </va-card>
    <va-modal v-model="showVolumeModal" hide-default-actions no-padding class="p-0">
      <template #content>
        <form v-if="editingVolume" id="volumeForm" class="p-4" @submit.prevent="volumeForm.put(volumeUrl(), { onSuccess: () => { showVolumeModal = false } })">
          <h3 class="va-h5 mb-1">{{ $t(editingVolume.label) }}</h3>
          <p class="va-text-secondary mb-3">{{ $t('admin.volumes.set_once') }}</p>
          <va-switch v-model="volumeForm.enabled" class="mb-3" :label="$t('admin.volumes.enabled')" />
          <va-input v-if="!editingVolume.follows_quota" v-model="volumeForm.size" type="number" min="1" class="mb-3" :label="$t('admin.volumes.size')" :placeholder="String(editingVolume.effective.size)" :error="!!volumeForm.errors.size" :error-messages="volumeForm.errors.size" />
          <va-input v-model="volumeForm.storage_class" class="mb-3" :label="$t('admin.volumes.storage_class')" :placeholder="editingVolume.effective.storage_class" :error="!!volumeForm.errors.storage_class" :error-messages="volumeForm.errors.storage_class" />
          <va-select v-model="volumeForm.access_mode" class="mb-3" :options="editingVolume.access_modes" clearable :label="$t('admin.volumes.access_mode')" :placeholder="editingVolume.effective.access_mode" />
          <va-button type="submit" id="saveVolume" :disabled="volumeForm.processing">{{ $t('common.save') }}</va-button>
          <va-button preset="secondary" class="ml-2" @click="showVolumeModal = false">{{ $t('common.cancel') }}</va-button>
        </form>
      </template>
    </va-modal>
    <va-modal v-model="showClaimModal" hide-default-actions no-padding class="p-0">
      <template #content>
        <form v-if="editingVolume" id="claimForm" class="p-4" @submit.prevent="claimForm.put(volumeUrl() + '/claim', { onSuccess: () => { showClaimModal = false } })">
          <h3 class="va-h5 mb-1">{{ $t('admin.volumes.change_claim') }}</h3>
          <p class="va-text-secondary mb-3">{{ $t('admin.volumes.claim_warning') }}</p>
          <va-input v-model="claimForm.claim" class="mb-3" :label="$t('admin.volumes.claim')" :error="!!claimForm.errors.claim" :error-messages="claimForm.errors.claim" />
          <va-button type="submit" id="saveClaim" :disabled="claimForm.processing">{{ $t('common.save') }}</va-button>
          <va-button preset="secondary" class="ml-2" @click="showClaimModal = false">{{ $t('common.cancel') }}</va-button>
        </form>
      </template>
    </va-modal>
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
    <va-modal v-model="showRemoveOldSsoModal"
    hide-default-actions
    no-padding
    class="p-0"
    >
      <template #content="{ ok }">
        <form @submit.prevent="removeOldSsoForm.post('/admin/organizations/'+organization.id+'/apps/'+app.id+'/remove-old-sso', { onSuccess: () => { showRemoveOldSsoModal = false } })">
          <va-card-title class="m-0">{{ $t('admin.apps.removeOldSsoTitle') }}</va-card-title>
          <va-card-content class="m-0 p-0">
            <p>{{ $t('admin.apps.removeOldSsoDescription', { server: removeOldSsoServer }) }}</p>
          </va-card-content>
          <va-card-actions align="right" class="">
            <va-button color="textInverted" :disabled="removeOldSsoForm.processing" @click="ok">{{ $t('common.cancel') }}</va-button>
            <va-button type="submit" color="danger" class="mr-2" :disabled="removeOldSsoForm.processing">{{ $t('admin.apps.removeOldSso') }}</va-button>
          </va-card-actions>
        </form>
      </template>
    </va-modal>
    <va-modal v-model="showRemoveOldWebModal"
    hide-default-actions
    no-padding
    class="p-0"
    >
      <template #content="{ ok }">
        <form @submit.prevent="removeOldWebForm.post('/admin/organizations/'+organization.id+'/apps/'+app.id+'/remove-old-release', { onSuccess: () => { showRemoveOldWebModal = false } })">
          <va-card-title class="m-0">{{ $t('admin.apps.removeOldWebTitle') }}</va-card-title>
          <va-card-content class="m-0 p-0">
            <p>{{ $t('admin.apps.removeOldWebDescription', { server: removeOldWebServer }) }}</p>
          </va-card-content>
          <va-card-actions align="right" class="">
            <va-button color="textInverted" :disabled="removeOldWebForm.processing" @click="ok">{{ $t('common.cancel') }}</va-button>
            <va-button type="submit" color="danger" class="mr-2" :disabled="removeOldWebForm.processing">{{ $t('admin.apps.removeOldWeb') }}</va-button>
          </va-card-actions>
        </form>
      </template>
    </va-modal>
    <va-modal v-model="showMoveSsoModal"
    hide-default-actions
    no-padding
    class="p-0"
    >
      <template #content="{ ok }">
        <form @submit.prevent="moveSsoForm.post('/admin/organizations/'+organization.id+'/apps/'+app.id+'/move-sso', { onSuccess: () => { showMoveSsoModal = false } })">
          <va-card-title class="m-0">{{ $t('admin.apps.moveSsoTitle') }}</va-card-title>
          <va-card-content class="m-0 p-0">
            <p v-if="app.sso_server" class="mb-2">{{ $t('admin.apps.moveSsoCurrent', { server: app.sso_server }) }}</p>
            <p>{{ $t('admin.apps.moveSsoDescription') }}</p>
            <p class="mt-2"><strong>{{ $t('admin.apps.moveSsoWarning') }}</strong></p>
            <p v-if="app.status !== 'active'" class="mt-2">{{ $t('admin.apps.moveSsoNotActive') }}</p>
            <p v-else-if="sso_servers.length === 0" class="mt-2">{{ $t('admin.apps.moveSsoNoServers') }}</p>
            <va-select
              v-else
              v-model="moveSsoForm.sso_server_id"
              class="mt-3"
              :label="$t('admin.apps.moveSsoTarget')"
              :options="sso_servers"
              text-by="text"
              value-by="value"
              required-mark
              :error="!!$page.props.errors.sso_server_id"
              :error-messages="$page.props.errors.sso_server_id"
            />
          </va-card-content>
          <va-card-actions align="right" class="">
            <va-button color="textInverted" :disabled="moveSsoForm.processing" @click="ok">{{ $t('common.cancel') }}</va-button>
            <va-button type="submit" class="mr-2" :disabled="moveSsoForm.processing || !moveSsoForm.sso_server_id || app.status !== 'active'">{{ $t('admin.apps.moveSso') }}</va-button>
          </va-card-actions>
        </form>
      </template>
    </va-modal>
    <va-modal v-model="showMaintenanceModal"
    hide-default-actions
    no-padding
    class="p-0"
    >
      <template #content="{ ok }">
        <form @submit.prevent="toggleMaintenance(ok)">
          <va-card-title class="m-0">{{ maintenance.enabled ? $t('admin.apps.maintenanceDisableTitle') : $t('admin.apps.maintenanceEnableTitle') }}</va-card-title>
          <va-card-content class="m-0 p-0">
            {{ maintenance.enabled ? $t('admin.apps.maintenanceDisableModal') : $t('admin.apps.maintenanceEnableModal') }}
          </va-card-content>
          <va-card-actions align="right" class="">
            <va-button color="textInverted" :disabled="maintenanceForm.processing" @click="ok">{{ $t('common.cancel') }}</va-button>
            <va-button type="submit" id="confirmMaintenance" class="mr-2" :disabled="maintenanceForm.processing">{{ $t('common.yes') }}</va-button>
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
    volumes: {
      type: Array,
      default: () => []
    },
    ignored_image_settings: {
      type: Array,
      default: () => []
    },
    database_servers: Array,
    sso_servers: {
      type: Array,
      default: () => []
    },
    old_web_entries: {
      type: Array,
      default: () => []
    },
    old_sso_entries: {
      type: Array,
      default: () => []
    },
    awaiting: {
      type: Object,
      default: null
    },
    maintenance: {
      type: Object,
      default: () => ({ enabled: false, available: false })
    },
    server_options: {
      type: Object,
      default: () => ({})
    }
  },
  computed: {
    serverRows () {
      return [
        { type: 'database', label: this.$t('admin.apps.databaseServer'), name: this.app.database_server },
        { type: 'web', label: this.$t('admin.apps.appServer'), name: this.app.web_server },
        { type: 'sso', label: this.$t('admin.apps.ssoServer'), name: this.app.sso_server }
      ]
    },
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
      // Saving the settings never sends servers: moving those is a separate, confirmed action
      form: useForm({
        settings
      }),
      editingVolume: null,
      showVolumeModal: false,
      showClaimModal: false,
      volumeForm: useForm({ enabled: true, size: null, storage_class: null, access_mode: null }),
      claimForm: useForm({ claim: '' }),
      showRepair: false,
      confirmRepair: false,
      repairForm: useForm({
        settings,
        database_server_id: this.app.database_server_id ?? null,
        web_server_id: this.app.web_server_id ?? null,
        sso_server_id: this.app.sso_server_id ?? null
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
      showMoveSsoModal: false,
      showRemoveOldWebModal: false,
      removeOldWebServer: '',
      showRemoveOldSsoModal: false,
      removeOldSsoServer: '',
      showMaintenanceModal: false,
      maintenanceForm: useForm({
        enabled: !this.maintenance.enabled
      }),
      migrateForm: useForm({
        database_server_id: null
      }),
      moveSsoForm: useForm({
        sso_server_id: null
      }),
      removeOldWebForm: useForm({
        move_task_id: null
      }),
      removeOldSsoForm: useForm({
        move_task_id: null
      }),
      confirmForm: useForm({
        task_id: this.awaiting ? this.awaiting.task_id : null
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
    volumeUrl () {
      return '/admin/organizations/' + this.organization.id + '/apps/' + this.app.id + '/volumes/' + this.editingVolume.name
    },
    openVolume (volume) {
      this.editingVolume = volume
      this.volumeForm.defaults({
        enabled: true,
        ...(volume.follows_quota ? {} : { size: volume.own.size ?? null }),
        storage_class: volume.own.storage_class ?? null,
        access_mode: volume.own.access_mode ?? null
      }).reset()
      this.showVolumeModal = true
    },
    openClaim (volume) {
      this.editingVolume = volume
      this.claimForm.defaults({ claim: volume.effective.claim ?? '' }).reset()
      this.showClaimModal = true
    },
    askRemoveOldWeb (entry) {
      this.removeOldWebForm.move_task_id = entry.id
      this.removeOldWebServer = entry.server
      this.showRemoveOldWebModal = true
    },
    askRemoveOldSso (entry) {
      this.removeOldSsoForm.move_task_id = entry.id
      this.removeOldSsoServer = entry.server
      this.showRemoveOldSsoModal = true
    },
    toggleMaintenance (close) {
      // What the button asks for: the opposite of what the app is in now
      this.maintenanceForm.enabled = !this.maintenance.enabled
      this.maintenanceForm.put('/admin/organizations/' + this.organization.id + '/apps/' + this.app.id + '/maintenance', {
        onSuccess: () => close()
      })
    },
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
