<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { router } from '@inertiajs/vue3'

</script>
<template>
  <Head>
    <title>{{ $t('admin.audit.audit') }} - Control Panel</title>
  </Head>
  <va-alert v-if="!enabled" color="warning" class="mb-4" icon="warning">
    <p class="va-h6 mb-1">{{ $t('admin.audit.disabledTitle') }}</p>
    <p>{{ $t('admin.audit.disabledText') }}</p>
    <va-button class="mt-2" size="small" href="/admin/settings" id="auditGoToSettings">{{ $t('admin.audit.goToSettings') }}</va-button>
  </va-alert>
  <va-card class="mb-4">
    <va-card-title>{{ $t('admin.audit.audit') }}</va-card-title>
    <va-card-content>
      <form class="audit-filters mb-3" @submit.prevent="applyFilters">
        <va-input v-model="form.event"
          :label="$t('admin.audit.filterEvent')"
          id="auditFilterEvent"
          class="mr-2 mb-2"
        />
        <va-input v-model="form.actor"
          :label="$t('admin.audit.filterActor')"
          id="auditFilterActor"
          class="mr-2 mb-2"
        />
        <va-select v-model="form.outcome"
          :label="$t('admin.audit.filterOutcome')"
          :options="outcomeOptions"
          clearable
          id="auditFilterOutcome"
          class="mr-2 mb-2"
        />
        <va-input v-model="form.from"
          type="date"
          :label="$t('admin.audit.from')"
          id="auditFilterFrom"
          class="mr-2 mb-2"
        />
        <va-input v-model="form.to"
          type="date"
          :label="$t('admin.audit.to')"
          id="auditFilterTo"
          class="mr-2 mb-2"
        />
        <va-button type="submit" id="auditApply" class="mr-2 mb-2">{{ $t('admin.audit.apply') }}</va-button>
        <va-button preset="secondary" id="auditReset" class="mr-2 mb-2" @click="resetFilters">{{ $t('admin.audit.reset') }}</va-button>
        <va-button preset="secondary" id="auditExport" class="mb-2" :href="exportUrl">{{ $t('admin.audit.export') }}</va-button>
      </form>

      <p v-if="entries.length === 0" class="va-text-secondary">{{ $t('admin.audit.none') }}</p>

      <va-scroll-container v-else color="warning" horizontal>
        <table class="va-table va-table--hoverable mt-3">
          <thead>
            <tr>
              <th style="width: 11rem">{{ $t('admin.audit.time') }}</th>
              <th>{{ $t('admin.audit.event') }}</th>
              <th style="width: 7rem">{{ $t('admin.audit.outcome') }}</th>
              <th>{{ $t('admin.audit.actor') }}</th>
              <th>{{ $t('admin.audit.subject') }}</th>
              <th style="width: 6rem">{{ $t('admin.audit.target') }}</th>
              <th>{{ $t('admin.audit.ip') }}</th>
              <th style="width: 6rem">{{ $t('admin.audit.details') }}</th>
            </tr>
          </thead>
          <tbody>
            <template v-for="entry in entries" :key="entry.id">
              <tr>
                <td>{{ entry.time }}</td>
                <td>{{ entry.event }}</td>
                <td><va-badge :text="entry.outcome" :color="outcomeColor(entry.outcome)" /></td>
                <td>{{ entry.actor }}</td>
                <td>{{ entry.subject_type ? entry.subject_type + ': ' : '' }}{{ entry.subject }}</td>
                <td>{{ entry.target_organization_id }}</td>
                <td>{{ entry.ip }}</td>
                <td>
                  <va-button v-if="entry.diff || entry.properties" size="small" preset="secondary" :id="'auditDetails' + entry.id" @click="toggle(entry.id)">
                    {{ $t('admin.audit.details') }}
                  </va-button>
                </td>
              </tr>
              <tr v-if="expanded.includes(entry.id)">
                <td colspan="8">
                  <pre v-if="entry.diff" class="audit-details">{{ JSON.stringify(entry.diff, null, 2) }}</pre>
                  <pre v-if="entry.properties" class="audit-details">{{ JSON.stringify(entry.properties, null, 2) }}</pre>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </va-scroll-container>

      <va-pagination v-if="meta.total > pageSize" class="mt-3 mb-3 justify-center" v-model="curPageValue" :pages="pages" input @update:modelValue="changePage" />
    </va-card-content>
  </va-card>
</template>

<script lang="ts">
export default {
  layout: (h, page) => h(AppLayout, [page]),
  props: {
    entries: Array,
    meta: Object,
    filters: Object,
    enabled: Boolean,
    retention_days: Number,
    streaming: Boolean
  },
  data () {
    return {
      curPageValue: this.meta.page,
      pages: this.meta.pages,
      pageSize: 30,
      expanded: [],
      outcomeOptions: ['success', 'failure', 'denied'],
      form: {
        event: this.filters.event ?? '',
        actor: this.filters.actor ?? '',
        outcome: this.filters.outcome ?? null,
        from: this.filters.from ?? '',
        to: this.filters.to ?? ''
      }
    }
  },
  computed: {
    activeFilters () {
      const params = {}

      Object.entries(this.form).forEach(([key, value]) => {
        if (value) {
          params[key] = value
        }
      })

      return params
    },
    exportUrl () {
      return '/admin/server/audit/export?' + new URLSearchParams(this.activeFilters).toString()
    }
  },
  methods: {
    applyFilters () {
      router.get('/admin/server/audit', this.activeFilters, { preserveScroll: true })
    },
    resetFilters () {
      router.get('/admin/server/audit', {}, { preserveScroll: true })
    },
    changePage () {
      router.get('/admin/server/audit', { ...this.activeFilters, page: this.curPageValue }, { preserveScroll: true })
    },
    toggle (id) {
      this.expanded = this.expanded.includes(id) ? this.expanded.filter(item => item !== id) : [...this.expanded, id]
    },
    outcomeColor (outcome) {
      return outcome === 'success' ? 'success' : (outcome === 'denied' ? 'warning' : 'danger')
    }
  }
}
</script>

<style lang="scss">
.audit-filters {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
}

.audit-details {
  max-height: 20rem;
  overflow: auto;
  white-space: pre-wrap;
  word-break: break-word;
}
</style>
