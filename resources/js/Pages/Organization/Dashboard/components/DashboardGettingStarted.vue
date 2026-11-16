<script setup lang="ts">
import { Link } from '@inertiajs/vue3'

interface Step {
  key: string
  done: boolean
  optional: boolean
  label: string
  hint: string
  url: string
}

defineProps<{
  steps: Step[]
}>()
</script>

<template>
  <va-card id="getting-started" class="mb-4">
    <va-card-title>{{ $t('organization.dashboard.gettingStarted') }}</va-card-title>
    <va-card-content>
      <p class="mb-3">{{ $t('organization.dashboard.gettingStartedIntro') }}</p>
      <div
        v-for="step in steps"
        :id="'step-' + step.key"
        :key="step.key"
        class="getting-started__step mb-3"
      >
        <va-icon
          :name="step.done ? 'check_circle' : 'radio_button_unchecked'"
          :color="step.done ? 'success' : 'secondary'"
          class="mr-2"
        />
        <span :class="{ 'getting-started__done': step.done }">
          <Link v-if="!step.done" :href="step.url">{{ step.label }}</Link>
          <template v-else>{{ step.label }}</template>
          <va-chip v-if="step.optional" size="small" outline class="ml-2">{{ $t('organization.dashboard.optional') }}</va-chip>
        </span>
        <p v-if="!step.done" class="secondary ml-8 mb-0">{{ step.hint }}</p>
      </div>
    </va-card-content>
  </va-card>
</template>

<style lang="scss">
  .getting-started__done {
    text-decoration: line-through;
    opacity: 0.7;
  }
</style>
