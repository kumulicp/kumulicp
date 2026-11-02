<script setup>
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import TicketEditor from './TicketEditor.vue'

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
const form = useForm({ body: '' })

function formatDate (value) {
  return value ? new Date(value).toLocaleString() : ''
}

function startEdit (message) {
  editingId.value = message.id
  form.body = message.body_html
  form.clearErrors()
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
  <div class="ticket-thread">
    <va-card
      v-for="message in messages"
      :key="message.id"
      :id="`message-${message.id}`"
      class="ticket-message mb-3"
      :class="{ 'ticket-message--internal': message.internal, 'ticket-message--staff': message.kind === 'staff' }"
    >
      <va-card-content>
        <div class="ticket-message__header mb-2">
          <strong>{{ message.author }}</strong>
          <va-chip v-if="message.kind === 'staff'" size="small" color="primary" class="ml-2">{{ $t('tickets.support') }}</va-chip>
          <va-chip v-if="message.internal" size="small" color="warning" class="ml-2">{{ $t('tickets.internalNote') }}</va-chip>
          <span class="ticket-message__date ml-2">{{ formatDate(message.created_at) }}</span>
          <span v-if="message.edited_at" class="ticket-message__date ml-2" :title="formatDate(message.edited_at)">({{ $t('tickets.edited') }})</span>
          <va-button
            v-if="message.can_edit && editingId !== message.id"
            preset="plain"
            size="small"
            class="ml-2"
            @click="startEdit(message)"
          >{{ $t('common.edit') }}</va-button>
        </div>

        <template v-if="editingId === message.id">
          <TicketEditor v-model="form.body" :error="form.errors.body" />
          <div class="mt-2">
            <va-button size="small" :disabled="form.processing" @click="save(message)">{{ $t('common.save') }}</va-button>
            <va-button size="small" color="textInverted" class="ml-2" @click="cancelEdit">{{ $t('common.cancel') }}</va-button>
          </div>
        </template>
        <div v-else-if="message.redacted" class="ticket-message__redacted">{{ $t('tickets.redacted') }}</div>
        <div v-else class="ticket-message__body" v-html="message.body_html"></div>
      </va-card-content>
    </va-card>
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

  .ticket-message__date {
    color: var(--va-secondary);
    font-size: 0.85em;
  }

  .ticket-message__redacted {
    font-style: italic;
    color: var(--va-secondary);
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
      color: var(--va-secondary);
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
