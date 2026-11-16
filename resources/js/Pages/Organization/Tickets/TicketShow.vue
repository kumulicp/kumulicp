<script setup>
import AppLayout from '@/layouts/AppLayout.vue'
import { Link } from '@inertiajs/vue3'
import TicketBadge from '@/components/tickets/TicketBadge.vue'
import TicketHeader from '@/components/tickets/TicketHeader.vue'
import TicketPanel from '@/components/tickets/TicketPanel.vue'
import StarRating from '@/components/tickets/StarRating.vue'
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
      <span class="t-muted">{{ ticket.type.label }}</span>
    </template>
    <template #actions>
      <va-button v-if="can.close" preset="secondary" border-color="danger" color="danger" @click="close">{{ $t('tickets.closeTicket') }}</va-button>
      <va-button v-if="can.reopen" @click="reopen">{{ $t('tickets.reopenTicket') }}</va-button>
    </template>
  </TicketHeader>

  <div class="t-page t-page--sidebar">
    <div class="t-col">
      <va-alert v-if="ticket.merged_into" color="info" id="mergedNotice">
        {{ $t('tickets.merge.mergedInto') }}
        <Link :href="ticket.merged_into.url">{{ ticket.merged_into.reference }}</Link>
      </va-alert>

      <TicketThread :messages="messages" :messages-url="`${ticket.url}/messages`" :edit-window-minutes="edit_window_minutes" />

      <va-card v-if="can.rate" id="ratingCard">
        <va-card-title>{{ $t('tickets.rating.title') }}</va-card-title>
        <va-card-content>
          <p class="mb-3">{{ ticket.rating ? $t('tickets.rating.thanks') : $t('tickets.rating.prompt') }}</p>
          <form class="t-stack" @submit.prevent="sendRating">
            <div>
              <StarRating v-model="ratingForm.score" />
              <div v-if="ratingForm.errors.score" class="va-message-list__message text-danger">{{ ratingForm.errors.score }}</div>
            </div>
            <va-textarea
              v-model="ratingForm.comment"
              :label="$t('tickets.rating.comment')"
              :max-length="1000"
              :min-rows="2"
              autosize
              :error="!!ratingForm.errors.comment"
              :error-messages="ratingForm.errors.comment"
            />
            <div class="t-actions t-actions--stack">
              <va-button type="submit" id="sendRating" :disabled="ratingForm.processing || !ratingForm.score">{{ $t('tickets.rating.send') }}</va-button>
            </div>
          </form>
        </va-card-content>
      </va-card>

      <va-card v-if="can.reply">
        <va-card-title>{{ $t('tickets.reply') }}</va-card-title>
        <va-card-content>
          <form @submit.prevent="sendReply">
            <TicketEditor ref="editor" v-model="replyForm.body" :draft-key="`ticket-draft:${ticket.number}`" :error="replyForm.errors.body" @uploaded="imageUploaded" />
            <AttachmentPicker v-model="attachments" class="mt-3" :max-files="attachment_limits.files" :max-file-mb="attachment_limits.file_mb" />
            <div v-if="replyForm.errors.attachment_ids" class="va-message-list__message text-danger mt-1">{{ replyForm.errors.attachment_ids }}</div>
            <div class="t-actions t-actions--stack mt-3">
              <va-button type="submit" id="sendReply" :disabled="replyForm.processing">{{ $t('tickets.sendReply') }}</va-button>
            </div>
          </form>
        </va-card-content>
      </va-card>
      <va-alert v-else-if="!ticket.merged_into" color="info">{{ $t('tickets.closedNotice') }}</va-alert>
    </div>

    <div class="t-col">
      <TicketPanel :title="$t('tickets.details')" open>
        <dl class="t-details">
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

        <div class="t-stack mt-4">
          <va-select
            v-model="customerAssignee"
            id="customerAssignee"
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
            :label="$t('tickets.mute.label')"
            @update:model-value="saveMuted"
          />

          <div v-if="can.edit_details" class="t-actions t-actions--stack">
            <va-button preset="secondary" border-color="primary" @click="showEdit = true">{{ $t('tickets.editDetails') }}</va-button>
          </div>
        </div>
      </TicketPanel>

      <TicketPanel :title="$t('tickets.activity')">
        <TicketTimeline :events="timeline" />
      </TicketPanel>
    </div>
  </div>

  <va-modal v-model="showEdit" hide-default-actions no-padding size="small">
    <template #content>
      <va-card-title>{{ $t('tickets.editDetails') }}</va-card-title>
      <form @submit.prevent="saveDetails">
        <va-card-content class="t-stack">
          <va-input v-model="detailsForm.subject" :label="$t('tickets.subject')" maxlength="150" :error="!!detailsForm.errors.subject" :error-messages="detailsForm.errors.subject" />
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
      ratingForm: useForm({ score: this.ticket.rating?.score ?? 0, comment: this.ticket.rating?.comment ?? '' }),
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
    sendRating () {
      this.ratingForm.post(`${this.ticket.url}/rating`, { preserveScroll: true })
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
