<script setup>
import AppLayout from '@/layouts/AppLayout.vue'
import { Link } from '@inertiajs/vue3'
import TicketBadge from '@/components/tickets/TicketBadge.vue'
import TicketHeader from '@/components/tickets/TicketHeader.vue'
import TicketPanel from '@/components/tickets/TicketPanel.vue'
import TicketEditor from '@/components/tickets/TicketEditor.vue'
import AttachmentPicker from '@/components/tickets/AttachmentPicker.vue'
import TicketThread from '@/components/tickets/TicketThread.vue'
import TicketTimeline from '@/components/tickets/TicketTimeline.vue'
</script>
<template>
  <Head>
    <title>{{ ticket.reference }} {{ ticket.subject }} - Control Panel</title>
  </Head>
  <TicketHeader :title="ticket.subject" :eyebrow="ticket.reference">
    <template #meta>
      <TicketBadge :value="ticket.status.value" :label="ticket.status.label" />
      <TicketBadge :value="ticket.priority.value" :label="ticket.priority.label" />
      <va-chip v-for="tag in ticket.tags" :key="tag" size="small" outline>{{ tag }}</va-chip>
      <span class="t-muted">
        <template v-if="context.organization">{{ context.organization.name }} · </template>{{ $t('tickets.supportAssignee') }}: {{ ticket.support_assignee?.name || $t('tickets.unassigned') }}
      </span>
    </template>
    <template v-if="ticket.support_assignee?.id !== me && !ticket.merged_into" #actions>
      <va-button preset="secondary" border-color="primary" @click="assignToMe">{{ $t('tickets.admin.assignToMe') }}</va-button>
    </template>
  </TicketHeader>

  <div class="t-page t-page--sidebar">
    <div class="t-col">
      <va-alert v-if="ticket.merged_into" color="info">
        {{ $t('tickets.merge.mergedInto') }}
        <Link :href="ticket.merged_into.url">{{ ticket.merged_into.reference }}</Link>
      </va-alert>
      <va-alert v-if="merged_from.length" color="info">
        {{ $t('tickets.merge.mergedFrom') }}
        <Link v-for="item in merged_from" :key="item.reference" :href="item.url" class="mr-2">{{ item.reference }}</Link>
      </va-alert>

      <TicketThread :messages="messages" :messages-url="`${ticket.url}/messages`" staff-view />

      <va-card>
        <va-card-title>
          <div class="t-switch">
            <button type="button" class="t-switch__option" :class="{ 't-switch__option--active': !internal }" @click="internal = false">{{ $t('tickets.admin.publicReply') }}</button>
            <button type="button" class="t-switch__option t-switch__option--note" :class="{ 't-switch__option--active': internal }" @click="internal = true">{{ $t('tickets.admin.internalNote') }}</button>
          </div>
        </va-card-title>
        <va-card-content>
          <va-alert v-if="internal" color="warning" class="mb-3">{{ $t('tickets.admin.internalWarning') }}</va-alert>
          <form @submit.prevent="sendReply">
            <va-select
              v-if="canned.length"
              v-model="cannedChoice"
              class="mb-3"
              :label="$t('tickets.canned.insert')"
              :options="canned"
              value-by="id"
              text-by="title"
              clearable
              @update:model-value="insertCanned"
            />
            <TicketEditor ref="editor" v-model="replyForm.body" :draft-key="`ticket-draft:admin:${ticket.number}`" :error="replyForm.errors.body" @uploaded="imageUploaded" />
            <AttachmentPicker v-model="attachments" class="mt-3" :max-files="attachment_limits.files" :max-file-mb="attachment_limits.file_mb" />
            <div v-if="replyForm.errors.attachment_ids" class="va-message-list__message text-danger mt-1">{{ replyForm.errors.attachment_ids }}</div>
            <div class="t-actions t-actions--stack mt-3">
              <va-button type="submit" id="sendReply" :color="internal ? 'warning' : 'primary'" :disabled="replyForm.processing">
                {{ internal ? $t('tickets.admin.addNote') : $t('tickets.sendReply') }}
              </va-button>
            </div>
          </form>
        </va-card-content>
      </va-card>
    </div>

    <div class="t-col">
      <TicketPanel :title="$t('tickets.details')">
        <va-alert v-if="form.errors.lock_version" color="warning" class="mb-3">{{ form.errors.lock_version }}</va-alert>
        <form class="t-stack" @submit.prevent="save">
          <va-select v-model="form.status" :label="$t('tickets.statusColumn')" :options="statuses" value-by="value" text-by="text" :error="!!form.errors.status" :error-messages="form.errors.status" />
          <va-select v-model="form.priority" :label="$t('tickets.priorityLabel')" :options="priorities" value-by="value" text-by="text" />
          <va-select v-model="form.category_id" :label="$t('tickets.category')" :options="categories" value-by="value" text-by="text" clearable />
          <va-select
            v-model="form.support_assignee_id"
            id="supportAssignee"
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
            :label="$t('tickets.admin.tags')"
            :options="form.tags"
            multiple
            allow-create
            searchable
          />
          <div class="t-actions t-actions--stack">
            <va-button type="submit" id="saveTicket" :disabled="form.processing">{{ $t('common.save') }}</va-button>
          </div>
        </form>

        <div class="t-stack mt-4">
          <va-select
            v-model="watchers"
            id="watchers"
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
            :label="$t('tickets.mute.label')"
            @update:model-value="saveMuted"
          />
        </div>
      </TicketPanel>

      <TicketPanel :title="$t('tickets.admin.customer')">
        <div class="t-stack">
          <div v-if="context.organization">
            <Link :href="context.organization.url">{{ context.organization.name }}</Link>
            <div class="t-muted">{{ context.organization.plan || '-' }} · {{ context.organization.status }}</div>
          </div>
          <div v-if="context.requester">
            {{ context.requester.name }}<br>
            <a :href="`mailto:${context.requester.email}`" class="t-break">{{ context.requester.email }}</a>
          </div>
          <div class="t-muted">
            {{ $t('tickets.type') }}: {{ ticket.type.label }} · {{ $t('tickets.impact') }}: {{ ticket.impact.label }}
          </div>

          <div v-if="context.apps.length">
            <div class="t-subtitle">{{ $t('tickets.admin.apps') }}</div>
            <ul class="t-list-plain">
              <li v-for="app in context.apps" :key="app.id">
                {{ app.label }} <span class="t-muted">({{ app.status }})</span>
                <strong v-if="ticket.app === app.label"> ← {{ $t('tickets.admin.relatedToTicket') }}</strong>
              </li>
            </ul>
          </div>

          <div v-if="context.tasks.length">
            <div class="t-subtitle">{{ $t('tickets.admin.recentTasks') }}</div>
            <ul class="t-list-plain">
              <li v-for="task in context.tasks" :key="task.id">
                {{ task.name }} <span class="t-muted">({{ task.status }})</span>
                <div v-if="task.error" class="t-muted t-break">{{ task.error }}</div>
              </li>
            </ul>
          </div>
        </div>
      </TicketPanel>

      <TicketPanel v-if="ticket.sla || ticket.csat" id="service" :title="$t('tickets.sla.title')">
        <dl v-if="ticket.sla" class="t-details">
          <template v-for="clock in ['first_response', 'resolution']" :key="clock">
            <dt>{{ $t(`tickets.sla.clocks.${clock}`) }}</dt>
            <dd>
              <va-chip size="small" :color="slaColors[ticket.sla[clock].state]">{{ $t(`tickets.sla.states.${ticket.sla[clock].state}`) }}</va-chip>
              <div v-if="ticket.sla[clock].due_at" class="t-muted">{{ $t('tickets.sla.due') }} {{ formatDate(ticket.sla[clock].due_at) }}</div>
            </dd>
          </template>
        </dl>
        <div v-if="ticket.csat" id="csat" :class="{ 'mt-3': ticket.sla }">
          <div class="t-subtitle">{{ $t('tickets.rating.title') }}</div>
          <div>{{ '★'.repeat(ticket.csat.score) }}{{ '☆'.repeat(5 - ticket.csat.score) }}</div>
          <div v-if="ticket.csat.comment" class="t-muted">{{ ticket.csat.comment }}</div>
        </div>
      </TicketPanel>

      <TicketPanel id="time" :title="$t('tickets.time.title')">
        <template #aside>
          <span v-if="time_entries.length" class="t-muted t-panel__aside">{{ time_total.total }}</span>
        </template>
        <p v-if="time_entries.length" class="t-muted mb-2">{{ $t('tickets.time.total', { total: time_total.total, billable: time_total.billable }) }}</p>
        <ul v-if="time_entries.length" class="t-list-plain mb-3">
          <li v-for="entry in time_entries" :key="entry.id" class="ticket-time-entry">
            <span>
              <strong>{{ entry.duration }}</strong>
              <span class="t-muted"> · {{ entry.worked_at }} · {{ entry.by }}<template v-if="!entry.billable"> · {{ $t('tickets.time.nonBillable') }}</template></span>
              <span v-if="entry.note" class="d-block">{{ entry.note }}</span>
            </span>
            <va-button size="small" preset="plain" color="danger" @click="deleteTime(entry)">{{ $t('common.remove') }}</va-button>
          </li>
        </ul>
        <form class="t-stack" @submit.prevent="addTime">
          <va-input
            v-model="timeForm.duration"
            id="duration"
            :label="$t('tickets.time.duration')"
            placeholder="1h 30m"
            :error="!!timeForm.errors.duration"
            :error-messages="timeForm.errors.duration"
          />
          <va-input v-model="timeForm.note" :label="$t('tickets.time.note')" maxlength="500" />
          <va-input v-model="timeForm.worked_at" type="date" :label="$t('tickets.time.date')" :max="today" />
          <va-switch v-model="timeForm.billable" :label="$t('tickets.time.billable')" />
          <div class="t-actions t-actions--stack"><va-button type="submit" :disabled="timeForm.processing">{{ $t('tickets.time.add') }}</va-button></div>
        </form>
      </TicketPanel>

      <TicketPanel v-if="links.length || issue_targets.length || deliveries.length" id="trackers" :title="$t('tickets.integrations.linkedIssues')">
        <div class="t-stack">
          <ul v-if="links.length" class="t-list-plain">
            <li v-for="link in links" :key="link.id">
              <a v-if="link.url" :href="link.url" target="_blank" rel="noopener noreferrer">{{ link.integration }} {{ link.key }}</a>
              <span v-else>{{ link.integration }} {{ link.key }}</span>
              <span class="t-muted"> ({{ link.state }})</span>
            </li>
          </ul>

          <div v-if="issue_targets.length" class="t-stack">
            <va-select v-model="issueTarget" :label="$t('tickets.integrations.openIssueIn')" :options="issue_targets" value-by="id" text-by="name" />
            <div class="t-actions t-actions--stack"><va-button preset="secondary" border-color="primary" :disabled="!issueTarget" @click="openIssue">{{ $t('tickets.integrations.openIssue') }}</va-button></div>
          </div>

          <div v-if="deliveries.length">
            <div class="t-subtitle">{{ $t('tickets.integrations.recent') }}</div>
            <ul class="t-list-plain">
              <li v-for="delivery in deliveries" :key="delivery.id" class="t-delivery">
                <va-chip size="small" :color="deliveryColors[delivery.status]">{{ $t(`tickets.integrations.statuses.${delivery.status}`) }}</va-chip>
                <span>{{ delivery.integration }} · {{ delivery.event }}</span>
                <div v-if="delivery.error" class="delivery-error t-break">{{ delivery.error }}</div>
              </li>
            </ul>
          </div>
        </div>
      </TicketPanel>

      <TicketPanel v-if="!ticket.merged_into" id="merge" :title="$t('tickets.merge.title')">
        <p class="t-muted mb-3">{{ $t('tickets.merge.help') }}</p>
        <form class="t-stack" @submit.prevent="showMerge = true">
          <va-input v-model="mergeForm.target_number" type="number" min="1" :label="$t('tickets.merge.target')" :error="!!(mergeForm.errors.target_number || mergeForm.errors.target)" :error-messages="mergeForm.errors.target_number || mergeForm.errors.target" />
          <div class="t-actions t-actions--stack"><va-button type="submit" preset="secondary" border-color="primary" :disabled="!mergeForm.target_number">{{ $t('tickets.merge.button') }}</va-button></div>
        </form>
      </TicketPanel>

      <TicketPanel :title="$t('tickets.activity')">
        <TicketTimeline :events="timeline" />
      </TicketPanel>

      <TicketPanel id="danger" :title="$t('tickets.delete.title')">
        <p class="t-muted mb-3">{{ $t('tickets.delete.help') }}</p>
        <div class="t-actions t-actions--stack">
          <va-button color="danger" @click="showDelete = true">{{ $t('tickets.delete.button') }}</va-button>
          <a v-if="ticket.requester" :href="`/admin/support/people/${ticket.requester.id}/export`">{{ $t('tickets.delete.exportRequester') }}</a>
        </div>
      </TicketPanel>
    </div>
  </div>

  <va-modal v-model="showMerge" hide-default-actions :title="$t('tickets.merge.confirmTitle')" :message="$t('tickets.merge.confirmMessage', { source: ticket.reference, target: mergeForm.target_number })">
    <template #footer="{ cancel }">
      <va-button color="backgroundSecondary" @click="cancel">{{ $t('common.cancel') }}</va-button>
      <va-button color="warning" @click="merge">{{ $t('tickets.merge.button') }}</va-button>
    </template>
  </va-modal>

  <va-modal v-model="showDelete" hide-default-actions :title="$t('tickets.delete.confirmTitle')" :message="$t('tickets.delete.confirmMessage', { reference: ticket.reference })">
    <template #footer="{ cancel }">
      <va-button color="backgroundSecondary" @click="cancel">{{ $t('common.cancel') }}</va-button>
      <va-button color="danger" @click="destroyTicket">{{ $t('tickets.delete.button') }}</va-button>
    </template>
  </va-modal>
