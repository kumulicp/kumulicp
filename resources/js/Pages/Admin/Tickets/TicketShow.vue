<script setup>
import AppLayout from '@/layouts/AppLayout.vue'
import { Link } from '@inertiajs/vue3'
import TicketBadge from '@/components/tickets/TicketBadge.vue'
import TicketEditor from '@/components/tickets/TicketEditor.vue'
import AttachmentPicker from '@/components/tickets/AttachmentPicker.vue'
import TicketThread from '@/components/tickets/TicketThread.vue'
import TicketTimeline from '@/components/tickets/TicketTimeline.vue'
</script>
<template>
  <Head>
    <title>{{ ticket.reference }} {{ ticket.subject }} - Control Panel</title>
  </Head>
  <div class="row">
    <div class="flex flex-col xs12 lg8">
      <va-card class="mb-4">
        <va-card-title>
          <div class="flex justify-between items-center w-full">
            <span>{{ ticket.reference }}: {{ ticket.subject }}</span>
            <span>
              <TicketBadge :value="ticket.status.value" :label="ticket.status.label" />
              <TicketBadge :value="ticket.priority.value" :label="ticket.priority.label" class="ml-1" />
            </span>
          </div>
        </va-card-title>
        <va-card-content>
          <TicketThread :messages="messages" :messages-url="`${ticket.url}/messages`" staff-view />
        </va-card-content>
      </va-card>

      <va-card class="mb-4">
        <va-card-title>
          <va-button-group>
            <va-button :preset="internal ? 'secondary' : undefined" @click="internal = false">{{ $t('tickets.admin.publicReply') }}</va-button>
            <va-button :preset="internal ? undefined : 'secondary'" color="warning" @click="internal = true">{{ $t('tickets.admin.internalNote') }}</va-button>
          </va-button-group>
        </va-card-title>
        <va-card-content>
          <va-alert v-if="internal" color="warning" class="mb-3">{{ $t('tickets.admin.internalWarning') }}</va-alert>
          <form @submit.prevent="sendReply">
            <TicketEditor ref="editor" v-model="replyForm.body" :draft-key="`ticket-draft:admin:${ticket.number}`" :error="replyForm.errors.body" @uploaded="imageUploaded" />
            <AttachmentPicker v-model="attachments" class="mt-3" :max-files="attachment_limits.files" :max-file-mb="attachment_limits.file_mb" />
            <div v-if="replyForm.errors.attachment_ids" class="va-message-list__message text-danger mt-1">{{ replyForm.errors.attachment_ids }}</div>
            <va-button type="submit" id="sendReply" class="mt-3" :disabled="replyForm.processing">
              {{ internal ? $t('tickets.admin.addNote') : $t('tickets.sendReply') }}
            </va-button>
          </form>
        </va-card-content>
      </va-card>
    </div>

    <div class="flex flex-col xs12 lg4">
      <va-card class="mb-4">
        <va-card-title>{{ $t('tickets.details') }}</va-card-title>
        <va-card-content>
          <va-alert v-if="form.errors.lock_version" color="warning" class="mb-3">{{ form.errors.lock_version }}</va-alert>
          <form @submit.prevent="save">
            <va-select v-model="form.status" class="mb-3" :label="$t('tickets.statusColumn')" :options="statuses" value-by="value" text-by="text" :error="!!form.errors.status" :error-messages="form.errors.status" />
            <va-select v-model="form.priority" class="mb-3" :label="$t('tickets.priorityLabel')" :options="priorities" value-by="value" text-by="text" />
            <va-select v-model="form.category_id" class="mb-3" :label="$t('tickets.category')" :options="categories" value-by="value" text-by="text" clearable />
            <va-select
              v-model="form.support_assignee_id"
              id="supportAssignee"
              class="mb-3"
              :label="$t('tickets.supportAssignee')"
              :options="staff"
              value-by="id"
              text-by="name"
              clearable
              :error="!!form.errors.support_assignee_id"
              :error-messages="form.errors.support_assignee_id"
            />
            <va-select
              v-model="form.customer_assignee_id"
              id="customerAssignee"
              class="mb-3"
              :label="$t('tickets.customerAssignee')"
              :options="members"
              value-by="id"
              text-by="name"
              clearable
              :error="!!form.errors.customer_assignee_id"
              :error-messages="form.errors.customer_assignee_id"
            />
            <va-select
              v-model="form.tags"
              class="mb-3"
              :label="$t('tickets.admin.tags')"
              :options="form.tags"
              multiple
              allow-create
              searchable
            />
            <va-button type="submit" id="saveTicket" :disabled="form.processing">{{ $t('common.save') }}</va-button>
          </form>

          <va-select
            v-model="watchers"
            id="watchers"
            class="mt-4"
            :label="$t('tickets.watchers.label')"
            :messages="$t('tickets.admin.watchersHelp')"
            :options="watcher_options"
            value-by="id"
            text-by="name"
            multiple
            @update:model-value="saveWatchers"
          />

          <va-switch
            v-model="mutedLocal"
            id="mute"
            class="mt-3"
            :label="$t('tickets.mute.label')"
            @update:model-value="saveMuted"
          />
        </va-card-content>
      </va-card>

      <va-card class="mb-4">
        <va-card-title>{{ $t('tickets.admin.customer') }}</va-card-title>
        <va-card-content>
          <template v-if="context.organization">
            <div><Link :href="context.organization.url">{{ context.organization.name }}</Link></div>
            <div class="ticket-context__muted">
              {{ context.organization.plan || '-' }} · {{ context.organization.status }}
            </div>
          </template>
          <div v-if="context.requester" class="mt-2">
            {{ context.requester.name }}<br>
            <a :href="`mailto:${context.requester.email}`">{{ context.requester.email }}</a>
          </div>
          <div class="mt-2 ticket-context__muted">
            {{ $t('tickets.type') }}: {{ ticket.type.label }} · {{ $t('tickets.impact') }}: {{ ticket.impact.label }}
          </div>

          <template v-if="context.apps.length">
            <div class="mt-3"><strong>{{ $t('tickets.admin.apps') }}</strong></div>
            <ul class="ticket-context__list">
              <li v-for="app in context.apps" :key="app.id">
                {{ app.label }} <span class="ticket-context__muted">({{ app.status }})</span>
                <strong v-if="ticket.app === app.label"> ← {{ $t('tickets.admin.relatedToTicket') }}</strong>
              </li>
            </ul>
          </template>

          <template v-if="context.tasks.length">
            <div class="mt-3"><strong>{{ $t('tickets.admin.recentTasks') }}</strong></div>
            <ul class="ticket-context__list">
              <li v-for="task in context.tasks" :key="task.id">
                {{ task.name }} <span class="ticket-context__muted">({{ task.status }})</span>
                <div v-if="task.error" class="ticket-context__muted">{{ task.error }}</div>
              </li>
            </ul>
          </template>
        </va-card-content>
      </va-card>

      <va-card class="mb-4" id="time">
        <va-card-title>
          <div class="flex justify-between items-center w-full">
            <span>{{ $t('tickets.time.title') }}</span>
            <span v-if="time_entries.length" class="ticket-context__muted">{{ $t('tickets.time.total', { total: time_total.total, billable: time_total.billable }) }}</span>
          </div>
        </va-card-title>
        <va-card-content>
          <ul v-if="time_entries.length" class="ticket-context__list mb-3">
            <li v-for="entry in time_entries" :key="entry.id" class="ticket-time-entry">
              <span>
                <strong>{{ entry.duration }}</strong>
                <span class="ticket-context__muted"> · {{ entry.worked_at }} · {{ entry.by }}<template v-if="!entry.billable"> · {{ $t('tickets.time.nonBillable') }}</template></span>
                <span v-if="entry.note" class="d-block">{{ entry.note }}</span>
              </span>
              <va-button size="small" preset="plain" color="danger" @click="deleteTime(entry)">{{ $t('common.remove') }}</va-button>
            </li>
          </ul>
          <form @submit.prevent="addTime">
            <va-input
              v-model="timeForm.duration"
              id="duration"
              class="mb-2"
              :label="$t('tickets.time.duration')"
              placeholder="1h 30m"
              :error="!!timeForm.errors.duration"
              :error-messages="timeForm.errors.duration"
            />
            <va-input v-model="timeForm.note" class="mb-2" :label="$t('tickets.time.note')" maxlength="500" />
            <va-input v-model="timeForm.worked_at" class="mb-2" type="date" :label="$t('tickets.time.date')" :max="today" />
            <va-switch v-model="timeForm.billable" class="mb-2" :label="$t('tickets.time.billable')" />
            <div><va-button type="submit" size="small" :disabled="timeForm.processing">{{ $t('tickets.time.add') }}</va-button></div>
          </form>
        </va-card-content>
      </va-card>

      <va-card v-if="links.length || issue_targets.length || deliveries.length" class="mb-4" id="trackers">
        <va-card-title>{{ $t('tickets.integrations.linkedIssues') }}</va-card-title>
        <va-card-content>
          <ul v-if="links.length" class="ticket-context__list mb-3">
            <li v-for="link in links" :key="link.id">
              <a v-if="link.url" :href="link.url" target="_blank" rel="noopener noreferrer">{{ link.integration }} {{ link.key }}</a>
              <span v-else>{{ link.integration }} {{ link.key }}</span>
              <span class="ticket-context__muted"> ({{ link.state }})</span>
            </li>
          </ul>

          <div v-if="issue_targets.length" class="mb-3">
            <va-select v-model="issueTarget" class="mb-2" :label="$t('tickets.integrations.openIssueIn')" :options="issue_targets" value-by="id" text-by="name" />
            <va-button size="small" :disabled="!issueTarget" @click="openIssue">{{ $t('tickets.integrations.openIssue') }}</va-button>
          </div>

          <template v-if="deliveries.length">
            <div><strong>{{ $t('tickets.integrations.recent') }}</strong></div>
            <ul class="ticket-context__list">
              <li v-for="delivery in deliveries" :key="delivery.id">
                <va-chip size="small" :color="deliveryColors[delivery.status]">{{ $t(`tickets.integrations.statuses.${delivery.status}`) }}</va-chip>
                {{ delivery.integration }} · {{ delivery.event }}
                <div v-if="delivery.error" class="delivery-error ticket-context__muted">{{ delivery.error }}</div>
              </li>
            </ul>
          </template>
        </va-card-content>
      </va-card>

      <va-card class="mb-4">
        <va-card-title>{{ $t('tickets.activity') }}</va-card-title>
        <va-card-content>
          <TicketTimeline :events="timeline" />
        </va-card-content>
      </va-card>
    </div>
  </div>
