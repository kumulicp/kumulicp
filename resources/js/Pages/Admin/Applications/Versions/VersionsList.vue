<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import AppsLayout from '../AppsLayout.vue'
import { Link, useForm } from '@inertiajs/vue3'

</script>
<template>
  <Head>
    <title>{{ app.name }} - {{ $t('admin.versions.versions') }} - Control Panel</title>
  </Head>
  <div v-for="version in versionsWithUpdates" :key="'updates-' + version.id" class="mb-3">
    <va-alert
      v-if="version.updates.image || version.updates.chart"
      color="success"
      border="top"
      border-color="success"
      class="mb-2"
      :id="'updatesAvailable' + version.id"
    >
      <div v-if="version.updates.image">{{ $t('admin.versions.newerImage', { version: version.version, latest: version.updates.image }) }}</div>
      <div v-if="version.updates.chart">{{ $t('admin.versions.newerChart', { current: version.updates.chart_current, latest: version.updates.chart }) }}</div>
      <div v-for="(warning, index) in (version.updates.chart ? version.updates.chart_warnings : [])" :key="index" class="va-text-secondary" style="font-size: 0.85rem">{{ warning }}</div>
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
      v-if="version.updates.image_error || version.updates.chart_error"
      color="danger"
      border="top"
      border-color="danger"
      class="mb-2"
    >
      <div v-if="version.updates.image_error">{{ $t('admin.versions.imageCheckFailed', { error: version.updates.image_error }) }}</div>
      <div v-if="version.updates.chart_error">{{ $t('admin.versions.chartCheckFailed', { error: version.updates.chart_error }) }}</div>
    </va-alert>
  </div>
  <div class="row justify-center">
    <va-button id="addVersion" @click="showAddVersion = !showAddVersion">{{ $t('admin.versions.addVersion') }}</va-button>
    <va-button id="checkUpdates" class="ml-2" preset="secondary" :loading="checkForm.processing" :disabled="checkForm.processing" @click="checkForm.post('/admin/apps/' + app.slug + '/versions/check-updates')">{{ $t('admin.versions.checkNow') }}</va-button>
    <va-modal v-model="showAddVersion" no-outside-dismiss no-padding size="small" class="p-0">
      <template #content="{ ok }">
        <form @submit.prevent="submit">
          <va-card-title class="m-0">{{ $t('admin.versions.addVersion') }}</va-card-title>
          <va-card-content class="m-0">
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
                <th width="70%">{{ $t('admin.versions.name') }}</th>
                <th>{{ $t('status.updatedAt') }}</th>
                <th>{{ $t('status.createdAt') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="version in versions.slice((curPageValue - 1), (curPageValue - 1 + pageSize))" :key="version.name">
                <td style="text-align: center"><va-icon name="fa-circle" :color="version.status == 'active' ? 'success' : 'backgroundElement'" /></td>
                <td><Link :href="'/admin/apps/'+app.slug+'/versions/'+version.version">{{ version.version }}</Link></td>
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
    versions: Object,
    errors: Object
  },
  computed: {
    versionsWithUpdates () {
      return this.versions.filter((version) => version.updates)
    },
    // A new version can be added for each enabled version with a newer image tag that isn't already a version
    updateOptions () {
      return this.versions
        .filter((version) => version.updates && version.updates.image && !this.versions.some((other) => other.version === version.updates.image))
        .map((version) => ({
          text: this.$t('admin.versions.updateOption', { latest: version.updates.image }),
          details: version.updates.chart
            ? this.$t('admin.versions.updateDetailsChart', { current: version.version, chart: version.updates.chart })
            : this.$t('admin.versions.updateDetails', { current: version.version }),
          value: 'update:' + version.id,
          latest: version.updates.image
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
    // Choosing an update fills in the new version's name
    copyChanged (value) {
      const update = this.updateOptions.find((option) => option.value === value)
      if (update) {
        this.form.version = update.latest
      }
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
      checkForm: useForm({}),
      curPageValue: 1,
      pageSize: 10,
      form: useForm({
        version: '',
        copy_from: 'previous_version',
        copy_version: ''
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
