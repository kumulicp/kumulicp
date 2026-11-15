<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import ChangeSummary from '@/components/updates/ChangeSummary.vue'
import { Link, router } from '@inertiajs/vue3'
import axios from 'axios'

</script>
<template>
  <Head>
    <title>{{ $t('admin.updates.title') }} - Control Panel</title>
  </Head>
  <va-card class="mb-4">
    <va-card-title>{{ $t('admin.updates.title') }}</va-card-title>
    <va-card-content>
      <p class="secondary mb-3">{{ $t('admin.updates.description') }}</p>
      <div class="row align-center justify-space-between">
        <div class="flex">
          <template v-if="summary.instances > 0">
            <strong id="summaryInstances">{{ $t('admin.updates.appInstances', { count: summary.instances }) }}</strong>
            <span class="secondary"> · {{ $t('admin.updates.organizations', { count: summary.organizations }) }}</span>
            <span v-if="summary.since" class="secondary"> · {{ $t('admin.updates.since', { date: formatDate(summary.since) }) }}</span>
          </template>
          <p v-else id="noUpdates">{{ $t('admin.updates.none') }}</p>
        </div>
        <va-button id="scan" preset="secondary" icon="refresh" :disabled="scanning" @click="scan">
          {{ $t('admin.updates.checkAgain') }}
        </va-button>
      </div>
      <div v-if="summary.aspects.length > 0" class="mt-3">
        <va-badge
          v-for="aspect in summary.aspects"
          :key="aspect.key"
          :id="'aspect-' + aspect.key"
          class="mr-2 clickable-icon"
          :color="filters.aspect === aspect.key ? 'primary' : 'warning'"
          :text="aspect.label + ' · ' + aspect.instances"
          role="button"
          @click="filter({ aspect: filters.aspect === aspect.key ? null : aspect.key })"
        />
      </div>
    </va-card-content>
  </va-card>

  <va-card id="policy" class="mb-4">
    <va-card-title>{{ $t('admin.updates.autoTitle') }}</va-card-title>
    <va-card-content>
      <p class="secondary mb-3">{{ $t('admin.updates.autoHelp') }}</p>
      <va-switch
        id="autoEnabled"
        :model-value="policy.enabled"
        :label="$t('admin.updates.autoSwitch')"
        @update:model-value="setAutoEnabled"
      />
      <table v-if="policy.enabled" class="va-table mt-3">
        <tbody v-for="group in policyGroups" :key="group.name ?? 'core'" :class="'policy-group-' + (group.name ?? 'core')">
          <tr>
            <th colspan="2">{{ group.name ?? $t('admin.updates.autoCore') }}</th>
          </tr>
          <tr v-for="aspect in group.aspects" :key="aspect.key">
            <td>
              {{ aspect.label }}
              <va-badge v-if="aspect.disruptive" class="ml-2" color="warning" :text="$t('admin.updates.disruptiveTag')" />
            </td>
            <td class="text-right">
              <va-switch
                :id="'auto-' + group.id + '-' + aspect.key"
                :model-value="aspect.mode === 'auto'"
                :label="$t('admin.updates.autoAspect')"
                @update:model-value="setAspectMode(aspect, $event)"
              />
            </td>
          </tr>
        </tbody>
      </table>
    </va-card-content>
  </va-card>

  <template v-if="summary.instances > 0">
    <va-card id="groups" class="mb-4">
      <va-card-title>{{ $t('admin.updates.byChange') }}</va-card-title>
      <va-card-content>
        <VaScrollContainer color="primary" horizontal>
          <table class="va-table va-table--hoverable mt-3">
            <thead>
              <tr>
                <th>{{ $t('admin.updates.changed') }}</th>
                <th>{{ $t('admin.updates.outOfDate') }}</th>
                <th>{{ $t('admin.updates.instances') }}</th>
                <th />
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="group in groups"
                :key="group.change ? group.change.id : 'none'"
                :class="{ 'row-active': isSelectedGroup(group) }"
              >
                <td>
                  <ChangeSummary :change="group.change" />
                  <div v-if="group.since" class="secondary">{{ $t('admin.updates.since', { date: formatDate(group.since) }) }}</div>
                </td>
                <td>
                  <va-badge
                    v-for="aspect in group.aspects"
                    :key="aspect.key"
                    class="mr-1"
                    color="warning"
                    :text="aspect.label + ' · ' + aspect.instances"
                  />
                </td>
                <td>
                  {{ $t('admin.updates.appInstances', { count: group.instances }) }}
                  <div class="secondary">{{ $t('admin.updates.organizations', { count: group.organizations }) }}</div>
                </td>
                <td class="text-right">
                  <va-button
                    preset="secondary"
                    size="small"
                    class="mr-1"
                    :class="'review-' + groupKey(group)"
                    @click="filter({ change: isSelectedGroup(group) ? null : groupKey(group) })"
                  >{{ isSelectedGroup(group) ? $t('admin.updates.showEverything') : $t('admin.updates.review') }}</va-button>
                  <va-button
                    size="small"
                    class="mr-1"
                    :class="'apply-' + groupKey(group)"
                    @click="askToApply({ change: groupKey(group) })"
                  >{{ $t('admin.updates.updateThese') }}</va-button>
                  <va-button
                    preset="secondary"
                    size="small"
                    color="warning"
                    :class="'dismiss-' + groupKey(group)"
                    @click="askToDismiss({ change: groupKey(group) })"
                  >{{ $t('admin.updates.dismiss') }}</va-button>
                </td>
              </tr>
            </tbody>
          </table>
        </VaScrollContainer>
      </va-card-content>
    </va-card>

    <va-card id="instances" class="mb-4">
      <va-card-title>{{ $t('admin.updates.instances') }}</va-card-title>
      <va-card-content>
        <div class="row align-center mb-2">
          <va-checkbox
            id="selectAll"
            :model-value="allSelected"
            :indeterminate="selected.length > 0 && !allSelected"
            :label="$t('admin.updates.selectAll')"
            @update:model-value="selectAll"
          />
          <va-button
            id="applySelected"
            class="ml-4"
            size="small"
            :disabled="selected.length === 0"
            @click="askToApply({ instance_ids: selected })"
          >{{ $t('admin.updates.updateSelected') }}</va-button>
          <va-button
            id="dismissSelected"
            class="ml-2"
            preset="secondary"
            size="small"
            color="warning"
            :disabled="selected.length === 0"
            @click="askToDismiss({ instance_ids: selected })"
          >{{ $t('admin.updates.dismissSelected') }}</va-button>
        </div>
        <p v-if="instances.length === 0" class="secondary">{{ $t('admin.updates.noInstances') }}</p>
        <VaScrollContainer v-else color="primary" horizontal>
          <table class="va-table va-table--hoverable mt-3">
            <thead>
              <tr>
                <th />
                <th>{{ $t('admin.updates.organization') }}</th>
                <th>{{ $t('admin.updates.application') }}</th>
                <th>{{ $t('admin.updates.version') }}</th>
                <th>{{ $t('admin.updates.outOfDate') }}</th>
                <th />
              </tr>
            </thead>
            <tbody>
              <tr v-for="instance in instances" :key="instance.id">
                <td><va-checkbox v-model="selected" :array-value="instance.id" /></td>
                <td><Link :href="'/admin/organizations/' + instance.organization.id">{{ instance.organization.name }}</Link></td>
                <td>{{ instance.label }}</td>
                <td>{{ instance.version }}</td>
                <td>
                  <div v-for="aspect in instance.aspects" :key="aspect.key">
                    <va-badge class="mr-1" color="warning" :text="aspect.label" />
                    <span class="secondary"><ChangeSummary :change="aspect.change" /></span>
                  </div>
                </td>
                <td class="text-right">
                  <Link :href="'/admin/organizations/' + instance.organization.id + '/apps/' + instance.id + '/edit'">
                    <va-button size="small" :class="'update-' + instance.id">{{ $t('admin.updates.update') }}</va-button>
                  </Link>
                </td>
              </tr>
            </tbody>
          </table>
        </VaScrollContainer>
        <va-pagination
          v-if="pagination.last_page > 1"
          class="mt-3 mb-3 justify-center"
          :model-value="pagination.current_page"
          :total="pagination.total"
          :page-size="pagination.per_page"
          :direction-links="false"
          @update:model-value="goToPage"
        />
      </va-card-content>
    </va-card>
  </template>

  <va-modal v-model="showApplyModal" hide-default-actions no-padding class="p-0">
    <template #content="{ ok }">
      <va-card-title class="m-0">{{ $t('admin.updates.applyTitle') }}</va-card-title>
      <va-card-content class="m-0">
        <p v-if="!preview" class="secondary">{{ $t('admin.updates.applyLoading') }}</p>
        <p v-else-if="preview.instances === 0" id="applyNothing">{{ $t('admin.updates.applyNothing') }}</p>
        <template v-else>
          <p id="applySummary">{{ $t('admin.updates.applySummary', { count: preview.instances }) }}</p>
          <table class="va-table mt-2 mb-2">
            <tbody>
              <tr v-for="step in preview.steps" :key="step.key">
                <td>
                  {{ step.label }}
                  <va-badge v-if="step.disruptive" class="ml-2" color="warning" :text="$t('admin.updates.disruptiveTag')" />
                </td>
                <td class="text-right">{{ $t('admin.updates.appInstances', { count: step.instances }) }}</td>
              </tr>
            </tbody>
          </table>
          <va-alert v-if="preview.disruptive" id="applyDisruptive" color="warning" icon="warning" class="mb-2">
            {{ $t('admin.updates.applyDisruptive') }}
          </va-alert>
          <p v-if="preview.skipped.not_active" class="secondary">{{ $t('admin.updates.applySkippedNotActive', { count: preview.skipped.not_active }) }}</p>
          <p v-if="preview.skipped.nothing_to_do" class="secondary">{{ $t('admin.updates.applySkippedNothing', { count: preview.skipped.nothing_to_do }) }}</p>
          <p id="applyWaves" class="secondary">{{ $t('admin.updates.applyWaves', { waves: preview.waves.join(', ') }) }}</p>
          <div class="row mt-3">
            <va-input id="canary" v-model.number="rollout.canary" class="flex xs6 pr-2" type="number" min="0" max="20" :label="$t('admin.updates.canary')" />
            <va-input id="batch" v-model.number="rollout.batch" class="flex xs6 pl-2" type="number" min="1" max="100" :label="$t('admin.updates.batch')" />
          </div>
          <div class="row mt-3">
            <va-input id="windowStart" v-model="rollout.start_time" class="flex xs6 pr-2" type="time" :label="$t('admin.updates.windowStart')" />
            <va-input id="windowEnd" v-model="rollout.end_time" class="flex xs6 pl-2" type="time" :label="$t('admin.updates.windowEnd')" />
          </div>
        </template>
      </va-card-content>
      <va-card-actions align="right">
        <va-button color="textInverted" :disabled="applying" @click="ok">{{ $t('common.cancel') }}</va-button>
        <va-button
          id="confirmApply"
          class="mr-2"
          :disabled="applying || !preview || preview.instances === 0"
          @click="apply(ok)"
        >{{ $t('admin.updates.applyConfirm') }}</va-button>
      </va-card-actions>
    </template>
  </va-modal>

  <va-modal v-model="showDismissModal" hide-default-actions no-padding class="p-0">
    <template #content="{ ok }">
      <va-card-title class="m-0">{{ $t('admin.updates.dismissTitle') }}</va-card-title>
      <va-card-content class="m-0">
        <p>{{ $t('admin.updates.dismissText') }}</p>
      </va-card-content>
      <va-card-actions align="right">
        <va-button color="textInverted" :disabled="dismissing" @click="ok">{{ $t('common.cancel') }}</va-button>
        <va-button id="confirmDismiss" class="mr-2" color="warning" :disabled="dismissing" @click="dismiss(ok)">
          {{ $t('admin.updates.dismissConfirm') }}
        </va-button>
      </va-card-actions>
    </template>
  </va-modal>