</template>
<script>
import { router, useForm } from '@inertiajs/vue3'
import { useToast } from 'vuestic-ui'
import { createPoller } from '@/composables/ticketPoller'

export default {
  layout: (h, page) => h(AppLayout, [page]),
  props: {
    ticket: Object,
    messages: Array,
    timeline: Array,
    context: Object,
    staff: Array,
    members: Array,
    statuses: Array,
    priorities: Array,
    categories: Array,
    me: Number,
    attachment_limits: Object,
    version: String,
    last_message_id: Number,
    watcher_ids: Array,
    watcher_options: Array,
    muted: Boolean,
    time_entries: Array,
    time_total: Object,
    links: Array,
    issue_targets: Array,
    deliveries: Array,
  },
  data () {
    return {
      internal: false,
      attachments: [],
      watchers: [...this.watcher_ids],
      mutedLocal: this.muted,
      toast: useToast(),
      timeForm: useForm({ duration: '', note: '', worked_at: new Date().toISOString().slice(0, 10), billable: true }),
      today: new Date().toISOString().slice(0, 10),
      issueTarget: null,
      deliveryColors: { pending: 'warning', delivered: 'success', failed: 'danger' },
      replyForm: useForm({ body: '', internal: false }),
      form: this.makeForm(),
    }
  },
  watch: {
    version (value) {
      this.poller?.setVersion(value)
    },
    watcher_ids (value) {
      this.watchers = [...value]
    },
    muted (value) {
      this.mutedLocal = value
    },
    // Fresh props arrive after a save or when someone else changes the ticket.
    // Keep what is being typed; the stale version number will flag a clash on save.
    ticket: {
      handler () {
        if (!this.form.isDirty) {
          this.form = this.makeForm()
        }
      },
    },
  },
  mounted () {
    this.poller = createPoller({
      url: `${this.ticket.url}/poll`,
      version: this.version,
      params: () => ({ after: this.last_message_id }),
      slow: () => this.ticket.status.value === 'closed',
      onChange: (data) => this.refresh(data),
    })
    this.poller.start()
  },
  beforeUnmount () {
    this.poller?.stop()
  },
  methods: {
    // Reload only what changed. State is preserved, so a half-written reply and unsaved edits stay put.
    refresh (data) {
      router.reload({
        only: ['ticket', 'messages', 'timeline', 'context', 'version', 'last_message_id', 'watcher_ids', 'watcher_options', 'muted', 'time_entries', 'time_total', 'links', 'issue_targets', 'deliveries'],
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
          if (data.new_messages > 0) {
            this.toast.init({ message: this.$t('tickets.newReplies', { count: data.new_messages }, data.new_messages), color: 'info' })
          }
        },
      })
    },
    addTime () {
      this.timeForm.post(`${this.ticket.url}/time`, {
        preserveScroll: true,
        onSuccess: () => this.timeForm.reset('duration', 'note'),
      })
    },
    deleteTime (entry) {
      router.delete(`${this.ticket.url}/time/${entry.id}`, { preserveScroll: true })
    },
    openIssue () {
      router.post(`${this.ticket.url}/issues`, { integration_id: this.issueTarget }, {
        preserveScroll: true,
        onSuccess: () => { this.issueTarget = null },
      })
    },
    saveWatchers (value) {
      router.put(`${this.ticket.url}/watchers`, { user_ids: value }, { preserveScroll: true, preserveState: true })
    },
    saveMuted (value) {
      router.put(`${this.ticket.url}/subscription`, { muted: !!value }, { preserveScroll: true, preserveState: true })
    },
    makeForm () {
      return useForm({
        lock_version: this.ticket.lock_version,
        status: this.ticket.status.value,
        priority: this.ticket.priority.value,
        category_id: this.ticket.category_id,
        support_assignee_id: this.ticket.support_assignee?.id ?? null,
        customer_assignee_id: this.ticket.customer_assignee?.id ?? null,
        tags: [...this.ticket.tags],
      })
    },
    sendReply () {
      this.replyForm.internal = this.internal
      this.replyForm.transform(data => ({ ...data, attachment_ids: this.attachments.map(attachment => attachment.id) })).post(`${this.ticket.url}/messages`, {
        preserveScroll: true,
        onSuccess: () => {
          this.$refs.editor?.clearDraft()
          this.replyForm.reset('body')
          this.attachments = []
        },
      })
    },
    imageUploaded (attachment) {
      this.attachments.push({ ...attachment, inline: true })
    },
    save () {
      this.form.post(this.ticket.url, {
        preserveScroll: true,
        // The saved ticket is the new starting point (fresh version number included)
        onSuccess: () => { this.form = this.makeForm() },
      })
    },
  },
}
</script>
<style lang="scss">
  .ticket-context__muted {
    color: var(--va-secondary);
    font-size: 0.9em;
  }

  .ticket-context__list {
    list-style: none;
    padding: 0;
    margin: 0.25em 0 0;
  }
</style>
<style lang="scss">
  .ticket-time-entry {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    padding: 0.3em 0;
    border-bottom: 1px solid var(--va-background-border);
  }

  .delivery-error {
    color: var(--va-danger);
  }
</style>
