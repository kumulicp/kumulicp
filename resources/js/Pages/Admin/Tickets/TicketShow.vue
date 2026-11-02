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
    <div class="flex xs12 lg8 pr-2">
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

    <div class="flex xs12 lg4">
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
import { useForm } from '@inertiajs/vue3'

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
  },
  data () {
    return {
      internal: false,
      attachments: [],
      replyForm: useForm({ body: '', internal: false }),
      form: this.makeForm(),
    }
  },
  watch: {
    // Fresh props arrive after every save; keep the form in step with them
    ticket: {
      handler () {
        this.form = this.makeForm()
      },
    },
  },
  methods: {
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
      this.form.post(this.ticket.url, { preserveScroll: true })
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
