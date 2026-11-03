<script setup>
// One service level policy: targets per priority (in hours), and the hours
// during which the clock runs.
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'

const props = defineProps({
  policy: { type: Object, required: true },
  priorities: { type: Array, required: true },
})

const form = useForm({
  name: props.policy.name,
  active: props.policy.active,
  is_default: props.policy.is_default,
  targets: JSON.parse(JSON.stringify(props.policy.targets)),
  business_hours: JSON.parse(JSON.stringify(props.policy.business_hours)),
})

// Holidays are typed one per line
const holidays = ref((props.policy.business_hours.holidays || []).join('\n'))
const showDelete = ref(false)

const timezones = computed(() => {
  try {
    return Intl.supportedValuesOf('timeZone')
  } catch {
    return ['UTC', 'Europe/London', 'Europe/Madrid', 'America/New_York', 'America/Denver', 'America/Los_Angeles']
  }
})

const weekdays = [1, 2, 3, 4, 5, 6, 7]

function save () {
  form.transform((data) => ({
    ...data,
    business_hours: {
      ...data.business_hours,
      holidays: holidays.value.split(/\s*[\n,]\s*/).map(line => line.trim()).filter(Boolean),
    },
  })).put(`/admin/support/settings/policies/${props.policy.id}`, { preserveScroll: true })
}

function destroy () {
  router.delete(`/admin/support/settings/policies/${props.policy.id}`, { preserveScroll: true })
}
</script>
<template>
  <va-card class="mb-4" :id="`policy-${policy.id}`">
    <va-card-title>
      <div class="flex justify-between items-center w-full">
        <span>{{ policy.name }}</span>
        <span>
          <va-chip v-if="policy.is_default" size="small" color="primary">{{ $t('tickets.settings.default') }}</va-chip>
          <va-chip v-if="!policy.active" size="small" color="secondary">{{ $t('tickets.settings.inactive') }}</va-chip>
        </span>
      </div>
    </va-card-title>
    <va-card-content>
      <form @submit.prevent="save">
        <div class="row mb-3">
          <div class="flex xs12 md6 pr-2">
            <va-input v-model="form.name" :label="$t('tickets.integrations.name')" :error="!!form.errors.name" :error-messages="form.errors.name" />
          </div>
          <div class="flex xs12 md6">
            <va-switch v-model="form.active" class="d-block mb-1" :label="$t('tickets.settings.active')" />
            <va-switch v-model="form.is_default" class="d-block" :label="$t('tickets.settings.makeDefault')" />
          </div>
        </div>

        <div class="va-input-label">{{ $t('tickets.settings.targets') }}</div>
        <div class="integration-help mb-2">{{ $t('tickets.settings.targetsHelp') }}</div>
        <table class="va-table mb-3">
          <thead>
            <tr>
              <th>{{ $t('tickets.priorityLabel') }}</th>
              <th>{{ $t('tickets.sla.clocks.first_response') }}</th>
              <th>{{ $t('tickets.sla.clocks.resolution') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="priority in priorities" :key="priority.value">
              <td>{{ priority.text }}</td>
              <td><va-input v-model="form.targets[priority.value].first_response" type="number" min="0" step="0.25" :error="!!form.errors[`targets.${priority.value}.first_response`]" /></td>
              <td><va-input v-model="form.targets[priority.value].resolution" type="number" min="0" step="0.25" :error="!!form.errors[`targets.${priority.value}.resolution`]" /></td>
            </tr>
          </tbody>
        </table>

        <va-switch v-model="form.business_hours.enabled" class="mb-2" :label="$t('tickets.settings.businessHours')" />
        <div v-if="form.business_hours.enabled" class="mb-3">
          <div class="integration-help mb-2">{{ $t('tickets.settings.businessHoursHelp') }}</div>
          <va-select v-model="form.business_hours.timezone" class="mb-2" :label="$t('tickets.settings.timezone')" :options="timezones" searchable :error="!!form.errors['business_hours.timezone']" :error-messages="form.errors['business_hours.timezone']" />
          <div class="mb-2">
            <va-checkbox
              v-for="day in weekdays"
              :key="day"
              v-model="form.business_hours.days"
              :array-value="day"
              :label="$t(`tickets.settings.days.${day}`)"
              class="mr-3"
            />
            <div v-if="form.errors['business_hours.days']" class="text-danger">{{ form.errors['business_hours.days'] }}</div>
          </div>
          <div class="row mb-2">
            <div class="flex xs6 pr-2"><va-input v-model="form.business_hours.start" type="time" :label="$t('tickets.settings.from')" :error="!!form.errors['business_hours.start']" /></div>
            <div class="flex xs6"><va-input v-model="form.business_hours.end" type="time" :label="$t('tickets.settings.until')" :error="!!form.errors['business_hours.end']" :error-messages="form.errors['business_hours.end']" /></div>
          </div>
          <va-textarea v-model="holidays" :label="$t('tickets.settings.holidays')" :messages="$t('tickets.settings.holidaysHelp')" :min-rows="2" />
        </div>

        <va-button type="submit" :disabled="form.processing">{{ $t('common.save') }}</va-button>
        <va-button color="danger" preset="plain" class="ml-2" @click.prevent="showDelete = true">{{ $t('common.delete') }}</va-button>
      </form>
    </va-card-content>
  </va-card>

  <va-modal v-model="showDelete" hide-default-actions :title="$t('tickets.settings.deletePolicy')" :message="$t('tickets.settings.deletePolicyMessage')">
    <template #footer="{ cancel }">
      <va-button color="backgroundSecondary" @click="cancel">{{ $t('common.cancel') }}</va-button>
      <va-button color="danger" @click="destroy">{{ $t('common.delete') }}</va-button>
    </template>
  </va-modal>
</template>
