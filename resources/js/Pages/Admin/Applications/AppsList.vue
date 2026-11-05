<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { Link, useForm, router } from '@inertiajs/vue3'

</script>
<template>
  <Head>
    <title>{{ $t('admin.apps.appsTitle') }} - Control Panel</title>
  </Head>
  <va-card class="mb-4">
    <va-card-title>{{ $t('admin.apps.appsTitle') }}</va-card-title>
    <va-card-content>
      <div class="row justify-center">
        <va-button id="createApp" class="" @click="showAddApp = !showAddApp">{{ $t('admin.apps.addApp') }}</va-button>
        <va-modal v-model="showAddApp" no-outside-dismiss no-padding size="small" class="p-0">
          <template #content="{ ok }">
            <form @submit.prevent="form.post('/admin/apps')">
              <va-card-title class="m-0">{{ $t('admin.apps.addApp') }}</va-card-title>
              <va-card-content class="m-0">
                <va-input v-model="form.name"
                  immediateValidation
                  id="name"
                  required-mark
                  :label="$t('common.name')"
                  class="mb-3"
                  :error="$page.props.errors.name"
                  :error-messages="$page.props.errors.name" />
                <va-input v-model="form.slug"
                  immediateValidation
                  id="slug"
                  required-mark
                  :label="$t('organization.slug')"
                  class="mb-3"
                  :error="$page.props.errors.slug"
                  :error-messages="$page.props.errors.slug" />
                <va-input v-model="form.category"
                  id="category"
                  required-mark
                  immediateValidation
                  :label="$t('admin.apps.category')"
                  class="mb-3"
                  :error="$page.props.errors.category"
                  :error-messages="$page.props.errors.category" />
                <va-input v-model="form.description"
                  id="description"
                  required-mark
                  immediateValidation
                  :label="$t('admin.apps.description')"
                  class="mb-3"
                  :error="$page.props.errors.description"
                  :error-messages="$page.props.errors.description" />
              </va-card-content>
              <va-card-actions align="right" class="">
                <va-button color="textInverted" :disabled="form.processing" @click="ok">{{ $t('common.cancel') }}</va-button>
                <va-button type="submit" :disabled="form.processing" id="submit" class="mr-2 mb-2">{{ $t('common.submit') }}</va-button>
              </va-card-actions>
            </form>
          </template>
        </va-modal>
      </div>

      <div class="row mt-3">
        <div class="flex flex-col md2">
          <va-input
            v-model="selected.search"
            id="filterSearch"
            :label="$t('admin.apps.search')"
            clearable
            @update:modelValue="searchChanged"
          />
        </div>
        <div class="flex flex-col md2">
          <va-select
            v-model="selected.status"
            id="filterStatus"
            :label="$t('admin.apps.status')"
            :options="statusOptions"
            value-by="value"
            @update:modelValue="applyFilters"
          />
        </div>
        <div class="flex flex-col md2">
          <va-select
            v-model="selected.source"
            id="filterSource"
            :label="$t('admin.apps.source')"
            :options="filter_options.sources"
            value-by="value"
            clearable
            @update:modelValue="applyFilters"
          />
        </div>
        <div class="flex flex-col md2">
          <va-select
            v-model="selected.capability"
            id="filterCapability"
            :label="$t('admin.apps.capability')"
            :options="filter_options.capabilities"
            value-by="value"
            clearable
            @update:modelValue="applyFilters"
          />
        </div>
        <div class="flex flex-col md3">
          <va-select
            v-model="selected.tags"
            id="filterTags"
            :label="$t('admin.apps.filterTags')"
            :options="filter_options.tags"
            value-by="value"
            multiple
            searchable
            clearable
            @update:modelValue="applyFilters"
          />
        </div>
        <div class="flex flex-col md1 justify-center">
          <va-button id="resetFilters" preset="secondary" :disabled="isDefault" @click="resetFilters">{{ $t('admin.apps.resetFilters') }}</va-button>
        </div>
      </div>

      <va-scroll-container
        color="primary"
        horizontal
      >
      <table class="va-table va-table--hoverable mt-3">
        <thead>
          <tr>
            <th>{{ $t('common.name') }}</th>
            <th>{{ $t('admin.apps.source') }}</th>
            <th>{{ $t('admin.apps.tags') }}</th>
            <th>{{ $t('admin.apps.status') }}</th>
            <th>{{ $t('admin.apps.version') }}</th>
            <th>{{ $t('admin.apps.chartVersion') }}</th>
            <th>{{ $t('admin.apps.updatesAvailable') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(app, index) in apps" :key="index">
            <td><Link :href="'/admin/apps/'+app.slug">{{ app.name }}</Link></td>
            <td>{{ app.source }}</td>
            <td>
              <va-badge v-for="tag in app.tags" :key="tag" color="secondary" class="mr-1" :text="tag" />
            </td>
            <td>{{ app.status }}</td>
            <td>
              <div v-for="version in app.versions" :key="'version-' + version.id">{{ version.version }}</div>
            </td>
            <td>
              <div v-for="version in app.versions" :key="'chart-' + version.id">{{ version.chart_version }}</div>
            </td>
            <td>
              <div v-for="version in app.versions" :key="'updates-' + version.id">
                <template v-if="version.updates">
                  <va-badge v-if="version.updates.image" color="success" class="mr-1" :text="$t('admin.versions.imageBadge', { latest: version.updates.image })" />
                  <va-badge v-if="version.updates.chart" color="success" class="mr-1" :text="$t('admin.versions.chartBadge', { latest: version.updates.chart })" />
                  <va-badge v-if="version.updates.chart_incompatible" color="warning" class="mr-1" :text="$t('admin.versions.chartBlockedBadge', { latest: version.updates.chart_incompatible.version })" />
                  <va-badge v-if="version.updates.image_error || version.updates.chart_error" color="danger" class="mr-1" :text="$t('admin.versions.checkFailedBadge')" />
                  <div v-if="!version.updates.image && !version.updates.chart && !version.updates.chart_incompatible && !version.updates.image_error && !version.updates.chart_error" class="va-text-secondary">{{ $t('admin.versions.upToDate') }}</div>
                </template>
                <span v-else class="va-text-secondary">{{ $t('admin.versions.notChecked') }}</span>
              </div>
            </td>
          </tr>
          <tr v-if="apps.length === 0">
            <td colspan="7" class="va-text-secondary">{{ $t('admin.apps.noApps') }}</td>
          </tr>
        </tbody>
      </table>
      </va-scroll-container>
      <va-pagination v-if="meta.total > pageSize" class="mt-3 mb-3 justify-center" v-model="curPageValue" :pages="pages" input @update:modelValue="changePage" />
    </va-card-content>
  </va-card>
</template>

<script lang="ts">
const STORAGE_KEY = 'admin.apps.filters'
const DEFAULT_FILTERS = { search: '', status: 'enabled', source: '', tags: [], capability: '' }

export default {
  layout: (h, page) => h(AppLayout, [page]),
  props: {
    apps: Object,
    meta: Object,
    filters: Object,
    filter_options: Object,
    errors: Object
  },
  data () {
    return {
      curPageValue: this.meta.page,
      pageSize: 20,
      showAddApp: false,
      selected: { ...this.filters, tags: [...this.filters.tags] },
      statusOptions: [
        { value: 'enabled', text: this.$t('admin.apps.filterStatusEnabled') },
        { value: 'disabled', text: this.$t('admin.apps.filterStatusDisabled') },
        { value: 'all', text: this.$t('admin.apps.filterStatusAll') }
      ],
      searchTimer: null,
      form: useForm({
        name: '',
        slug: '',
        description: '',
        category: ''
      })
    }
  },
  computed: {
    pages () {
      return this.meta.pages
    },
    isDefault () {
      return JSON.stringify(this.normalized(this.selected)) === JSON.stringify(this.normalized(DEFAULT_FILTERS))
    }
  },
  watch: {
    'meta.page' (page) {
      this.curPageValue = page
    }
  },
  mounted () {
    // Coming in with no filters in the URL restores the last ones used
    const hasUrlFilters = Object.keys(this.$page.props.ziggy?.query ?? {}).some(key => key !== 'page')
    const saved = hasUrlFilters ? null : this.savedFilters()

    if (saved && JSON.stringify(this.normalized(saved)) !== JSON.stringify(this.normalized(this.selected))) {
      this.selected = saved
      this.visit(false)
    }
  },
  beforeUnmount () {
    clearTimeout(this.searchTimer)
  },
  methods: {
    normalized (filters) {
      return {
        search: (filters.search ?? '').trim(),
        status: filters.status || DEFAULT_FILTERS.status,
        source: filters.source || '',
        capability: filters.capability || '',
        tags: [...(filters.tags ?? [])].sort()
      }
    },
    savedFilters () {
      try {
        const saved = JSON.parse(localStorage.getItem(STORAGE_KEY))
        return saved && typeof saved === 'object' ? this.normalized(saved) : null
      } catch {
        return null
      }
    },
    query (page = null) {
      const filters = this.normalized(this.selected)
      const query = {}

      if (filters.search) query.search = filters.search
      if (filters.status !== DEFAULT_FILTERS.status) query.status = filters.status
      if (filters.source) query.source = filters.source
      if (filters.capability) query.capability = filters.capability
      if (filters.tags.length) query.tags = filters.tags
      if (page && page > 1) query.page = page

      return query
    },
    visit (remember = true, page = null) {
      if (remember) {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(this.normalized(this.selected)))
      }

      router.get('/admin/apps', this.query(page), { preserveState: true, preserveScroll: true, replace: true })
    },
    applyFilters () {
      this.curPageValue = 1
      this.visit()
    },
    searchChanged () {
      clearTimeout(this.searchTimer)
      this.searchTimer = setTimeout(this.applyFilters, 300)
    },
    resetFilters () {
      this.selected = { ...DEFAULT_FILTERS, tags: [] }
      this.applyFilters()
    },
    changePage () {
      this.visit(false, this.curPageValue)
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
