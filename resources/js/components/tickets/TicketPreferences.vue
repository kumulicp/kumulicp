<script setup>
// "Notification settings": which ticket events reach this person by email and
// in the panel. Shared by the customer and staff lists.
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'

const props = defineProps({
  // { events: [...], matrix: { event: { mail: bool, panel: bool } } }
  preferences: { type: Object, required: true },
  // The page opens it from its own menu instead
  hideButton: { type: Boolean, default: false },
})

const open = ref(false)
const form = useForm({ preferences: JSON.parse(JSON.stringify(props.preferences.matrix)) })

defineExpose({ show: () => { open.value = true } })

function save () {
  form.put('/support/tickets/preferences', {
    preserveScroll: true,
    onSuccess: () => { open.value = false },
  })
}
</script>
<template>
  <va-button v-if="!hideButton" preset="secondary" border-color="primary" @click="open = true">{{ $t('tickets.preferences.title') }}</va-button>

  <va-modal v-model="open" hide-default-actions no-padding size="small">
    <template #content>
      <va-card-title>{{ $t('tickets.preferences.title') }}</va-card-title>
      <form @submit.prevent="save">
        <va-card-content>
          <p class="va-p mb-3">{{ $t('tickets.preferences.help') }}</p>
          <table class="va-table">
            <thead>
              <tr>
                <th>{{ $t('tickets.preferences.event') }}</th>
                <th>{{ $t('tickets.preferences.email') }}</th>
                <th>{{ $t('tickets.preferences.panel') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="event in preferences.events" :key="event">
                <td>{{ $t(`tickets.preferences.events.${event}`) }}</td>
                <td><va-switch v-model="form.preferences[event].mail" size="small" :aria-label="$t('tickets.preferences.email')" /></td>
                <td><va-switch v-model="form.preferences[event].panel" size="small" :aria-label="$t('tickets.preferences.panel')" /></td>
              </tr>
            </tbody>
          </table>
        </va-card-content>
        <va-card-actions align="right">
          <va-button color="textInverted" @click="open = false">{{ $t('common.cancel') }}</va-button>
          <va-button type="submit" :disabled="form.processing">{{ $t('common.save') }}</va-button>
        </va-card-actions>
      </form>
    </template>
  </va-modal>
</template>
