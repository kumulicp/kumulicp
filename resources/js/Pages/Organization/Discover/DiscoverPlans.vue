<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import PlanCard from '@/components/cards/PlanCard.vue'

</script>
<template>
  <Head>
    <title>{{ $t('organization.discover.appPlans') }} - Control Panel</title>
  </Head>
  <div class="row justify-center">
    <div class="flex flex-col xs12 mb-3">
      <h2 id="plans-app-name" class="va-h4">{{ app.name }} - {{ $t('organization.discover.appPlans') }}</h2>
    </div>
  </div>
  <div v-if="has_suborganizations" class="row justify-center">
    <div class="flex flex-col xs12 mb-3">
      <va-alert color="primary" outline id="plans-organization">
        <template #icon>
          <va-icon name="info" color="primary" />
        </template>
        <div class="va-text-bold">{{ $t('organization.discover.choosingPlanFor', { name: organization.name }) }}</div>
        <div>{{ $t('organization.discover.choosingPlanForMessage') }}</div>
      </va-alert>
    </div>
  </div>
  <div class="row justify-center">
    <div v-for="(plan, index) in plans" class="flex flex-col xs12 md6 lg4 xl3 mb-3" :key="index">
      <plan-card :plan="plan" :app="app" select fullHeight />
    </div>
  </div>
</template>

<script lang="ts">
export default {
  layout: (h, page) => h(AppLayout, [page]),
  props: {
    plans: Object,
    app: Object,
    organization: Object,
    has_suborganizations: Boolean
  }
}
</script>

<style lang="scss"></style>
