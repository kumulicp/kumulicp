<script setup>
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import TicketEditor from './TicketEditor.vue'
import { formatDate, formatSize } from '@/composables/ticketFormat'

// The conversation. Each message is already sanitized server-side; it is the
// only thing rendered with v-html. Editing happens in place.
const props = defineProps({
  messages: { type: Array, required: true },
  // Base URL for editing, e.g. /support/tickets/1042/messages
  messagesUrl: { type: String, required: true },
  // Staff views show a badge on internal notes
  staffView: { type: Boolean, default: false },
  editWindowMinutes: { type: Number, default: 15 },
})

const editingId = ref(null)
const redactTarget = ref(null)
const form = useForm({ body: '' })

function startEdit (message) {
  editingId.value = message.id
  form.body = message.body_html
  form.clearErrors()
}

function redact () {
  router.post(`${props.messagesUrl}/${redactTarget.value.id}/redact`, {}, {
    preserveScroll: true,
    onFinish: () => { redactTarget.value = null },
  })
}

function cancelEdit () {
  editingId.value = null
  form.reset()
}

function save (message) {
  form.put(`${props.messagesUrl}/${message.id}`, {
    preserveScroll: true,
    onSuccess: () => {
      editingId.value = null
    },
  })
}
</script>
<template>
  <div class="ticket-thread t-col">
    <va-card
      v-for="message in messages"
      :key="message.id"
      :id="`message-${message.id}`"
      class="ticket-message"
      :class="{ 'ticket-message--internal': message.internal, 'ticket-message--staff': message.kind === 'staff' }"
    >
      <va-card-content>
        <div class="ticket-message__header">
          <div class="ticket-message__who">
            <strong>{{ message.author }}</strong>
            <va-chip v-if="message.kind === 'staff'" size="small" color="primary">{{ $t('tickets.support') }}</va-chip>
            <va-chip v-if="message.internal" size="small" color="warning">{{ $t('tickets.internalNote') }}</va-chip>
          </div>
          <div v-if="(staffView && !message.redacted && editingId !== message.id) || (message.can_edit && editingId !== message.id)" class="ticket-message__actions">
            <va-button
              v-if="staffView && !message.redacted && editingId !== message.id"
              preset="plain"
              size="small"
              color="danger"
              @click="redactTarget = message"
            >{{ $t('tickets.redact.button') }}</va-button>
            <va-button
              v-if="message.can_edit && editingId !== message.id"
              preset="plain"
              size="small"
              @click="startEdit(message)"
            >{{ $t('common.edit') }}</va-button>
          </div>
          <div class="ticket-message__when t-muted">
            {{ formatDate(message.created_at) }}
            <span v-if="message.edited_at" :title="formatDate(message.edited_at)">· {{ $t('tickets.edited') }}</span>
          </div>
        </div>

        <template v-if="editingId === message.id">
          <TicketEditor v-model="form.body" :error="form.errors.body" :images="false" />
          <div class="mt-2">
            <va-button size="small" :disabled="form.processing" @click="save(message)">{{ $t('common.save') }}</va-button>
            <va-button size="small" color="textInverted" class="ml-2" @click="cancelEdit">{{ $t('common.cancel') }}</va-button>
          </div>
        </template>
        <div v-else-if="message.redacted" class="ticket-message__redacted">{{ $t('tickets.redacted') }}</div>
        <div v-else class="ticket-message__body" v-html="message.body_html"></div>

        <ul v-if="message.attachments?.length" class="ticket-message__attachments">
          <li v-for="attachment in message.attachments" :key="attachment.id">
            <a :href="`${attachment.url}?download=1`" class="ticket-message__attachment">
              <img v-if="attachment.is_image" :src="attachment.url" :alt="attachment.name" class="ticket-message__thumb">
              <va-icon v-else name="description" size="small" />
              <span>{{ attachment.name }}</span>
              <span class="t-muted">{{ formatSize(attachment.size) }}</span>
            </a>
          </li>
        </ul>
      </va-card-content>
    </va-card>

    <va-modal :model-value="redactTarget !== null" hide-default-actions :title="$t('tickets.redact.title')" :message="$t('tickets.redact.message')" @update:model-value="redactTarget = null">
      <template #footer>
        <va-button color="backgroundSecondary" @click="redactTarget = null">{{ $t('common.cancel') }}</va-button>
        <va-button color="danger" @click="redact">{{ $t('tickets.redact.confirm') }}</va-button>
      </template>
    </va-modal>
  </div>
</template>
<style lang="scss">
  .ticket-message--staff {
    border-left: 4px solid var(--va-primary);
  }

  .ticket-message--internal {
    border-left: 4px solid var(--va-warning);
    background: color-mix(in srgb, var(--va-warning) 8%, var(--va-background-element));
  }

  .ticket-message__header {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 0.15rem 0.75rem;
    margin-bottom: 0.6rem;
  }

  .ticket-message__who {
    display: flex;
    flex: 1 1 auto;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.25rem 0.5rem;
    min-width: 0;
  }

  .ticket-message__actions {
    display: flex;
    flex: 0 0 auto;
    gap: 0.25rem;
  }

  .ticket-message__when {
    flex: 1 0 100%;
    order: 3;
    font-size: 0.8rem;
  }

  .ticket-message__attachments {
    list-style: none;
    padding: 0;
    margin: 0.9rem 0 0;
    display: flex;
    flex-wrap: wrap;
    gap: 0.5em;
  }

  .ticket-message__attachment {
    display: inline-flex;
    align-items: center;
    max-width: 100%;
    gap: 0.5em;
    padding: 0.35em 0.6em;
    border: 1px solid var(--va-background-border);
    border-radius: 4px;
    text-decoration: none;
  }

  .ticket-message__attachment span {
    min-width: 0;
    overflow-wrap: anywhere;
  }

  .ticket-message__thumb {
    max-height: 3em;
    max-width: 5em;
    object-fit: cover;
    border-radius: 2px;
  }

  .ticket-message__body img {
    max-width: 100%;
    height: auto;
  }

  .ticket-message__redacted {
    font-style: italic;
    color: var(--va-text-secondary);
  }

  .ticket-message__body {
    overflow-x: auto;
    overflow-wrap: anywhere;

    // Restore normal list/quote/code styling that the app reset removes
    ol, ul { all: revert; }
    blockquote {
      margin: 0.5em 0;
      padding-left: 1em;
      border-left: 3px solid var(--va-background-border);
      color: var(--va-text-secondary);
    }
    pre {
      padding: 0.75em;
      overflow-x: auto;
      background: var(--va-background-secondary);
      border-radius: 4px;
    }
    a { text-decoration: underline; }
  }
</style>
