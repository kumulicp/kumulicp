<script setup>
import AppLayout from '@/layouts/AppLayout.vue'
import { Link } from '@inertiajs/vue3'
import TicketEditor from '@/components/tickets/TicketEditor.vue'
import AttachmentPicker from '@/components/tickets/AttachmentPicker.vue'
</script>
<template>
  <Head>
    <title>{{ $t('tickets.newTicket') }} - Control Panel</title>
  </Head>
  <va-card class="mb-4">
    <va-card-title>{{ $t('tickets.newTicket') }}</va-card-title>
    <va-card-content>
      <p v-if="$page.props.documentation" class="va-p mb-3">
        {{ $t('navbar.helpDeskDescription') }}
        <a :href="$page.props.documentation" target="_blank" rel="noopener noreferrer">{{ $t('tickets.documentation') }}</a>
      </p>
      <form @submit.prevent="submit">
        <va-input
          v-model="form.subject"
          id="subject"
          class="mb-3"
          :label="$t('tickets.subject')"
          :messages="$t('navbar.subjectMessage')"
          :error="!!form.errors.subject"
          :error-messages="form.errors.subject"
          maxlength="150"
          required-mark
        />

        <div class="row">
          <div class="flex xs12 md6 pr-2 mb-3">
            <va-select v-model="form.type" :label="$t('tickets.type')" :options="types" value-by="value" text-by="text" :error="!!form.errors.type" :error-messages="form.errors.type" />
          </div>
          <div class="flex xs12 md6 mb-3">
            <va-select v-model="form.impact" :label="$t('tickets.impact')" :options="impacts" value-by="value" text-by="text" :error="!!form.errors.impact" :error-messages="form.errors.impact" />
          </div>
          <div v-if="categories.length" class="flex xs12 md6 pr-2 mb-3">
            <va-select v-model="form.category_id" :label="$t('tickets.category')" :options="categories" value-by="value" text-by="text" clearable />
          </div>
          <div v-if="apps.length" class="flex xs12 md6 mb-3">
            <va-select v-model="form.app_instance_id" :label="$t('tickets.relatedApp')" :options="apps" value-by="value" text-by="text" clearable />
          </div>
          <div class="flex xs12 md6 pr-2 mb-3">
            <va-select
              v-model="form.customer_assignee_id"
              id="customerAssignee"
              :label="$t('tickets.customerAssignee')"
              :messages="$t('tickets.customerAssigneeHelp')"
              :options="members"
              value-by="id"
              text-by="name"
              :error="!!form.errors.customer_assignee_id"
              :error-messages="form.errors.customer_assignee_id"
            />
          </div>
        </div>

        <div class="va-input-label mb-1">{{ $t('navbar.helpDeskBody') }}</div>
        <TicketEditor ref="editor" v-model="form.body" draft-key="ticket-draft:new" :error="form.errors.body" @uploaded="imageUploaded" />
        <div class="va-message-list__message">{{ $t('navbar.helpDeskBodyMessage') }}</div>

        <div class="va-input-label mt-3 mb-1">{{ $t('tickets.attachments.title') }}</div>
        <AttachmentPicker v-model="attachments" :max-files="attachment_limits.files" :max-file-mb="attachment_limits.file_mb" />
        <div v-if="form.errors.attachment_ids" class="va-message-list__message text-danger mt-1">{{ form.errors.attachment_ids }}</div>

        <div class="mt-4">
          <va-button type="submit" id="submit" :disabled="form.processing">{{ $t('common.submit') }}</va-button>
          <Link href="/support/tickets"><va-button color="textInverted" class="ml-2">{{ $t('common.cancel') }}</va-button></Link>
        </div>
      </form>
    </va-card-content>
  </va-card>
</template>
<script>
import { useForm } from '@inertiajs/vue3'

export default {
  layout: (h, page) => h(AppLayout, [page]),
  props: {
    types: Array,
    impacts: Array,
    categories: Array,
    apps: Array,
    members: Array,
    me: Number,
    attachment_limits: Object,
  },
  data () {
    return {
      attachments: [],
      form: useForm({
        subject: '',
        body: '',
        type: 'question',
        impact: 'minor',
        category_id: null,
        app_instance_id: null,
        customer_assignee_id: this.me,
      }),
    }
  },
  methods: {
    submit () {
      this.form.transform(data => ({ ...data, attachment_ids: this.attachments.map(attachment => attachment.id) })).post('/support/tickets', {
        onSuccess: () => this.$refs.editor?.clearDraft(),
      })
    },
    // A pasted screenshot was uploaded; it goes out with the message
    imageUploaded (attachment) {
      this.attachments.push({ ...attachment, inline: true })
    },
  },
}
</script>
