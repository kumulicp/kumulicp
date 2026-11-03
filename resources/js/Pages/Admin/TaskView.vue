<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { Link, router } from '@inertiajs/vue3'
import axios from 'axios'
import { useToast } from 'vuestic-ui'

</script>
<template>
  <Head>
    <title>{{ $t('admin.tasks.task') }} #{{ task.id }} - Control Panel</title>
  </Head>
  <va-card class="mb-4">
    <va-card-title>
      {{ task.description }}
      <va-badge class="ml-3" :color="statusColor(task.status)" :text="statusLabel(task.status)" />
    </va-card-title>
    <va-card-content>
      <div class="row mb-4">
        <div class="flex flex-col" style="flex-grow:1">
          <Link href="/admin/server/tasks">{{ $t('admin.tasks.back') }}</Link>
        </div>
        <div class="flex flex-col">
          <div class="item">
            <VaButton
              preset="secondary"
              icon="fa-trash-restore"
              color="primary"
              :disabled="!task.can_retry"
              @click="retry"
            >
              {{ $t('admin.tasks.retry') }}
            </VaButton>
            <VaButton
              preset="secondary"
              icon="cancel"
              color="warning"
              class="ml-2"
              :disabled="!task.can_cancel"
              @click="cancel"
            >
              {{ $t('admin.tasks.cancel') }}
            </VaButton>
            <VaButton
              preset="secondary"
              icon="delete"
              color="danger"
              class="ml-2"
              :disabled="!task.can_delete"
              @click="destroy"
            >
              {{ $t('admin.tasks.delete') }}
            </VaButton>
          </div>
        </div>
      </div>

      <va-alert v-if="task.error_message" color="warning" class="mb-4">
        <b v-if="task.error_code">{{ task.error_code }}:</b> {{ task.error_message }}
      </va-alert>

      <table class="va-table va-table--striped mb-4">
        <tbody>
          <tr>
            <td>{{ $t('admin.tasks.action') }}</td>
            <td>{{ task.action }}</td>
          </tr>
          <tr v-if="task.organization">
            <td>{{ $t('admin.tasks.organization') }}</td>
            <td><Link :href="'/admin/organizations/' + task.organization.id">{{ task.organization.name }}</Link></td>
          </tr>
          <tr v-if="task.application">
            <td>{{ $t('admin.tasks.application') }}</td>
            <td>{{ task.application }}</td>
          </tr>
          <tr v-if="task.app_instance">
            <td>{{ $t('admin.tasks.appInstance') }}</td>
            <td>{{ task.app_instance }}</td>
          </tr>
          <tr>
            <td>{{ $t('admin.tasks.attempts') }}</td>
            <td>{{ task.attempts }}</td>
          </tr>
          <tr v-if="task.blocked_from">
            <td>{{ $t('admin.tasks.blockedFrom') }}</td>
            <td>{{ task.blocked_from }}</td>
          </tr>
          <tr v-for="field in dates" :key="field.key">
            <td>{{ $t('admin.tasks.' + field.label) }}</td>
            <td>{{ formatDate(task[field.key]) }}</td>
          </tr>
        </tbody>
      </table>
    </va-card-content>
  </va-card>

  <va-card class="mb-4">
    <va-card-title>{{ $t('admin.tasks.timeline') }}</va-card-title>
    <va-card-content>
      <p v-if="events.length === 0">{{ $t('admin.tasks.nothingYet') }}</p>
      <va-scroll-container v-else color="primary" horizontal>
        <table class="va-table va-table--striped">
          <thead>
            <tr>
              <th>{{ $t('admin.tasks.when') }}</th>
              <th>{{ $t('admin.tasks.event') }}</th>
              <th>{{ $t('admin.tasks.change') }}</th>
              <th>{{ $t('admin.tasks.attempt') }}</th>
              <th>{{ $t('admin.tasks.message') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="event in events" :key="event.id">
              <td>{{ formatDate(event.created_at) }}</td>
              <td>{{ event.type }}</td>
              <td>
                <span v-if="event.from_status">{{ statusLabel(event.from_status) }} &rarr; </span>{{ event.to_status ? statusLabel(event.to_status) : '' }}
              </td>
              <td>{{ event.attempt }}</td>
              <td>
                {{ event.message }}
                <div v-if="contextText(event.context)" class="va-text-secondary">{{ contextText(event.context) }}</div>
              </td>
            </tr>
          </tbody>
        </table>
      </va-scroll-container>
    </va-card-content>
  </va-card>

  <va-card v-if="steps.length > 0" class="mb-4">
    <va-card-title>{{ $t('admin.tasks.steps') }}</va-card-title>
    <va-card-content>
      <table class="va-table va-table--striped">
        <thead>
          <tr>
            <th>{{ $t('admin.tasks.step') }}</th>
            <th>{{ $t('admin.tasks.status') }}</th>
            <th>{{ $t('admin.tasks.started') }}</th>
            <th>{{ $t('admin.tasks.took') }}</th>
            <th>{{ $t('admin.tasks.error') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="step in steps" :key="step.name">
            <td>{{ step.name }}</td>
            <td>{{ step.status }}</td>
            <td>{{ formatDate(step.started_at) }}</td>
            <td>{{ step.seconds === null ? '' : step.seconds + 's' }}</td>
            <td>{{ step.error }}</td>
          </tr>
        </tbody>
      </table>
    </va-card-content>
  </va-card>

  <va-card class="mb-4">
    <va-card-title>{{ $t('admin.tasks.dependencies') }}</va-card-title>
    <va-card-content>
      <h4 class="mb-2">{{ $t('admin.tasks.waitingFor') }}</h4>
      <p v-if="waiting_for.length === 0" class="mb-4">{{ $t('admin.tasks.none') }}</p>
      <ul v-else class="mb-4">
        <li v-for="other in waiting_for" :key="'for-' + other.id + other.gate">
          <Link :href="'/admin/server/tasks/' + other.id">#{{ other.id }} {{ other.description }}</Link>
          <va-badge class="ml-2" :color="statusColor(other.status)" :text="statusLabel(other.status)" />
          <span class="va-text-secondary ml-2">{{ $t(other.gate === 'start' ? 'admin.tasks.gateStart' : 'admin.tasks.gateFinish') }}</span>
        </li>
      </ul>
      <h4 class="mb-2">{{ $t('admin.tasks.waitedOnBy') }}</h4>
      <p v-if="waited_on_by.length === 0">{{ $t('admin.tasks.none') }}</p>
      <ul v-else>
        <li v-for="other in waited_on_by" :key="'by-' + other.id + other.gate">
          <Link :href="'/admin/server/tasks/' + other.id">#{{ other.id }} {{ other.description }}</Link>
          <va-badge class="ml-2" :color="statusColor(other.status)" :text="statusLabel(other.status)" />
        </li>
      </ul>
    </va-card-content>
  </va-card>

  <va-card class="mb-4">
    <va-card-title>{{ $t('admin.tasks.values') }}</va-card-title>
    <va-card-content>
      <p v-if="Object.keys(values).length === 0">{{ $t('admin.tasks.none') }}</p>
      <pre v-else class="task-values">{{ JSON.stringify(values, null, 2) }}</pre>
    </va-card-content>
  </va-card>
</template>

<script lang="ts">
export default {
  layout: (h, page) => h(AppLayout, [page]),
  props: {
    task: Object,
    events: Array,
    steps: Array,
    waiting_for: Array,
    waited_on_by: Array,
    values: Object
  },
  data () {
    return {
      toast: useToast(),
      dates: [
        { key: 'created_at', label: 'created' },
        { key: 'updated_at', label: 'updated' },
        { key: 'queued_at', label: 'queuedAt' },
        { key: 'claimed_at', label: 'claimedAt' },
        { key: 'next_attempt_at', label: 'nextAttempt' },
        { key: 'deadline_at', label: 'deadline' }
      ],
      statuses: {
        pending: this.$t('admin.tasks.pending'),
        ready: this.$t('admin.tasks.ready'),
        queued: this.$t('admin.tasks.queued'),
        in_progress: this.$t('admin.tasks.inProgress'),
        blocked: this.$t('admin.tasks.blocked'),
        complete: this.$t('admin.tasks.completed'),
        failed: this.$t('admin.tasks.failed'),
        cancelled: this.$t('admin.tasks.cancelled')
      }
    }
  },
  methods: {
    statusLabel (status) {
      return this.statuses[status] || status
    },
    statusColor (status) {
      return {
        complete: 'success',
        failed: 'danger',
        blocked: 'danger',
        in_progress: 'info',
        ready: 'info',
        pending: 'warning',
        queued: 'warning'
      }[status] || 'secondary'
    },
    formatDate (value) {
      return value ? new Date(value).toLocaleString() : ''
    },
    contextText (context) {
      if (!context || Object.keys(context).length === 0) {
        return ''
      }

      return Object.entries(context).map(([key, value]) => key + ': ' + value).join(', ')
    },
    operate (method, url, then) {
      axios({ method, url })
        .then(then)
        .catch((error) => {
          this.toast.init({
            message: error.response?.data?.message || error.message,
            color: 'danger',
            duration: 10000
          })
        })
    },
    retry () {
      this.operate('post', '/admin/server/tasks/' + this.task.id + '/retry', () => router.reload())
    },
    cancel () {
      if (!window.confirm(this.$t('admin.tasks.confirmCancel'))) {
        return
      }
      this.operate('post', '/admin/server/tasks/' + this.task.id + '/cancel', () => router.reload())
    },
    destroy () {
      if (!window.confirm(this.$t('admin.tasks.confirmDelete'))) {
        return
      }
      this.operate('delete', '/admin/server/tasks/' + this.task.id, () => router.visit('/admin/server/tasks'))
    }
  }
}
</script>

<style lang="scss">
.task-values {
  white-space: pre-wrap;
  word-break: break-word;
  max-height: 24rem;
  overflow: auto;
}
</style>
