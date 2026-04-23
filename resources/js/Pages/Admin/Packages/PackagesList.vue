<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { router, useForm } from '@inertiajs/vue3'
import { ref, computed } from 'vue'

const props = defineProps<{
  packages: Array<{
    name: string
    description: string
    latest: string | null
    type: string
    installed: boolean
    enabled: boolean
    version: string | null
    source: string
  }>
}>()

const search      = ref('')
const filterState = ref('all')
const showInstall = ref(false)

const installForm = useForm({
  package: '',
  version: '',
})

const filtered = computed(() => {
  return props.packages.filter(pkg => {
    const matchSearch =
      !search.value ||
      pkg.name.toLowerCase().includes(search.value.toLowerCase()) ||
      (pkg.description || '').toLowerCase().includes(search.value.toLowerCase())

    const matchState =
      filterState.value === 'all' ||
      (filterState.value === 'installed' && pkg.installed) ||
      (filterState.value === 'available' && !pkg.installed) ||
      (filterState.value === 'enabled' && pkg.enabled) ||
      (filterState.value === 'disabled' && pkg.installed && !pkg.enabled)

    return matchSearch && matchState
  })
})

function openInstall(packageName?: string) {
  installForm.reset()
  installForm.package = packageName ?? ''
  showInstall.value = true
}

function submitInstall() {
  installForm.post('/admin/packages/download', {
    onSuccess: () => {
      showInstall.value = false
      installForm.reset()
    },
  })
}

function confirmDelete(pkg: { name: string }) {
  if (!confirm(`Remove "${pkg.name}"? This will run composer remove and delete the module directory.`)) return
  const [vendor, name] = pkg.name.split('/')
  router.delete(`/admin/packages/${vendor}/${name}`)
}

function toggleModule(pkg: { name: string; enabled: boolean }) {
  const [vendor, name] = pkg.name.split('/')
  const action = pkg.enabled ? 'disable' : 'enable'
  router.post(`/admin/packages/${vendor}/${name}/${action}`)
}

function statusColor(pkg: { installed: boolean; enabled: boolean }) {
  if (!pkg.installed) return 'secondary'
  return pkg.enabled ? 'success' : 'warning'
}

function statusLabel(pkg: { installed: boolean; enabled: boolean }) {
  if (!pkg.installed) return 'Available'
  return pkg.enabled ? 'Enabled' : 'Disabled'
}
</script>

<template>
  <Head>
    <title>Package Manager - Control Panel</title>
  </Head>

  <va-card class="mb-4">
    <va-card-title>
      <span>Package Manager</span>
    </va-card-title>

    <va-card-content>
      <!-- Toolbar -->
      <div class="row mb-4 gap-3 items-end">
        <div class="flex flex-col md6">
          <va-input
            v-model="search"
            placeholder="Search packages…"
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
            label="Filter"
            :options="[
              { text: 'All', value: 'all' },
              { text: 'Installed', value: 'installed' },
              { text: 'Available', value: 'available' },
              { text: 'Enabled', value: 'enabled' },
              { text: 'Disabled', value: 'disabled' },
            ]"
            text-by="text"
            value-by="value"
          />
        </div>

        <div class="flex flex-col md3 items-end">
          <va-button icon="fa-download" @click="openInstall()">
            Install Package
          </va-button>
        </div>
      </div>

      <!-- Package Table -->
      <va-scroll-container color="primary" horizontal>
        <table class="va-table va-table--hoverable w-full">
          <thead>
            <tr>
              <th>Package</th>
              <th>Description</th>
              <th>Version</th>
              <th>Status</th>
              <th class="text-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="filtered.length === 0">
              <td colspan="5" class="text-center py-6 text-gray-400">
                No packages found.
              </td>
            </tr>
            <tr v-for="pkg in filtered" :key="pkg.name">
              <td>
                <a
                  :href="'/admin/packages/' + pkg.name.replace('/', '/')"
                  class="font-mono font-semibold text-primary hover:underline"
                >{{ pkg.name }}</a>
                <div v-if="pkg.source === 'local'" class="text-xs text-gray-400">local only</div>
              </td>
              <td class="text-sm text-gray-600 max-w-xs truncate">
                {{ pkg.description || '—' }}
              </td>
              <td class="font-mono text-sm">
                <span v-if="pkg.installed">{{ pkg.version ?? '?' }}</span>
                <span v-else class="text-gray-400">{{ pkg.latest ?? '—' }}</span>
              </td>
              <td>
                <va-badge
                  :color="statusColor(pkg)"
                  :text="statusLabel(pkg)"
                />
              </td>
              <td class="text-right whitespace-nowrap">
                <!-- Install -->
                <va-button
                  v-if="!pkg.installed"
                  preset="plain"
                  icon="fa-download"
                  color="primary"
                  size="small"
                  title="Install"
                  @click="openInstall(pkg.name)"
                />

                <!-- Enable / Disable toggle -->
                <va-button
                  v-if="pkg.installed"
                  preset="plain"
                  :icon="pkg.enabled ? 'fa-toggle-on' : 'fa-toggle-off'"
                  :color="pkg.enabled ? 'success' : 'warning'"
                  size="small"
                  :title="pkg.enabled ? 'Disable' : 'Enable'"
                  @click="toggleModule(pkg)"
                />

                <!-- Info -->
                <va-button
                  preset="plain"
                  icon="fa-circle-info"
                  color="secondary"
                  size="small"
                  title="View info"
                  :href="'/admin/packages/' + pkg.name"
                  tag="a"
                />

                <!-- Delete -->
                <va-button
                  v-if="pkg.installed"
                  preset="plain"
                  icon="delete"
                  color="danger"
                  size="small"
                  title="Remove"
                  @click="confirmDelete(pkg)"
                />
              </td>
            </tr>
          </tbody>
        </table>
      </va-scroll-container>

      <div class="mt-3 text-sm text-gray-400">
        {{ filtered.length }} package{{ filtered.length !== 1 ? 's' : '' }} shown
      </div>
    </va-card-content>
  </va-card>

  <!-- Install Modal -->
  <va-modal v-model="showInstall" no-outside-dismiss no-padding size="small">
    <template #content="{ ok }">
      <form @submit.prevent="submitInstall">
        <va-card-title>Install Package</va-card-title>
        <va-card-content>
          <va-input
            v-model="installForm.package"
            label="Package name"
            placeholder="vendor/package-name"
            required-mark
            class="mb-3 w-full"
            :error="!!$page.props.errors.package"
            :error-messages="$page.props.errors.package"
          />
          <va-input
            v-model="installForm.version"
            label="Version constraint (optional)"
            placeholder="e.g. ^1.0 or 1.2.3"
            class="mb-1 w-full"
          />
          <p class="text-xs text-gray-400 mt-1">
            Leave blank to install the latest stable release.
          </p>
        </va-card-content>
        <va-card-actions align="right">
          <va-button
            color="textInverted"
            :disabled="installForm.processing"
            @click="ok"
          >Cancel</va-button>
          <va-button
            type="submit"
            :disabled="installForm.processing || !installForm.package"
            :loading="installForm.processing"
          >Install</va-button>
        </va-card-actions>
      </form>
    </template>
  </va-modal>
</template>

<script lang="ts">
export default {
  layout: (h: any, page: any) => h(AppLayout, [page]),
}
</script>
