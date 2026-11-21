<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import AppsLayout from '../AppsLayout.vue'
import { Link, router, useForm } from '@inertiajs/vue3'

</script>
<template>
  <Head>
    <title>{{ app.name }} - {{ $t('admin.versions.versions') }} - Control Panel</title>
  </Head>
  <div v-for="version in versionsWithUpdates" :key="'updates-' + version.id" class="mb-3">
    <va-alert
      v-if="version.updates.image || version.updates.images.length || version.updates.chart"
      color="success"
      border="top"
      border-color="success"
      class="mb-2"
      :id="'updatesAvailable' + version.id"
    >
      <div v-if="version.updates.image">{{ $t('admin.versions.newerImage', { version: version.version, latest: version.updates.image }) }}</div>
      <div v-for="image in version.updates.images" :key="image.key">{{ $t('admin.versions.newerOtherImage', { image: image.label, current: image.current, latest: image.latest }) }}</div>
      <div v-if="version.updates.chart">{{ $t('admin.versions.newerChart', { current: version.updates.chart_current, latest: version.updates.chart }) }}</div>
      <div v-for="(warning, index) in (version.updates.chart ? version.updates.chart_warnings : [])" :key="index" class="va-text-secondary" style="font-size: 0.85rem">{{ warning }}</div>
      <template v-if="version.updates.chart && version.updates.chart_image_changes.length">
        <div class="mt-1">{{ $t('admin.versions.chartImageChanges', { latest: version.updates.chart }) }}</div>
        <ul class="ml-4" style="list-style: disc">
          <li v-for="(change, index) in version.updates.chart_image_changes" :key="index">{{ change }}</li>
        </ul>
      </template>
      <div class="mt-1">{{ $t('admin.versions.adoptUpdate') }}</div>
    </va-alert>
    <va-alert
      v-if="version.updates.chart_incompatible"
      color="warning"
      border="top"
      border-color="warning"
      class="mb-2"
      :id="'chartIncompatible' + version.id"
    >
      <div>{{ version.updates.chart_incompatible.status === 'incompatible'
        ? $t('admin.versions.chartIncompatible', { latest: version.updates.chart_incompatible.version })
        : $t('admin.versions.chartUnverified', { latest: version.updates.chart_incompatible.version }) }}</div>
      <ul class="ml-4" style="list-style: disc">
        <li v-for="(issue, index) in version.updates.chart_incompatible.issues" :key="index">{{ issue }}</li>
      </ul>
    </va-alert>
    <va-alert
      v-if="version.updates.image_error || version.updates.images_errors.length || version.updates.chart_error"
      color="danger"
      border="top"
      border-color="danger"
      class="mb-2"
    >
      <div v-if="version.updates.image_error">{{ $t('admin.versions.imageCheckFailed', { error: version.updates.image_error }) }}</div>
      <div v-for="image in version.updates.images_errors" :key="image.key">{{ $t('admin.versions.otherImageCheckFailed', { image: image.label, error: image.error }) }}</div>
      <div v-if="version.updates.chart_error">{{ $t('admin.versions.chartCheckFailed', { error: version.updates.chart_error }) }}</div>
    </va-alert>
  </div>
  <div v-if="groups.length" id="versionGroups" class="row align-center justify-center mb-3">
    <va-select
      :model-value="group_id"
      id="versionGroup"
      class="mr-2"
      style="min-width: 16rem"
      :label="$t('admin.versions.group')"
      :options="groupOptions"
      value-by="id"
      text-by="text"
      @update:modelValue="changeGroup"
    />
    <span v-if="currentGroup" class="va-text-secondary mr-3">{{ $t('admin.versions.groupSummary', { versions: currentGroup.versions, plans: currentGroup.plans }) }}</span>
    <va-button id="newGroup" preset="secondary" size="small" class="mr-1" @click="openGroupModal('add')">{{ $t('admin.versions.newGroup') }}</va-button>
    <va-button v-if="currentGroup" id="renameGroup" preset="secondary" size="small" class="mr-1" @click="openGroupModal('rename')">{{ $t('admin.versions.renameGroup') }}</va-button>
    <va-button v-if="currentGroup && !currentGroup.is_default" id="deleteGroup" preset="secondary" color="danger" size="small" @click="openGroupModal('delete')">{{ $t('admin.versions.deleteGroup') }}</va-button>
    <va-modal v-model="showGroupModal" no-outside-dismiss no-padding size="small" class="p-0">
      <template #content="{ ok }">
        <form @submit.prevent="submitGroup">
          <va-card-title class="m-0">{{ groupMode === 'add' ? $t('admin.versions.newGroup') : (groupMode === 'rename' ? $t('admin.versions.renameGroup') : $t('admin.versions.deleteGroup')) }}</va-card-title>
          <va-card-content class="m-0">
            <va-input
              v-if="groupMode !== 'delete'"
              v-model="groupForm.name"
              id="groupName"
              :label="$t('admin.versions.groupName')"
              required-mark
              immediateValidation
              :error="$page.props.errors.name"
              :error-messages="$page.props.errors.name"
            />
            <span v-else>{{ currentGroup && currentGroup.name }}</span>
          </va-card-content>
          <va-card-actions align="right" class="">
            <va-button color="textInverted" :disabled="groupForm.processing" @click="ok">{{ $t('common.cancel') }}</va-button>
            <va-button type="submit" id="submitGroup" :disabled="groupForm.processing" class="mr-2 mb-2">{{ $t('common.submit') }}</va-button>
          </va-card-actions>
        </form>
      </template>
    </va-modal>
  </div>
  <div class="row justify-center">
    <va-button id="addVersion" @click="showAddVersion = !showAddVersion">{{ $t('admin.versions.addVersion') }}</va-button>
    <va-button v-if="mass_upgrade.count > 0" id="massUpgrade" class="ml-2" color="success" @click="showMassUpgrade = true">{{ $t('admin.versions.massUpgradeButton', { count: mass_upgrade.count, version: mass_upgrade.version }) }}</va-button>
    <va-modal v-model="showMassUpgrade" no-outside-dismiss no-padding size="small" class="p-0">
      <template #content="{ ok }">
        <form @submit.prevent="massUpgradeForm.post('/admin/apps/' + app.slug + '/versions/' +mass_upgrade.id + '/mass-upgrade', { onSuccess: () => showMassUpgrade = false })">
          <va-card-title class="m-0">{{ $t('admin.versions.massUpgradeTitle') }}</va-card-title>
          <va-card-content class="m-0">
            {{ $t('admin.versions.massUpgradeWarning', { count: mass_upgrade.count, version: mass_upgrade.version }) }}
          </va-card-content>
          <va-card-actions align="right" class="">
            <va-button color="textInverted" :disabled="massUpgradeForm.processing" @click="ok">{{ $t('common.cancel') }}</va-button>
            <va-button type="submit" id="confirmMassUpgrade" :disabled="massUpgradeForm.processing" class="mr-2 mb-2">{{ $t('admin.versions.massUpgradeConfirm') }}</va-button>
          </va-card-actions>
        </form>
      </template>
    </va-modal>
    <va-button id="checkUpdates" class="ml-2" preset="secondary" :loading="checkForm.processing" :disabled="checkForm.processing" @click="checkForm.post('/admin/apps/' + app.slug + '/versions/check-updates')">{{ $t('admin.versions.checkNow') }}</va-button>
    <va-modal v-model="showAddVersion" no-outside-dismiss no-padding size="small" class="p-0">
      <template #content="{ ok }">
        <form @submit.prevent="submit">
          <va-card-title class="m-0">{{ $t('admin.versions.addVersion') }}</va-card-title>
          <va-card-content class="m-0">
            <va-select
              v-if="groups.length > 1"
              v-model="form.version_group_id"
              id="addVersionGroup"
              :label="$t('admin.versions.group')"
              class="mb-3"
              :options="groupOptions"
              value-by="id"
              text-by="text"
              :error="$page.props.errors.version_group_id"
              :error-messages="$page.props.errors.version_group_id"
            />
            <va-input v-model="form.version"
              id="name"
              :label="$t('admin.versions.name')"
              class="mb-3"
              required-mark
              immediateValidation
              :error="$page.props.errors.version"
              :error-messages="$page.props.errors.version"
              />

            <div class="mt-2 va-input-label va-input-wrapper__label va-input-wrapper__label--outer" style="color: var(--va-primary);">
              {{ $t('admin.versions.copyValuesFrom') }}
            </div>
            <va-radio v-model="form.copy_from"
              :options="copyOptions"
              value-by="value"
              @update:modelValue="copyChanged"
              />
            <div v-if="selectedUpdate" id="updateDetails" class="va-text-secondary mt-2" style="font-size: 0.85rem">{{ selectedUpdate.details }}</div>
            <div v-if="selectedUpdate && selectedUpdate.images.length" id="updateImages" class="mt-2">
              <div class="va-input-label">{{ $t('admin.versions.updateImagesPick') }}</div>
              <div v-for="image in selectedUpdate.images" :key="image.key">
                <va-checkbox
                  :id="'updateImage-' + image.key"
                  :model-value="form.images.includes(image.key)"
                  :label="$t('admin.versions.updateImageLine', { image: image.label, current: image.current, latest: image.latest })"
                  @update:modelValue="toggleImage(image, $event)"
                />
                <span v-if="image.group" class="va-text-secondary" style="font-size: 0.85rem">{{ $t('admin.versions.updateImageGroupNote') }}</span>
              </div>
            </div>
            <va-select v-model="form.copy_version"
              v-if="form.copy_from === 'previous_version'"
              id="copyVersion"
              :options="versions"
              :label="$t('admin.versions.copyVersion')"
              class="my-2"
              value-by="id"
              text-by="version"
              required-mark
              immediateValidation
              :error="$page.props.errors.copy_version"
              :error-messages="$page.props.errors.copy_version"
              />
          </va-card-content>
          <va-card-actions align="right" class="">
            <va-button color="textInverted" :disabled="form.processing" @click="ok">{{ $t('common.cancel') }}</va-button>
            <va-button type="submit" id="submit" :disabled="form.processing" class="mr-2 mb-2">{{ $t('common.submit') }}</va-button>
          </va-card-actions>
        </form>
      </template>
    </va-modal>
  </div>
  <div class="apps-list">
    <div class="row">
      <div class="flex flex-col xs12 lg12">
        <VaScrollContainer
          color="primary"
          horizontal
        >
          <table class="va-table va-table--hoverable mt-3">
            <thead>
              <tr>
                <th style="width: 50px">{{ $t('status.enabled') }}</th>
                <th width="55%">{{ $t('admin.versions.name') }}</th>
                <th>{{ $t('admin.versions.testsColumn') }}</th>
                <th>{{ $t('status.updatedAt') }}</th>
                <th>{{ $t('status.createdAt') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="version in versions.slice((curPageValue - 1), (curPageValue - 1 + pageSize))" :key="version.name">
                <td style="text-align: center"><va-icon name="fa-circle" :color="version.status == 'active' ? 'success' : 'backgroundElement'" /></td>
                <td><Link :href="'/admin/apps/'+app.slug+'/versions/'+version.id">{{ version.version }}</Link></td>
                <td>
                  <Link :href="'/admin/apps/'+app.slug+'/versions/'+version.id+'/tests' + (version.test.run_id ? '/' + version.test.run_id : '')">
                    <va-badge :color="gateColor(version.test.gate)" :text="$t('admin.versions.testGate.' + version.test.gate)" />
                  </Link>
                </td>
                <td>{{ version.updated_at }}</td>
                <td>{{ version.created_at }}</td>
              </tr>
            </tbody>
          </table>
        </VaScrollContainer>

        <va-pagination v-if="versions.length > pageSize" class="mt-3 mb-3 justify-center" v-model="curPageValue" :total="versions.length" :direction-links="false" :page-size="pageSize" />
      </div>
    </div>
  </div>
</template>

<script lang="ts">
export default {
  layout: (h, page) => {
    return h(AppLayout, () => h(AppsLayout, () => page))
  },
  props: {
    app: Object,
    group_id: Number,
    groups: {
      type: Array,
      default: () => []
    },
    versions: Object,
    mass_upgrade: Object,
    errors: Object
  },
  computed: {
    groupOptions () {
      return this.groups.map((group) => ({ id: group.id, text: group.name + (group.is_default ? ' (' + this.$t('admin.versions.groupDefault') + ')' : '') }))
    },
    currentGroup () {
      return this.groups.find((group) => group.id === this.group_id)
    },
    versionsWithUpdates () {
      return this.versions.filter((version) => version.updates)
    },
    // A new version can be added for each enabled version with a newer tag for its image that isn't already a
    // version, or for one of its other images (which has no name of its own to offer)
    updateOptions () {
      return this.versions
        .filter((version) => version.updates && (this.offeredName(version) || version.updates.images.length))
        .map((version) => ({
          text: this.offeredName(version)
            ? this.$t('admin.versions.updateOption', { latest: version.updates.image })
            : this.$t('admin.versions.updateOptionImages', { version: version.version }),
          details: version.updates.chart
            ? this.$t('admin.versions.updateDetailsChart', { current: version.version, chart: version.updates.chart })
            : (this.offeredName(version)
                ? this.$t('admin.versions.updateDetails', { current: version.version })
                : this.$t('admin.versions.updateDetailsImages', { version: version.version })),
          value: 'update:' + version.id,
          latest: this.offeredName(version),
          images: version.updates.images
        }))
    },
    selectedUpdate () {
      return this.updateOptions.find((option) => option.value === this.form.copy_from)
    },
    copyOptions () {
      return [...this.updateOptions, ...this.copyFrom]
    }
  },
  methods: {
    changeGroup (id) {
      router.get('/admin/apps/' + this.app.slug + '/versions', { group: id })
    },
    openGroupModal (mode) {
      this.groupMode = mode
      this.groupForm.name = mode === 'rename' && this.currentGroup ? this.currentGroup.name : ''
      this.showGroupModal = true
    },
    submitGroup () {
      const base = '/admin/apps/' + this.app.slug + '/version-groups'
      const done = { onSuccess: () => { this.showGroupModal = false } }

      if (this.groupMode === 'add') {
        this.groupForm.post(base, done)
      } else if (this.groupMode === 'rename') {
        this.groupForm.put(base + '/' + this.group_id, done)
      } else {
        this.groupForm.delete(base + '/' + this.group_id, done)
      }
    },
    gateColor (gate) {
      return { passed: 'success', bypassed: 'warning', running: 'info', failed: 'danger', stale: 'warning', untested: 'secondary' }[gate] || 'secondary'
    },
    // The newer tag of the version's image, as the name of the version that adopts it, unless that already is one
    offeredName (version) {
      const latest = version.updates && version.updates.image
      return latest && !this.versions.some((other) => other.version === latest) ? latest : null
    },
    // Choosing an update fills in the new version's name, and has all its newer images picked
    copyChanged (value) {
      const update = this.updateOptions.find((option) => option.value === value)
      if (update) {
        if (update.latest) {
          this.form.version = update.latest
        }
        this.form.images = update.images.map((image) => image.key)
      } else {
        this.form.images = []
      }
    },
    // Images in the same group are updated together
    toggleImage (image, checked) {
      const keys = this.selectedUpdate.images
        .filter((other) => other.key === image.key || (image.group && other.group === image.group))
        .map((other) => other.key)
      const rest = this.form.images.filter((key) => !keys.includes(key))
      this.form.images = checked ? [...rest, ...keys] : rest
    },
    submit () {
      this.form.transform((data) => {
        if (!data.copy_from.startsWith('update:')) {
          return data
        }
        return { ...data, copy_from: 'update', copy_version: Number(data.copy_from.split(':')[1]) }
      }).post('/admin/apps/' + this.app.slug + '/versions')
    }
  },
  data () {
    return {
      copyFrom: [
        {
          text: this.$t('admin.versions.recommendations'),
          value: 'recommendations'
        },
        {
          text: this.$t('admin.versions.previousVersion'),
          value: 'previous_version'
        },
        {
          text: this.$t('admin.versions.none'),
          value: 'none'
        }
      ],
      showAddVersion: false,
      showGroupModal: false,
      groupMode: 'add',
      groupForm: useForm({ name: '' }),
      showMassUpgrade: false,
      massUpgradeForm: useForm({}),
      checkForm: useForm({}),
      curPageValue: 1,
      pageSize: 10,
      form: useForm({
        version: '',
        version_group_id: this.group_id,
        copy_from: 'previous_version',
        copy_version: '',
        images: []
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