</template>
<script>
import { router, useForm } from '@inertiajs/vue3'
import { useToast } from 'vuestic-ui'
import axios from 'axios'
import { createPoller } from '@/composables/ticketPoller'
import { formatDate } from '@/composables/ticketFormat'

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
    canned: Array,
    merged_from: Array,
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
      cannedChoice: null,
      mergeForm: useForm({ target_number: '' }),
      showMerge: false,
      showDelete: false,
      slaColors: { ok: 'success', warning: 'warning', breached: 'danger', paused: 'secondary', met: 'success', missed: 'danger', none: 'secondary' },
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
        only: ['ticket', 'messages', 'timeline', 'context', 'version', 'last_message_id', 'watcher_ids', 'watcher_options', 'muted', 'time_entries', 'time_total', 'links', 'issue_targets', 'deliveries', 'merged_from'],
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
          if (data.new_messages > 0) {
            this.toast.init({ message: this.$t('tickets.newReplies', { count: data.new_messages }, data.new_messages), color: 'info' })
          }
        },
      })
    },
    // Drop a canned response into the reply box, with this ticket's details filled in
    async insertCanned (id) {
      if (!id) return
      try {
        const { data } = await axios.post(`${this.ticket.url}/canned/${id}`)
        this.replyForm.body = (this.replyForm.body || '') + data.html
      } finally {
        this.cannedChoice = null
      }
    },
    merge () {
      this.mergeForm.post(`${this.ticket.url}/merge`, { onFinish: () => { this.showMerge = false } })
    },
    destroyTicket () {
      router.delete(this.ticket.url)
    },
    formatDate,
    assignToMe () {
      router.post(this.ticket.url, { support_assignee_id: this.me, lock_version: this.ticket.lock_version }, { preserveScroll: true })
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
  .ticket-time-entry {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 0.5rem;
    padding: 0.4rem 0;
    border-bottom: 1px solid var(--va-background-border);
  }

  .delivery-error {
    color: var(--va-danger);
    font-size: 0.875rem;
  }

  .t-delivery {
    padding: 0.25rem 0;
  }
</style>
