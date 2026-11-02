<script setup>
// Short activity list: status changes, assignments and so on.
defineProps({
  events: { type: Array, required: true },
})

function formatDate (value) {
  return value ? new Date(value).toLocaleString() : ''
}
</script>
<template>
  <ul v-if="events.length" class="ticket-timeline">
    <li v-for="event in events" :key="event.id" class="ticket-timeline__item">
      <div>
        <template v-if="event.type === 'status_changed'">
          {{ $t('tickets.timeline.status_changed', { actor: event.actor || $t('tickets.system'), to: $t(`tickets.status.${event.context.to}`) }) }}
        </template>
        <template v-else-if="event.type === 'assigned'">
          <template v-if="event.context.to_name">
            {{ $t(`tickets.timeline.assigned_${event.context.side}`, { actor: event.actor || $t('tickets.system'), name: event.context.to_name }) }}
          </template>
          <template v-else>
            {{ $t(`tickets.timeline.unassigned_${event.context.side}`, { actor: event.actor || $t('tickets.system') }) }}
          </template>
        </template>
        <template v-else-if="event.type === 'assignee_removed'">
          {{ $t(`tickets.timeline.assignee_removed_${event.context.side}`) }}
        </template>
        <template v-else-if="event.type === 'priority_changed'">
          {{ $t('tickets.timeline.priority_changed', { actor: event.actor || $t('tickets.system'), to: $t(`tickets.priority.${event.context.to}`) }) }}
        </template>
        <template v-else-if="event.type === 'tags_changed'">
          {{ $t('tickets.timeline.tags_changed', { actor: event.actor || $t('tickets.system'), tags: (event.context.tags || []).join(', ') || '-' }) }}
        </template>
        <template v-else>
          {{ $t(`tickets.timeline.${event.type}`, { actor: event.actor || $t('tickets.system') }) }}
        </template>
      </div>
      <div class="ticket-timeline__date">{{ formatDate(event.created_at) }}</div>
    </li>
  </ul>
</template>
<style lang="scss">
  .ticket-timeline {
    list-style: none;
    padding: 0;
    margin: 0;

    &__item {
      padding: 0.4em 0;
      border-bottom: 1px solid var(--va-background-border);
      font-size: 0.9em;
    }

    &__date {
      color: var(--va-secondary);
      font-size: 0.85em;
    }
  }
</style>
