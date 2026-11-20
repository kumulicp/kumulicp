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
    <div class="flex xs12">
      <h5 class="mb-2">{{ $t('admin.versions.imagesOfVersion', { version: version.version }) }}</h5>
      <p v-if="!images.length" id="noImages" class="va-text-secondary">{{ $t('admin.versions.noImages') }}</p>
      <table v-else id="versionImages" class="va-table va-table--hoverable mt-2">
        <thead>
          <tr>
            <th>{{ $t('admin.versions.imageName') }}</th>
            <th>{{ $t('admin.versions.image') }}</th>
            <th>{{ $t('admin.versions.imagePath') }}</th>
            <th>{{ $t('admin.versions.imageSource') }}</th>
            <th></th>
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
            <td>
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

      <div class="mt-4">
        <Link v-if="version.chart" :href="basePath + '/images/discover'" id="discoverImages">
          <va-button>{{ $t('admin.versions.discoverImages', { chart: version.chart }) }}</va-button>
        </Link>
        <p v-else class="va-text-secondary">{{ $t('admin.versions.discoverNeedsChart') }}</p>
      </div>

      <va-alert v-if="discover_error" id="discoverError" color="danger" border="top" border-color="danger" class="mt-3">
        {{ $t('admin.versions.discoverFailed', { error: discover_error }) }}
      </va-alert>

      <div v-if="discovered" id="discoveredImages" class="mt-4">
        <h5 class="mb-1">{{ $t('admin.versions.discoveredImages', { chart: version.chart }) }}</h5>
        <p class="va-text-secondary mb-2">{{ $t('admin.versions.discoveredHelp') }}</p>
        <p v-if="!discovered.length" id="noDiscoveredImages">{{ $t('admin.versions.noDiscoveredImages') }}</p>
        <form v-else @submit.prevent="add">
          <table class="va-table va-table--hoverable">
            <thead>
              <tr>
                <th></th>
                <th>{{ $t('admin.versions.imageName') }}</th>
                <th>{{ $t('admin.versions.image') }}</th>
                <th>{{ $t('admin.versions.imagePath') }}</th>
                <th>{{ $t('admin.versions.imageSource') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(row, index) in rows" :key="row.key + index" :id="'discovered-' + row.key">
                <td>
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
    errors: Object
  },
  data () {
    return {
      form: useForm({
        images: []
      }),
      // What the chart runs, to pick from. Left alone, an image is only recorded as the chart's own
      rows: this.rowsFor(this.discovered)
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
    remove (image) {
      router.delete(this.basePath + '/images/' + image.id)
    }
  }
}
</script>

<style>
</style>
