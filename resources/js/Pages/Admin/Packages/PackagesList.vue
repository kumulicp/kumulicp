<script lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { router, useForm } from '@inertiajs/vue3'
import { defineComponent } from 'vue'

interface Package {
  name: string
  label: string
  description: string
  latest: string | null
  type: string
  installed: boolean
  enabled: boolean
  version: string | null
  updateAvailable: boolean
  isUnstable: boolean
  source: string
}

export default defineComponent({
  layout: (h: any, page: any) => h(AppLayout, [page]),

  props: {
    packages: {
      type: Array as () => Package[],
      required: true,
    },
    allowUnstable: {
      type: Boolean,
      default: false,
    },
  },

  setup() {
    const uploadForm = useForm({ module: null as File | null })
    return { uploadForm }
  },

  data() {
    return {
      search: '',
      filterState: 'all',
      showUpload: false,
      showInstallConfirm: false,
      pendingInstallPkg: null as Package | null,
      showDeleteConfirm: false,
      pendingDeletePkg: null as Package | null,
    }
  },

  computed: {
    filtered(): Package[] {
      return this.packages.filter((pkg) => {
        const matchSearch =
          !this.search ||
          pkg.name.toLowerCase().includes(this.search.toLowerCase()) ||
          (pkg.description || '').toLowerCase().includes(this.search.toLowerCase())

        const matchState =
          this.filterState === 'all' ||
          (this.filterState === 'installed' && pkg.installed) ||
          (this.filterState === 'available' && !pkg.installed) ||
          (this.filterState === 'enabled' && pkg.enabled) ||
          (this.filterState === 'disabled' && pkg.installed && !pkg.enabled)

        return matchSearch && matchState
      })
    },
  },

  methods: {
    openUpload() {
      this.uploadForm.reset()
      this.showUpload = true
    },

    submitUpload() {
      this.uploadForm.post('/admin/packages/upload', {
        onSuccess: () => {
          this.showUpload = false
          this.uploadForm.reset()
        },
      })
    },

    confirmInstall(pkg: Package) {
      this.pendingInstallPkg = pkg
      this.showInstallConfirm = true
    },

    executeInstall() {
      if (!this.pendingInstallPkg) return
      const [vendor, name] = this.pendingInstallPkg.name.split('/')
      router.post(`/admin/packages/${vendor}/${name}/install`)
      this.showInstallConfirm = false
      this.pendingInstallPkg = null
    },

    confirmDelete(pkg: Package) {
      this.pendingDeletePkg = pkg
      this.showDeleteConfirm = true
    },

    executeDelete() {
      if (!this.pendingDeletePkg) return
      const [vendor, name] = this.pendingDeletePkg.name.split('/')
      router.delete(`/admin/packages/${vendor}/${name}`)
      this.showDeleteConfirm = false
      this.pendingDeletePkg = null
    },

    toggleModule(pkg: Package) {
      const [vendor, name] = pkg.name.split('/')
      const action = pkg.enabled ? 'disable' : 'enable'
      router.post(`/admin/packages/${vendor}/${name}/${action}`)
    },

    upgradePackage(pkg: Package) {
      const [vendor, name] = pkg.name.split('/')
      router.post(`/admin/packages/${vendor}/${name}/upgrade`)
    },

    toggleAllowUnstable(value: boolean) {
      router.post('/admin/packages/settings', { allow_unstable: value })
    },

    statusColor(pkg: Package): string {
      if (!pkg.installed) return 'secondary'
      return pkg.enabled ? 'success' : 'warning'
    },

    statusLabel(pkg: Package): string {
      if (!pkg.installed) return this.$t('admin.packages.filterAvailable')
      return pkg.enabled ? this.$t('admin.packages.filterEnabled') : this.$t('admin.packages.filterDisabled')
    },
  },
})
</script>

