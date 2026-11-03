<script setup>
import AppLayout from '@/layouts/AppLayout.vue'
</script>
<template>
  <Head>
    <title>{{ $t('tickets.reports.title') }} - Control Panel</title>
  </Head>
  <va-card class="mb-4">
    <va-card-title>
      <div class="flex justify-between items-center w-full">
        <span>{{ $t('tickets.reports.title') }}</span>
        <a :href="exportUrl" class="no-underline"><va-button size="small" color="secondary">{{ $t('tickets.reports.exportCsv') }}</va-button></a>
      </div>
    </va-card-title>
    <va-card-content>
      <div class="row items-end mb-3">
        <div class="flex xs12 md4 pr-2">
          <va-select v-model="days" :label="$t('tickets.reports.period')" :options="periodOptions" value-by="value" text-by="text" @update:model-value="reload" />
        </div>
        <div class="flex xs12 md8 integration-help">{{ report.period.from }} → {{ report.period.to }}</div>
      </div>

      <div class="row report-tiles mb-4">
        <div v-for="tile in tiles" :key="tile.label" class="flex xs6 md3 mb-2 pr-2">
          <div class="report-tile">
            <div class="report-tile__value">{{ tile.value }}</div>
            <div class="report-tile__label">{{ tile.label }}</div>
          </div>
        </div>
      </div>

      <h3 class="va-h6 mb-2">{{ $t('tickets.reports.perDay') }}</h3>
      <div class="report-bars mb-4" role="img" :aria-label="$t('tickets.reports.perDay')">
        <div v-for="day in report.per_day" :key="day.date" class="report-bars__day" :title="`${day.date}: ${day.created} / ${day.resolved}`">
          <div class="report-bars__bar report-bars__bar--created" :style="{ height: barHeight(day.created) }"></div>
          <div class="report-bars__bar report-bars__bar--resolved" :style="{ height: barHeight(day.resolved) }"></div>
        </div>
      </div>
      <div class="integration-help mb-4">
        <span class="report-key report-key--created"></span>{{ $t('tickets.reports.created') }}
        <span class="report-key report-key--resolved ml-3"></span>{{ $t('tickets.reports.resolved') }}
      </div>

      <div class="row">
        <div class="flex xs12 lg6 pr-2 mb-4">
          <h3 class="va-h6 mb-2">{{ $t('tickets.reports.satisfaction') }}</h3>
          <div v-if="report.csat.count">
            <div v-for="score in [5, 4, 3, 2, 1]" :key="score" class="report-row">
              <span class="report-row__label">{{ score }} ★</span>
              <span class="report-row__bar"><span :style="{ width: share(report.csat.distribution[score], report.csat.count) }"></span></span>
              <span class="report-row__value">{{ report.csat.distribution[score] }}</span>
            </div>
          </div>
          <div v-else class="integration-help">{{ $t('tickets.reports.noRatings') }}</div>
        </div>

        <div class="flex xs12 lg6 mb-4">
          <h3 class="va-h6 mb-2">{{ $t('tickets.reports.timeLogged') }}</h3>
          <table v-if="report.time.total" class="va-table">
            <thead><tr><th>{{ $t('tickets.organization') }}</th><th>{{ $t('tickets.reports.total') }}</th><th>{{ $t('tickets.time.billable') }}</th></tr></thead>
            <tbody>
              <tr v-for="row in report.time.by_organization" :key="row.name"><td>{{ row.name }}</td><td>{{ fmt(row.minutes) }}</td><td>{{ fmt(row.billable) }}</td></tr>
            </tbody>
          </table>
          <div v-else class="integration-help">{{ $t('tickets.reports.noTime') }}</div>
        </div>
      </div>

      <div class="row">
        <div v-for="table in tables" :key="table.key" class="flex xs12 lg6 pr-2 mb-4">
          <h3 class="va-h6 mb-2">{{ table.title }}</h3>
          <table class="va-table">
            <thead><tr><th>{{ $t('tickets.reports.name') }}</th><th>{{ $t('tickets.reports.tickets') }}</th><th>{{ $t('tickets.reports.firstResponse') }}</th><th>{{ $t('tickets.reports.rating') }}</th></tr></thead>
            <tbody>
              <tr v-for="row in table.rows" :key="row.name">
                <td>{{ row.name }}</td><td>{{ row.tickets }}</td><td>{{ fmt(row.first_response) }}</td><td>{{ row.csat ?? '-' }}</td>
              </tr>
              <tr v-if="!table.rows.length"><td colspan="4">-</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </va-card-content>
  </va-card>
