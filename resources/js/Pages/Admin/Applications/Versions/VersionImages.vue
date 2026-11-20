<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import VersionLayout from './VersionLayout.vue'
import { Link, router, useForm } from '@inertiajs/vue3'

</script>
<template>
  <Head>
    <title>{{ $t('admin.versions.images') }} - Control Panel</title>
  </Head>
  <div class="row">
    <div class="flex flex-col xs12">
      <h5 class="mb-2">{{ $t('admin.versions.imagesOfVersion', { version: version.version }) }}</h5>
      <p v-if="!images.length" id="noImages" class="va-text-secondary">{{ $t('admin.versions.noImages') }}</p>
      <table v-else id="versionImages" class="va-table va-table--hoverable images-table mt-2">
        <thead>
          <tr>
            <th>{{ $t('admin.versions.imageName') }}</th>
            <th>{{ $t('admin.versions.image') }}</th>
            <th>{{ $t('admin.versions.imagePath') }}</th>
            <th>{{ $t('admin.versions.imageSource') }}</th>
            <th class="images-actions"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="image in images" :key="image.id" :id="'image-' + image.key">
            <td>
              {{ image.label }}
              <div class="va-text-secondary" style="font-size: 0.85rem">{{ image.key }}</div>
            </td>
            <td><code>{{ image.reference }}</code></td>
            <td><code v-if="image.values_path">{{ image.values_path }}</code></td>
            <td>
              <va-badge
                :color="image.source === 'override' ? 'primary' : 'secondary'"
                :text="image.source === 'override' ? $t('admin.versions.imageSourceOverride') : $t('admin.versions.imageSourceChart')"
              />
            </td>
            <td class="images-actions">
              <va-button
                :id="'editImage-' + image.key"
                size="small"
                preset="plain"
                @click="edit(image)"
              >{{ $t('common.edit') }}</va-button>
              <va-button
                v-if="!image.follows_settings"
                :id="'removeImage-' + image.key"
                size="small"
                color="danger"
                preset="plain"
                @click="remove(image)"
              >{{ $t('common.remove') }}</va-button>
            </td>
          </tr>
        </tbody>
      </table>

      <va-separator class="my-2" />

      <div>
        <Link v-if="version.chart" :href="basePath + '/images/discover'" id="discoverImages">
          <va-button class="mb-2">{{ $t('admin.versions.discoverImages', { chart: version.chart }) }}</va-button>
        </Link>
        <p v-else class="va-text-secondary">{{ $t('admin.versions.discoverNeedsChart') }}</p>
      </div>

      <va-alert v-if="discover_error" id="discoverError" color="danger" border="top" border-color="danger" class="mt-3">
        {{ $t('admin.versions.discoverFailed', { error: discover_error }) }}
      </va-alert>

      <div v-if="discovered" id="discoveredImages" class="mt-6">
        <h5 class="mb-1">{{ $t('admin.versions.discoveredImages', { chart: version.chart }) }}</h5>
        <p class="va-text-secondary mb-2">{{ $t('admin.versions.discoveredHelp') }}</p>
        <p v-if="!discovered.length" id="noDiscoveredImages">{{ $t('admin.versions.noDiscoveredImages') }}</p>
        <form v-else @submit.prevent="add">
          <table class="va-table va-table--hoverable images-table">
            <thead>
              <tr>
                <th class="images-select"></th>
                <th>{{ $t('admin.versions.imageName') }}</th>
                <th>{{ $t('admin.versions.image') }}</th>
                <th>{{ $t('admin.versions.imagePath') }}</th>
                <th>{{ $t('admin.versions.imageSource') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(row, index) in rows" :key="row.key + index" :id="'discovered-' + row.key">
                <td class="images-select">
                  <va-checkbox v-model="row.selected" :disabled="row.exists" :id="'select-' + row.key" />
                </td>
                <td>
                  {{ row.label }}
                  <div class="va-text-secondary" style="font-size: 0.85rem">{{ row.key }}</div>
                </td>
                <td><code>{{ reference(row) }}</code></td>
                <td>
                  <code v-if="row.values_path">{{ row.values_path }}</code>
                </td>
                <td>
                  <va-badge v-if="row.exists" color="success" :text="$t('admin.versions.imageAlreadyAdded')" />
                  <va-select
                    v-else
                    v-model="row.source"
                    :id="'source-' + row.key"
                    :options="sourceOptions(row)"
                    text-by="text"
                    value-by="value"
                    :disabled="!row.selected"
                  />
                </td>
              </tr>
            </tbody>
          </table>
          <div v-if="errors && Object.keys(errors).length" class="mt-2" style="color: var(--va-danger)">
            <div v-for="(message, field) in errors" :key="field">{{ message }}</div>
          </div>
          <va-button type="submit" id="addImages" class="mt-3" :disabled="form.processing || !selectedRows.length">
            {{ $t('admin.versions.addSelectedImages') }}
          </va-button>
        </form>
      </div>

      <va-modal v-model="showEdit" no-outside-dismiss no-padding size="small" class="p-0">
        <template #content="{ ok }">
          <form v-if="editing" id="editImageForm" @submit.prevent="update">
            <va-card-title class="m-0">{{ $t('admin.versions.editImage', { image: editing.label }) }}</va-card-title>
            <va-card-content class="m-0">
              <va-input v-model="editForm.label" id="editLabel" :label="$t('admin.versions.imageName')" class="mb-3" :error="!!editForm.errors.label" :error-messages="editForm.errors.label" />
              <template v-if="!editing.follows_settings">
                <va-input v-model="editForm.registry" id="editRegistry" :label="$t('admin.versions.imageRegistry')" class="mb-3" :error="!!editForm.errors.registry" :error-messages="editForm.errors.registry" />
                <va-input v-model="editForm.repository" id="editRepository" :label="$t('admin.versions.imageRepository')" class="mb-3" required-mark :error="!!editForm.errors.repository" :error-messages="editForm.errors.repository" />
                <va-input v-model="editForm.tag" id="editTag" :label="$t('admin.versions.imageTag')" class="mb-3" :error="!!editForm.errors.tag" :error-messages="editForm.errors.tag" />
                <va-input v-model="editForm.digest" id="editDigest" :label="$t('admin.versions.imageDigest')" class="mb-3" :error="!!editForm.errors.digest" :error-messages="editForm.errors.digest" />
                <va-select
                  v-model="editForm.registry_secret_id"
                  id="editRegistrySecret"
                  :label="$t('admin.versions.containerImageRegistry')"
                  :options="image_registries"
                  text-by="name"
                  value-by="id"
                  clearable
                  class="mb-3"
                  :error="!!editForm.errors.registry_secret_id"
                  :error-messages="editForm.errors.registry_secret_id"
                />
                <va-input v-model="editForm.values_path" id="editPath" :label="$t('admin.versions.imagePath')" class="mb-3" :error="!!editForm.errors.values_path" :error-messages="editForm.errors.values_path" />
                <va-select
                  v-model="editForm.source"
                  id="editSource"
                  :label="$t('admin.versions.imageSource')"
                  :options="sourceChoices"
                  text-by="text"
                  value-by="value"
                  class="mb-3"
                  :error="!!editForm.errors.source"
                  :error-messages="editForm.errors.source"
                />
              </template>
              <va-select
                v-model="editForm.update_policy"
                id="editPolicy"
                :label="$t('admin.versions.imageUpdatePolicy')"
                :options="policyChoices"
                text-by="text"
                value-by="value"
                class="mb-3"
                :error="!!editForm.errors.update_policy"
                :error-messages="editForm.errors.update_policy"
              />
              <va-input v-model="editForm.tag_pattern" id="editTagPattern" :label="$t('admin.versions.imageTagPattern')" class="mb-3" :error="!!editForm.errors.tag_pattern" :error-messages="editForm.errors.tag_pattern" />
              <va-input v-model="editForm.group" id="editGroup" :label="$t('admin.versions.imageGroup')" :messages="[$t('admin.versions.imageGroupHelp')]" class="mb-3" :error="!!editForm.errors.group" :error-messages="editForm.errors.group" />
            </va-card-content>
            <va-card-actions align="right">
              <va-button color="textInverted" :disabled="editForm.processing" @click="ok">{{ $t('common.cancel') }}</va-button>
              <va-button type="submit" id="saveImage" :disabled="editForm.processing" class="mr-2 mb-2">{{ $t('common.update') }}</va-button>
            </va-card-actions>
          </form>
        </template>
      </va-modal>
    </div>
  </div>
</template>

<script lang="ts">
export default {
  layout: (h, page) => {
    return h(AppLayout, () => h(VersionLayout, () => page))
  },
  props: {
    app: Object,
    version: Object,
    images: Array,
    discovered: Array,
    discover_error: String,
    image_registries: {
      type: Array,
      default: () => []
    },
    update_policies: {
      type: Array,
      default: () => []
    },
    errors: Object
  },
  data () {
    return {
      form: useForm({
        images: []
      }),
      // What the chart runs, to pick from. Left alone, an image is only recorded as the chart's own
      rows: this.rowsFor(this.discovered),
      showEdit: false,
      editing: null,
      editForm: useForm({
        label: '',
        registry: '',
        repository: '',
        tag: '',
        digest: '',
        registry_secret_id: null,
        values_path: '',
        source: 'chart',
        update_policy: 'track',
        tag_pattern: '',
        group: ''
      })
    }
  },
  watch: {
    discovered (discovered) {
      this.rows = this.rowsFor(discovered)
    }
  },
  computed: {
    basePath () {
      return '/admin/apps/' + this.app.slug + '/versions/' + this.version.version
    },
    selectedRows () {
      return this.rows.filter((row) => row.selected && !row.exists)
    },
    sourceChoices () {
      return [
        { text: this.$t('admin.versions.imageSourceChartHelp'), value: 'chart' },
        { text: this.$t('admin.versions.imageSourceOverrideHelp'), value: 'override' }
      ]
    },
    policyChoices () {
      return this.update_policies.map((policy) => ({ text: this.$t('admin.versions.imagePolicy_' + policy), value: policy }))
    }
  },
  methods: {
    rowsFor (discovered) {
      return (discovered || []).map((image) => ({ ...image, selected: false, source: 'chart' }))
    },
    reference (row) {
      let reference = (row.registry ? row.registry + '/' : '') + row.repository
      if (row.tag) {
        reference += ':' + row.tag
      }
      if (row.digest) {
        reference += '@' + row.digest
      }
      return reference
    },
    // Only an image the chart takes from a registry/repository/tag block can be set by Kumulicp
    sourceOptions (row) {
      const options = [{ text: this.$t('admin.versions.imageSourceChartHelp'), value: 'chart' }]
      if (row.overridable) {
        options.push({ text: this.$t('admin.versions.imageSourceOverrideHelp'), value: 'override' })
      }
      return options
    },
    add () {
      this.form.images = this.selectedRows.map((row) => ({
        key: row.key,
        label: row.label,
        values_path: row.values_path,
        registry: row.registry,
        repository: row.repository,
        tag: row.tag,
        digest: row.digest,
        source: row.source
      }))
      this.form.post(this.basePath + '/images')
    },
    edit (image) {
      this.editing = image
      this.editForm.defaults({
        label: image.label === image.key ? '' : image.label,
        registry: image.registry || '',
        repository: image.repository || '',
        tag: image.tag || '',
        digest: image.digest || '',
        registry_secret_id: image.registry_secret_id,
        values_path: image.values_path || '',
        source: image.source,
        update_policy: image.update_policy,
        tag_pattern: image.tag_pattern || '',
        group: image.group || ''
      }).reset()
      this.editForm.clearErrors()
      this.showEdit = true
    },
    update () {
      // What belongs to the version's settings isn't sent for the image that follows them
      this.editForm.transform((data) => this.editing.follows_settings
        ? { label: data.label, update_policy: data.update_policy, tag_pattern: data.tag_pattern, group: data.group }
        : data
      ).put(this.basePath + '/images/' + this.editing.id, {
        onSuccess: () => { this.showEdit = false }
      })
    },
    remove (image) {
      router.delete(this.basePath + '/images/' + image.id)
    }
  }
}
</script>

<style lang="scss" scoped>
.images-table {
  width: 100%;

  code {
    overflow-wrap: anywhere;
    font-size: 0.85rem;
  }

  .images-actions {
    text-align: right;
    white-space: nowrap;
  }

  .images-select {
    width: 1%;
  }
}
</style>
