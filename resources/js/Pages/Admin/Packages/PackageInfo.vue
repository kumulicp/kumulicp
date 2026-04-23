<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { router } from '@inertiajs/vue3'
import { ref } from 'vue'

const props = defineProps<{
  package: {
    name: string
    vendor: string
    package: string
    description: string
    versions: string[]
    latest: string | null
    type: string
    keywords: string[]
    homepage: string
    authors: Array<{ name?: string; email?: string; homepage?: string }>
    require: Record<string, string>
    license: string[]
    installed: boolean
    enabled: boolean
    version: string | null
    path: string | null
    module_name: string
  }
}>()

const selectedVersion = ref<string>(props.package.latest ?? '')
const busy = ref(false)

function install() {
  busy.value = true
  router.post(
    '/admin/packages/download',
    { package: props.package.name, version: selectedVersion.value || undefined },
    { onFinish: () => (busy.value = false) }
  )
}

function confirmDelete() {
  if (!confirm(`Remove "${props.package.name}"? This will run composer remove and delete the module directory.`)) return
  busy.value = true
  router.delete(
    `/admin/packages/${props.package.vendor}/${props.package.package}`,
    { onFinish: () => (busy.value = false) }
  )
}

function toggleModule() {
  const action = props.package.enabled ? 'disable' : 'enable'
  busy.value = true
  router.post(
    `/admin/packages/${props.package.vendor}/${props.package.package}/${action}`,
    {},
    { onFinish: () => (busy.value = false) }
  )
}
</script>

<template>
  <Head>
    <title>{{ package.name }} - Package Manager</title>
  </Head>

  <!-- Header Card -->
  <va-card class="mb-4">
    <va-card-content>
      <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
          <div class="flex items-center gap-3 mb-1">
            <h2 class="text-xl font-bold font-mono">{{ package.name }}</h2>
            <va-badge
              v-if="package.installed"
              :color="package.enabled ? 'success' : 'warning'"
              :text="package.enabled ? 'Enabled' : 'Disabled'"
            />
            <va-badge v-else color="secondary" text="Not installed" />
          </div>
          <p class="text-gray-500 text-sm">{{ package.description || 'No description provided.' }}</p>
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
          <!-- Install with version selector -->
          <template v-if="!package.installed">
            <va-select
              v-if="package.versions.length"
              v-model="selectedVersion"
              :options="package.versions"
              placeholder="Latest"
              class="w-36"
            />
            <va-button
              icon="fa-download"
              :loading="busy"
              :disabled="busy"
              @click="install"
            >Install</va-button>
          </template>

          <!-- Enable / Disable -->
          <va-button
            v-if="package.installed"
            :icon="package.enabled ? 'fa-toggle-off' : 'fa-toggle-on'"
            :color="package.enabled ? 'warning' : 'success'"
            :loading="busy"
            :disabled="busy"
            @click="toggleModule"
          >{{ package.enabled ? 'Disable' : 'Enable' }}</va-button>

          <!-- Remove -->
          <va-button
            v-if="package.installed"
            icon="delete"
            color="danger"
            preset="outlined"
            :loading="busy"
            :disabled="busy"
            @click="confirmDelete"
          >Remove</va-button>

          <!-- Back -->
          <va-button
            preset="plain"
            icon="fa-arrow-left"
            href="/admin/packages"
            tag="a"
          >Back</va-button>
        </div>
      </div>
    </va-card-content>
  </va-card>

  <div class="row gap-4">
    <!-- Details Column -->
    <div class="flex flex-col md8">
      <!-- Metadata -->
      <va-card class="mb-4">
        <va-card-title>Package Details</va-card-title>
        <va-card-content>
          <table class="va-table w-full">
            <tbody>
              <tr>
                <td class="font-semibold text-gray-500 w-40">Package</td>
                <td class="font-mono">{{ package.name }}</td>
              </tr>
              <tr>
                <td class="font-semibold text-gray-500">Type</td>
                <td>{{ package.type }}</td>
              </tr>
              <tr>
                <td class="font-semibold text-gray-500">Latest version</td>
                <td class="font-mono">{{ package.latest ?? '—' }}</td>
              </tr>
              <tr v-if="package.installed">
                <td class="font-semibold text-gray-500">Installed version</td>
                <td class="font-mono">{{ package.version ?? '?' }}</td>
              </tr>
              <tr v-if="package.license.length">
                <td class="font-semibold text-gray-500">License</td>
                <td>{{ package.license.join(', ') }}</td>
              </tr>
              <tr v-if="package.homepage">
                <td class="font-semibold text-gray-500">Homepage</td>
                <td>
                  <a :href="package.homepage" target="_blank" class="text-primary hover:underline break-all">
                    {{ package.homepage }}
                  </a>
                </td>
              </tr>
              <tr v-if="package.installed && package.path">
                <td class="font-semibold text-gray-500">Module path</td>
                <td class="font-mono text-sm break-all">{{ package.path }}</td>
              </tr>
              <tr v-if="package.installed">
                <td class="font-semibold text-gray-500">Module name</td>
                <td class="font-mono">{{ package.module_name }}</td>
              </tr>
            </tbody>
          </table>
        </va-card-content>
      </va-card>

      <!-- Requirements -->
      <va-card v-if="Object.keys(package.require).length" class="mb-4">
        <va-card-title>Requirements</va-card-title>
        <va-card-content>
          <table class="va-table w-full">
            <thead>
              <tr>
                <th>Package</th>
                <th>Constraint</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(constraint, dep) in package.require" :key="dep">
                <td class="font-mono text-sm">{{ dep }}</td>
                <td class="font-mono text-sm text-gray-500">{{ constraint }}</td>
              </tr>
            </tbody>
          </table>
        </va-card-content>
      </va-card>
    </div>

    <!-- Sidebar Column -->
    <div class="flex flex-col md4">
      <!-- Authors -->
      <va-card v-if="package.authors.length" class="mb-4">
        <va-card-title>Authors</va-card-title>
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

      <!-- Available Versions -->
      <va-card v-if="package.versions.length" class="mb-4">
        <va-card-title>Available Versions</va-card-title>
        <va-card-content>
          <div class="flex flex-wrap gap-1">
            <va-chip
              v-for="v in package.versions"
              :key="v"
              size="small"
              :color="v === package.latest ? 'primary' : 'secondary'"
            >{{ v }}</va-chip>
          </div>
        </va-card-content>
      </va-card>
    </div>
  </div>
</template>

<script lang="ts">
export default {
  layout: (h: any, page: any) => h(AppLayout, [page]),
}
</script>
