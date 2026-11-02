<script setup>
import AppLayout from '@/layouts/AppLayout.vue'
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
            <TicketBadge :value="ticket.status.value" :label="ticket.status.label" />
          </div>
        </va-card-title>
        <va-card-content>
          <TicketThread :messages="messages" :messages-url="`${ticket.url}/messages`" :edit-window-minutes="edit_window_minutes" />
        </va-card-content>
      </va-card>

      <va-card v-if="can.reply" class="mb-4">
        <va-card-title>{{ $t('tickets.reply') }}</va-card-title>
        <va-card-content>
          <form @submit.prevent="sendReply">
            <TicketEditor ref="editor" v-model="replyForm.body" :draft-key="`ticket-draft:${ticket.number}`" :error="replyForm.errors.body" @uploaded="imageUploaded" />
            <AttachmentPicker v-model="attachments" class="mt-3" :max-files="attachment_limits.files" :max-file-mb="attachment_limits.file_mb" />
            <div v-if="replyForm.errors.attachment_ids" class="va-message-list__message text-danger mt-1">{{ replyForm.errors.attachment_ids }}</div>
            <va-button type="submit" id="sendReply" class="mt-3" :disabled="replyForm.processing">{{ $t('tickets.sendReply') }}</va-button>
          </form>
        </va-card-content>
      </va-card>
      <va-alert v-else color="info" class="mb-4">{{ $t('tickets.closedNotice') }}</va-alert>
    </div>

    <div class="flex flex-col xs12 lg4">
      <va-card class="mb-4">
        <va-card-title>{{ $t('tickets.details') }}</va-card-title>
        <va-card-content>
          <dl class="ticket-details">
            <dt>{{ $t('tickets.type') }}</dt>
            <dd>{{ ticket.type.label }}</dd>
            <dt>{{ $t('tickets.impact') }}</dt>
            <dd>{{ ticket.impact.label }}</dd>
            <template v-if="ticket.category">
              <dt>{{ $t('tickets.category') }}</dt>
              <dd>{{ ticket.category }}</dd>
            </template>
            <template v-if="ticket.app">
              <dt>{{ $t('tickets.relatedApp') }}</dt>
              <dd>{{ ticket.app }}</dd>
            </template>
            <dt>{{ $t('tickets.requester') }}</dt>
            <dd>{{ ticket.requester?.name || $t('tickets.deletedUser') }}</dd>
            <dt>{{ $t('tickets.supportAssignee') }}</dt>
            <dd id="supportAssignee">{{ ticket.support_assignee?.name || $t('tickets.unassigned') }}</dd>
          </dl>

          <va-select
            v-model="customerAssignee"
            id="customerAssignee"
            class="mt-3"
            :label="$t('tickets.customerAssignee')"
            :options="members"
            value-by="id"
            text-by="name"
            clearable
            @update:model-value="changeCustomerAssignee"
          />

          <va-select
            v-model="watchers"
            id="watchers"
            class="mt-3"
            :label="$t('tickets.watchers.label')"
            :messages="$t('tickets.watchers.help')"
            :options="members"
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

          <div class="mt-3">
            <va-button v-if="can.edit_details" size="small" color="secondary" @click="showEdit = true">{{ $t('tickets.editDetails') }}</va-button>
            <va-button v-if="can.close" size="small" color="danger" class="ml-2" @click="close">{{ $t('tickets.closeTicket') }}</va-button>
            <va-button v-if="can.reopen" size="small" class="ml-2" @click="reopen">{{ $t('tickets.reopenTicket') }}</va-button>
          </div>
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

  <va-modal v-model="showEdit" hide-default-actions no-padding>
    <template #content>
      <va-card-title>{{ $t('tickets.editDetails') }}</va-card-title>
      <form @submit.prevent="saveDetails">
        <va-card-content>
          <va-input v-model="detailsForm.subject" class="mb-3" :label="$t('tickets.subject')" maxlength="150" :error="!!detailsForm.errors.subject" :error-messages="detailsForm.errors.subject" />
          <va-select v-model="detailsForm.impact" :label="$t('tickets.impact')" :options="impactOptions" value-by="value" text-by="text" />
        </va-card-content>
        <va-card-actions align="right">
          <va-button color="textInverted" @click="showEdit = false">{{ $t('common.cancel') }}</va-button>
          <va-button type="submit" :disabled="detailsForm.processing">{{ $t('common.save') }}</va-button>
        </va-card-actions>
      </form>
    </template>
  </va-modal>
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
    can: Object,
    members: Array,
    me: Number,
    edit_window_minutes: Number,
    attachment_limits: Object,
    version: String,
    last_message_id: Number,
    watcher_ids: Array,
    muted: Boolean,
  },
  data () {
    return {
      replyForm: useForm({ body: '' }),
      attachments: [],
      detailsForm: useForm({ subject: this.ticket.subject, impact: this.ticket.impact.value }),
      customerAssignee: this.ticket.customer_assignee?.id ?? null,
      watchers: [...this.watcher_ids],
      mutedLocal: this.muted,
      toast: useToast(),
      showEdit: false,
    }
  },
  computed: {
    impactOptions () {
      return ['minor', 'degraded', 'outage'].map(value => ({ value, text: this.$t(`tickets.impactShort.${value}`) }))
    },
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
    // Another visit to the same page (after a reply, say) brings fresh props
    'ticket.customer_assignee' (value) {
      this.customerAssignee = value?.id ?? null
    },
  },
  mounted () {
    // Checks every few seconds whether this ticket changed; costs a 304 when it did not
    this.poller = createPoller({
      url: `${this.ticket.url}/poll`,
      version: this.version,
      params: () => ({ after: this.last_message_id }),
      // A closed ticket rarely changes
      slow: () => this.ticket.status.value === 'closed',
      onChange: (data) => this.refresh(data),
    })
    this.poller.start()
  },
  beforeUnmount () {
    this.poller?.stop()
  },
  methods: {
    // Reload only what changed. State is preserved, so a half-written reply stays put.
    refresh (data) {
      router.reload({
        only: ['ticket', 'messages', 'timeline', 'can', 'version', 'last_message_id', 'watcher_ids', 'muted'],
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
          if (data.new_messages > 0) {
            this.toast.init({ message: this.$t('tickets.newReplies', { count: data.new_messages }, data.new_messages), color: 'info' })
          }
        },
      })
    },
    saveWatchers (value) {
      router.put(`${this.ticket.url}/watchers`, { user_ids: value }, { preserveScroll: true, preserveState: true })
    },
    saveMuted (value) {
      router.put(`${this.ticket.url}/subscription`, { muted: !!value }, { preserveScroll: true, preserveState: true })
    },
    sendReply () {
      this.replyForm.transform(data => ({ ...data, attachment_ids: this.attachments.map(attachment => attachment.id) })).post(`${this.ticket.url}/messages`, {
        preserveScroll: true,
        onSuccess: () => {
          this.$refs.editor?.clearDraft()
          this.replyForm.reset()
          this.attachments = []
        },
      })
    },
    imageUploaded (attachment) {
      this.attachments.push({ ...attachment, inline: true })
    },
    changeCustomerAssignee (value) {
      router.put(this.ticket.url, { customer_assignee_id: value ?? null }, { preserveScroll: true })
    },
    saveDetails () {
      this.detailsForm.put(this.ticket.url, {
        preserveScroll: true,
        onSuccess: () => { this.showEdit = false },
      })
    },
    close () {
      router.post(`${this.ticket.url}/close`, {}, { preserveScroll: true })
    },
    reopen () {
      router.post(`${this.ticket.url}/reopen`, {}, { preserveScroll: true })
    },
  },
}
</script>
<style lang="scss">
  .ticket-details {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 0.4em 1em;
    margin: 0;

    dt { color: var(--va-secondary); }
    dd { margin: 0; }
  }
</style>