</template>

<script lang="ts">
export default {
  layout: (h, page) => h(AppLayout, [page]),
  props: {
    summary: Object,
    groups: Array,
    filters: Object,
    instances: Array,
    pagination: Object,
    policy: Object
  },
  data () {
    return {
      selected: [],
      scanning: false,
      showDismissModal: false,
      dismissing: false,
      dismissTarget: null,
      showApplyModal: false,
      applying: false,
      applyTarget: null,
      preview: null,
      rollout: { canary: 1, batch: 10, start_time: '', end_time: '' }
    }
  },
  computed: {
    // Core settings (what every app has) first, then each app with what is its own
    policyGroups () {
      const core = this.policy.aspects.filter(aspect => aspect.apps.length === 0)
      const apps = {}
      this.policy.aspects.forEach(aspect => {
        aspect.apps.forEach(app => { (apps[app] = apps[app] || []).push(aspect) })
      })

      const groups = Object.keys(apps).sort((a, b) => a.localeCompare(b)).map(name => ({ id: name, name, aspects: apps[name] }))

      return core.length > 0 ? [{ id: 'core', name: null, aspects: core }, ...groups] : groups
    },
    allSelected () {
      return this.instances.length > 0 && this.selected.length === this.instances.length
    }
  },
  watch: {
    // A new page, or another filter, is a new list
    instances () {
      this.selected = []
    },
    // How many are updated first or at a time changes the waves
    'rollout.canary' () {
      this.loadPreview()
    },
    'rollout.batch' () {
      this.loadPreview()
    }
  },
  methods: {
    formatDate (iso) {
      return new Date(iso).toLocaleDateString()
    },
    groupKey (group) {
      return group.change ? String(group.change.id) : 'none'
    },
    isSelectedGroup (group) {
      return this.filters.change === this.groupKey(group)
    },
    selectAll (selected) {
      this.selected = selected ? this.instances.map(instance => instance.id) : []
    },
    filter (changes) {
      const query = { change: this.filters.change, aspect: this.filters.aspect, ...changes }
      Object.keys(query).forEach(key => query[key] === null && delete query[key])

      router.get('/admin/server/updates', query, { preserveScroll: true })
    },
    goToPage (page) {
      const query = { change: this.filters.change, aspect: this.filters.aspect, page }
      Object.keys(query).forEach(key => query[key] === null && delete query[key])

      router.get('/admin/server/updates', query, { preserveScroll: true })
    },
    scan () {
      this.scanning = true
      router.post('/admin/server/updates/scan', {}, {
        preserveScroll: true,
        onFinish: () => { this.scanning = false }
      })
    },
    askToApply (target) {
      this.applyTarget = target
      this.preview = null
      this.showApplyModal = true
      this.loadPreview()
    },
    rolloutOptions () {
      const options = { canary: this.rollout.canary, batch: this.rollout.batch }
      if (this.rollout.start_time && this.rollout.end_time) {
        options.start_time = this.rollout.start_time
        options.end_time = this.rollout.end_time
      }

      return options
    },
    loadPreview () {
      if (!this.showApplyModal || !this.applyTarget) {
        return
      }

      const target = this.applyTarget
      axios.post('/admin/server/updates/preview', { ...target, ...this.rolloutOptions() }).then(response => {
        // Not the answer to a question that was asked before another
        if (target === this.applyTarget) {
          this.preview = response.data
        }
      })
    },
    apply (close) {
      this.applying = true
      router.post('/admin/server/updates/apply', { ...this.applyTarget, ...this.rolloutOptions() }, {
        preserveScroll: true,
        onSuccess: () => {
          this.selected = []
          close()
        },
        onFinish: () => { this.applying = false }
      })
    },
    setAutoEnabled (enabled) {
      router.post('/admin/server/updates/policy', { enabled }, { preserveScroll: true })
    },
    setAspectMode (aspect, auto) {
      router.post('/admin/server/updates/policy', { aspect: aspect.key, mode: auto ? 'auto' : 'manual' }, { preserveScroll: true })
    },
    askToDismiss (target) {
      this.dismissTarget = target
      this.showDismissModal = true
    },
    dismiss (close) {
      this.dismissing = true
      router.post('/admin/server/updates/dismiss', this.dismissTarget, {
        preserveScroll: true,
        onSuccess: () => {
          this.selected = []
          close()
        },
        onFinish: () => { this.dismissing = false }
      })
    }
  }
}
</script>

<style lang="scss">
.row-active {
  background: rgba(0, 0, 0, 0.04);
}
</style>