</template>
<script>
import { router } from '@inertiajs/vue3'

export default {
  layout: (h, page) => h(AppLayout, [page]),
  props: {
    report: Object,
    range: Number,
  },
  data () {
    return { days: this.range ?? 30 }
  },
  computed: {
    periodOptions () {
      return [7, 30, 90, 365].map(value => ({ value, text: this.$t('tickets.reports.lastDays', { count: value }) }))
    },
    exportUrl () {
      return `/admin/support/reports/export.csv?from=${this.report.period.from}&to=${this.report.period.to}`
    },
    tiles () {
      const r = this.report
      const percent = value => value === null ? '-' : `${value}%`
      return [
        { label: this.$t('tickets.reports.created'), value: r.totals.created },
        { label: this.$t('tickets.reports.resolved'), value: r.totals.resolved },
        { label: this.$t('tickets.reports.openNow'), value: r.totals.open_now },
        { label: this.$t('tickets.reports.unassigned'), value: r.totals.unassigned_now },
        { label: this.$t('tickets.reports.firstResponseMedian'), value: this.fmt(r.first_response.median) },
        { label: this.$t('tickets.reports.resolutionMedian'), value: this.fmt(r.resolution.median) },
        { label: this.$t('tickets.reports.slaFirst'), value: percent(r.sla.first_response.percent) },
        { label: this.$t('tickets.reports.slaResolution'), value: percent(r.sla.resolution.percent) },
        { label: this.$t('tickets.reports.satisfactionAverage'), value: r.csat.average ?? '-' },
      ]
    },
    tables () {
      return [
        { key: 'priority', title: this.$t('tickets.reports.byPriority'), rows: this.report.by_priority },
        { key: 'category', title: this.$t('tickets.reports.byCategory'), rows: this.report.by_category },
        { key: 'assignee', title: this.$t('tickets.reports.byAssignee'), rows: this.report.by_assignee },
        { key: 'organization', title: this.$t('tickets.reports.byOrganization'), rows: this.report.by_organization },
      ]
    },
    peak () {
      return Math.max(1, ...this.report.per_day.map(day => Math.max(day.created, day.resolved)))
    },
  },
  methods: {
    reload () {
      router.get('/admin/support/reports', { days: this.days }, { preserveState: true, replace: true })
    },
    fmt (minutes) {
      if (minutes === null || minutes === undefined) return '-'
      const hours = Math.floor(minutes / 60)
      const rest = minutes % 60
      if (hours === 0) return `${rest}m`
      return rest ? `${hours}h ${rest}m` : `${hours}h`
    },
    barHeight (count) {
      return `${Math.round((count / this.peak) * 100)}%`
    },
    share (count, total) {
      return `${Math.round((count / total) * 100)}%`
    },
  },
}
</script>
<style lang="scss">
  .report-tile {
    width: 100%;
    padding: 0.75em 1em;
    border: 1px solid var(--va-background-border);
    border-radius: 6px;

    &__value { font-size: 1.6em; font-weight: 600; }
    &__label { color: var(--va-secondary); font-size: 0.85em; }
  }

  .report-bars {
    display: flex;
    align-items: flex-end;
    gap: 2px;
    height: 8em;
    padding: 0 0 1px;
    border-bottom: 1px solid var(--va-background-border);

    &__day { flex: 1; display: flex; align-items: flex-end; gap: 1px; height: 100%; min-width: 2px; }
    &__bar { flex: 1; min-height: 1px; border-radius: 1px 1px 0 0; }
    &__bar--created { background: var(--va-primary); }
    &__bar--resolved { background: var(--va-success); }
  }

  .report-key {
    display: inline-block;
    width: 0.8em;
    height: 0.8em;
    margin-right: 0.3em;
    border-radius: 2px;

    &--created { background: var(--va-primary); }
    &--resolved { background: var(--va-success); }
  }

  .report-row {
    display: flex;
    align-items: center;
    gap: 0.5em;
    margin-bottom: 0.3em;

    &__label { width: 3em; }
    &__bar { flex: 1; height: 0.8em; background: var(--va-background-secondary); border-radius: 3px; overflow: hidden; }
    &__bar > span { display: block; height: 100%; background: var(--va-warning); }
    &__value { width: 2.5em; text-align: right; }
  }
</style>
