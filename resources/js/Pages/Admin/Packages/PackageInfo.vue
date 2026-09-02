<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { router } from '@inertiajs/vue3'
import { defineComponent } from 'vue'
</script>

<template>
  <Head>
    <title>{{ package.label }} - {{ $t('admin.packages.packageManager') }} - Control Panel</title>
  </Head>

  <!-- Header Card -->
  <va-card class="mb-4">
    <va-card-content>
      <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
          <div class="flex items-center gap-3 mb-1">
            <div>
              <h2 class="text-xl font-bold">{{ package.label }}</h2>
              <span class="font-mono text-xs text-gray-400">{{ package.name }}</span>
            </div>
            <va-badge
              v-if="package.installed"
              :color="package.enabled ? 'success' : 'warning'"
              :text="package.enabled ? $t('admin.packages.filterEnabled') : $t('admin.packages.filterDisabled')"
            />
            <va-badge v-else color="secondary" :text="$t('admin.packages.notInstalled')" />
            <va-badge v-if="package.updateAvailable" color="warning" :text="$t('admin.packages.updateAvailable')" />
            <va-badge v-if="package.isUnstable" color="warning" :text="$t('admin.packages.unstable')" />
          </div>
          <p class="text-gray-500 text-sm">{{ package.description || $t('admin.packages.noDescription') }}</p>
          <div v-if="package.keywords.length" class="flex flex-wrap gap-1 mt-2">
            <va-chip
              v-for="kw in package.keywords"
              :key="kw"
              size="small"
              color="primary"
            >{{ kw }}</va-chip>
          </div>
        </div>

        <!-- Action Buttons -->
        <div class="flex flex-wrap gap-2">
          <!-- Install -->
          <va-button
            v-if="!package.installed"
            icon="fa-download"
            :loading="busy"
            :disabled="busy"
            @click="install"
          >{{ $t('admin.packages.install') }}</va-button>

          <!-- Upgrade -->
          <va-button
            v-if="package.updateAvailable"
            icon="fa-circle-up"
            color="warning"
            :loading="busy"
            :disabled="busy"
            @click="upgrade"
          >{{ $t('admin.packages.upgradeToVersion', { version: package.latest }) }}</va-button>

          <!-- Enable / Disable -->
          <va-button
            v-if="package.installed"
            :icon="package.enabled ? 'fa-toggle-off' : 'fa-toggle-on'"
            :color="package.enabled ? 'warning' : 'success'"
            :loading="busy"
            :disabled="busy"
            @click="toggleModule"
          >{{ package.enabled ? $t('admin.packages.disable') : $t('admin.packages.enable') }}</va-button>

          <!-- Remove -->
          <va-button
            v-if="package.installed"
            icon="delete"
            color="danger"
            preset="outlined"
            :loading="busy"
            :disabled="busy"
            @click="confirmDelete"
          >{{ $t('common.remove') }}</va-button>
        </div>
      </div>
    </va-card-content>
  </va-card>

  <div class="row gap-4">
    <!-- Details Column -->
    <div class="flex flex-col md12">
      <!-- Metadata -->
      <va-card class="mb-4">
        <va-card-title>{{ $t('admin.packages.packageDetails') }}</va-card-title>
        <va-card-content>
          <va-scroll-container horizontal>
            <table class="va-table w-full">
              <tbody>
                <tr>
                  <td class="font-semibold text-gray-500 w-40">{{ $t('admin.packages.package') }}</td>
                  <td class="font-mono">{{ package.name }}</td>
                </tr>
                <tr>
                  <td class="font-semibold text-gray-500">{{ $t('admin.packages.type') }}</td>
                  <td>{{ package.type }}</td>
                </tr>
                <tr>
                  <td class="font-semibold text-gray-500">{{ $t('admin.packages.latestVersion') }}</td>
                  <td class="font-mono">{{ package.latest ?? '—' }}</td>
                </tr>
                <tr v-if="package.installed">
                  <td class="font-semibold text-gray-500">{{ $t('admin.packages.installedVersion') }}</td>
                  <td class="font-mono">{{ package.version ?? '?' }}</td>
                </tr>
                <tr v-if="package.license.length">
                  <td class="font-semibold text-gray-500">{{ $t('admin.packages.license') }}</td>
                  <td>{{ package.license.join(', ') }}</td>
                </tr>
                <tr v-if="package.homepage">
                  <td class="font-semibold text-gray-500">{{ $t('admin.packages.homepage') }}</td>
                  <td>
                    <a :href="package.homepage" target="_blank" class="text-primary hover:underline break-all">
                      {{ package.homepage }}
                    </a>
                  </td>
                </tr>
                <tr v-if="package.installed && package.path">
                  <td class="font-semibold text-gray-500">{{ $t('admin.packages.modulePath') }}</td>
                  <td class="font-mono text-sm break-all">{{ package.path }}</td>
                </tr>
                <tr v-if="package.installed">
                  <td class="font-semibold text-gray-500">{{ $t('admin.packages.moduleName') }}</td>
                  <td class="font-mono">{{ package.module_name }}</td>
                </tr>
              </tbody>
            </table>
          </va-scroll-container>
        </va-card-content>
        <template v-if="Object.keys(package.require).length">
          <va-card-title>{{ $t('admin.packages.requirements') }}</va-card-title>
          <va-card-content>
            <va-scroll-container horizontal>
              <table class="va-table w-full">
                <thead>
                  <tr>
                    <th>{{ $t('admin.packages.package') }}</th>
                    <th>{{ $t('admin.packages.constraint') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(constraint, dep) in package.require" :key="dep">
                    <td class="font-mono text-sm">{{ dep }}</td>
                    <td class="font-mono text-sm text-gray-500">{{ constraint }}</td>
                  </tr>
                </tbody>
              </table>
            </va-scroll-container>
          </va-card-content>
        </template>
        <va-card-title>{{ $t('admin.packages.authors') }}</va-card-title>
        <va-card-content>
          <div
            v-for="author in package.authors"
            :key="author.email ?? author.name"
            class="mb-2"
          >
            <div class="font-semibold text-sm">{{ author.name ?? '—' }}</div>
            <div v-if="author.email" class="text-gray-400 text-xs">{{ author.email }}</div>
            <a
              v-if="author.homepage"
              :href="author.homepage"
              target="_blank"
              class="text-primary text-xs hover:underline"
            >{{ author.homepage }}</a>
          </div>
        </va-card-content>
      </va-card>
    </div>
  </div>

  <!-- Delete Confirmation Modal -->
  <va-modal v-model="showDeleteConfirm" no-padding size="small">
    <template #content="{ ok }">
      <va-card-title>{{ $t('admin.packages.removePackageTitle') }}</va-card-title>
      <va-card-content>
        <p>{{ $t('admin.packages.removePackageConfirm', { name: package.name }) }}</p>
        <p class="text-sm text-gray-500 mt-1">{{ $t('admin.packages.removePackageMessage') }}</p>
      </va-card-content>
      <va-card-actions align="right">
        <va-button color="textInverted" @click="ok">{{ $t('common.cancel') }}</va-button>
        <va-button color="danger" :loading="busy" @click="executeDelete">{{ $t('common.remove') }}</va-button>
      </va-card-actions>
    </template>
  </va-modal>
</template>

<script lang="ts">

interface Author {
  name?: string
  email?: string
  homepage?: string
}

interface Package {
  name: string
  label: string
  vendor: string
  package: string
  description: string
  latest: string | null
  type: string
  keywords: string[]
  homepage: string
  authors: Author[]
  require: Record<string, string>
  license: string[]
  installed: boolean
  enabled: boolean
  version: string | null
  updateAvailable: boolean
  isUnstable: boolean
  path: string | null
  module_name: string
}

export default defineComponent({
  layout: (h: any, page: any) => h(AppLayout, [page]),

  props: {
    package: {
      type: Object as () => Package,
      required: true,
    },
  },

  data() {
    return {
      busy: false,
      showDeleteConfirm: false,
    }
  },

  methods: {
    install() {
      this.busy = true
      router.post(
        `/admin/packages/${this.package.vendor}/${this.package.package}/install`,
        {},
        { onFinish: () => (this.busy = false) }
      )
    },

    upgrade() {
      this.busy = true
      router.post(
        `/admin/packages/${this.package.vendor}/${this.package.package}/upgrade`,
        {},
        { onFinish: () => (this.busy = false) }
      )
    },

    confirmDelete() {
      this.showDeleteConfirm = true
    },

    executeDelete() {
      this.showDeleteConfirm = false
      this.busy = true
      router.delete(
        `/admin/packages/${this.package.vendor}/${this.package.package}`,
        { onFinish: () => (this.busy = false) }
      )
    },

    toggleModule() {
      const action = this.package.enabled ? 'disable' : 'enable'
      this.busy = true
      router.post(
        `/admin/packages/${this.package.vendor}/${this.package.package}/${action}`,
        {},
        { onFinish: () => (this.busy = false) }
      )
    },
  },
})
</script>