<template>
  <Head>
    <title>{{ $t('admin.packages.packageManager') }} - Control Panel</title>
  </Head>

  <va-card class="mb-4">
    <va-card-title>
      <span>{{ $t('admin.packages.packageManager') }}</span>
    </va-card-title>

    <va-card-content>
      <!-- Toolbar -->
      <div class="row mb-4 gap-3 items-end">
        <div class="flex flex-col md6">
          <va-input
            v-model="search"
            :placeholder="$t('admin.packages.searchPlaceholder')"
            clearable
          >
            <template #prepend>
              <va-icon name="search" />
            </template>
          </va-input>
        </div>

        <div class="flex flex-col md3">
          <va-select
            v-model="filterState"
            :label="$t('admin.packages.filter')"
            :options="[
              { text: $t('admin.packages.filterAll'), value: 'all' },
              { text: $t('admin.packages.filterInstalled'), value: 'installed' },
              { text: $t('admin.packages.filterAvailable'), value: 'available' },
              { text: $t('admin.packages.filterEnabled'), value: 'enabled' },
              { text: $t('admin.packages.filterDisabled'), value: 'disabled' },
            ]"
            text-by="text"
            value-by="value"
          />
        </div>

        <div class="flex flex-col items-end">
          <va-switch
            :model-value="allowUnstable"
            :label="$t('admin.packages.allowUnstable')"
            @update:model-value="toggleAllowUnstable"
          />
        </div>

        <div class="flex flex-col md3 items-end">
          <va-button icon="fa-file-zipper" @click="openUpload">
            {{ $t('admin.packages.installFromZip') }}
          </va-button>
        </div>
      </div>

      <!-- Package Table -->
      <va-scroll-container color="primary" horizontal>
        <table class="va-table va-table--hoverable w-full">
          <thead>
            <tr>
              <th>{{ $t('admin.packages.package') }}</th>
              <th>{{ $t('admin.packages.description') }}</th>
              <th>{{ $t('admin.packages.version') }}</th>
              <th>{{ $t('admin.packages.status') }}</th>
              <th class="text-right">{{ $t('admin.packages.actions') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="filtered.length === 0">
              <td colspan="5" class="text-center py-6 text-gray-400">
                {{ $t('admin.packages.noPackagesFound') }}
              </td>
            </tr>
            <tr v-for="pkg in filtered" :key="pkg.name">
              <td>
                <a :href="'/admin/packages/' + pkg.name" class="hover:underline">
                  <span class="block font-semibold text-primary">{{ pkg.label }}</span>
                  <span class="block font-mono text-xs text-gray-400">{{ pkg.name }}</span>
                </a>
                <div v-if="pkg.source === 'local'" class="text-xs text-gray-400">{{ $t('admin.packages.localOnly') }}</div>
              </td>
              <td class="text-sm text-gray-600 max-w-xs truncate">
                {{ pkg.description || '—' }}
              </td>
              <td class="font-mono text-sm">
                <span v-if="pkg.installed">{{ pkg.version ?? '?' }}</span>
                <span v-else class="text-gray-400">{{ pkg.latest ?? '—' }}</span>
                <div v-if="pkg.updateAvailable" class="text-xs text-warning mt-0.5">
                  {{ $t('admin.packages.latestAvailable', { version: pkg.latest }) }}
                </div>
              </td>
              <td>
                <va-badge
                  :color="statusColor(pkg)"
                  :text="statusLabel(pkg)"
                />
                <va-badge
                  v-if="pkg.isUnstable"
                  color="warning"
                  :text="$t('admin.packages.unstable')"
                  class="ml-1"
                />
              </td>
              <td class="text-right whitespace-nowrap">
                <!-- Install -->
                <va-button
                  v-if="!pkg.installed"
                  preset="plain"
                  icon="fa-download"
                  color="primary"
                  :title="$t('admin.packages.install')"
                  @click="confirmInstall(pkg)"
                />

                <!-- Enable / Disable toggle -->
                <va-button
                  v-if="pkg.installed"
                  preset="plain"
                  :icon="pkg.enabled ? 'fa-toggle-on' : 'fa-toggle-off'"
                  :color="pkg.enabled ? 'success' : 'warning'"
                  :title="pkg.enabled ? $t('admin.packages.disable') : $t('admin.packages.enable')"
                  @click="toggleModule(pkg)"
                />

                <!-- Upgrade -->
                <va-button
                  v-if="pkg.updateAvailable"
                  preset="plain"
                  icon="fa-circle-up"
                  color="warning"
                  :title="$t('admin.packages.upgrade')"
                  @click="upgradePackage(pkg)"
                />

                <!-- Info -->
                <va-button
                  preset="plain"
                  icon="fa-circle-info"
                  color="secondary"
                  :title="$t('admin.packages.viewInfo')"
                  :href="'/admin/packages/' + pkg.name"
                  tag="a"
                />

                <!-- Delete -->
                <va-button
                  v-if="pkg.installed"
                  preset="plain"
                  icon="delete"
                  color="danger"
                  :title="$t('common.remove')"
                  @click="confirmDelete(pkg)"
                />
              </td>
            </tr>
          </tbody>
        </table>
      </va-scroll-container>

      <div class="mt-3 text-sm text-gray-400">
        {{ $t(filtered.length !== 1 ? 'admin.packages.packagesShown' : 'admin.packages.packageShown', { count: filtered.length }) }}
      </div>
    </va-card-content>
  </va-card>

  <!-- Upload ZIP Modal -->
  <va-modal v-model="showUpload" no-outside-dismiss no-padding size="small">
    <template #content="{ ok }">
      <form @submit.prevent="submitUpload">
        <va-card-title>{{ $t('admin.packages.installFromZip') }}</va-card-title>
        <va-card-content>
          <p class="text-sm text-gray-500 mb-3">
            {{ $t('admin.packages.zipInstructions') }}
          </p>
          <va-file-upload
            v-model="uploadForm.module"
            file-types="zip"
            type="single"
            dropzone
            :error="!!uploadForm.errors.module"
            :error-messages="uploadForm.errors.module"
            class="w-full"
          />
        </va-card-content>
        <va-card-actions align="right">
          <va-button color="textInverted" :disabled="uploadForm.processing" @click="ok">{{ $t('common.cancel') }}</va-button>
          <va-button
            type="submit"
            icon="fa-file-zipper"
            :disabled="uploadForm.processing || !uploadForm.module"
            :loading="uploadForm.processing"
          >{{ $t('admin.packages.install') }}</va-button>
        </va-card-actions>
      </form>
    </template>
  </va-modal>

  <!-- Delete Confirmation Modal -->
  <va-modal v-model="showDeleteConfirm" no-padding size="small">
    <template #content="{ ok }">
      <va-card-title>{{ $t('admin.packages.removePackageTitle') }}</va-card-title>
      <va-card-content>
        <p>{{ $t('admin.packages.removePackageConfirm', { name: pendingDeletePkg?.name }) }}</p>
        <p class="text-sm text-gray-500 mt-1">{{ $t('admin.packages.removePackageMessage') }}</p>
      </va-card-content>
      <va-card-actions align="right">
        <va-button color="textInverted" @click="ok">{{ $t('common.cancel') }}</va-button>
        <va-button color="danger" @click="executeDelete">{{ $t('common.remove') }}</va-button>
      </va-card-actions>
    </template>
  </va-modal>

  <!-- Install Confirmation Modal -->
  <va-modal v-model="showInstallConfirm" no-padding size="small">
    <template #content="{ ok }">
      <va-card-title>{{ $t('admin.packages.installPackageTitle') }}</va-card-title>
      <va-card-content>
        <p>{{ $t('admin.packages.installPackageConfirm', { name: pendingInstallPkg?.name }) }}</p>
        <p v-if="pendingInstallPkg?.latest" class="text-sm text-gray-500 mt-1">
          {{ $t('admin.packages.latestVersionLabel') }} <span class="font-mono">{{ pendingInstallPkg.latest }}</span>
        </p>
      </va-card-content>
      <va-card-actions align="right">
        <va-button color="textInverted" @click="ok">{{ $t('common.cancel') }}</va-button>
        <va-button icon="fa-download" @click="executeInstall">{{ $t('admin.packages.install') }}</va-button>
      </va-card-actions>
    </template>
  </va-modal>
</template>
