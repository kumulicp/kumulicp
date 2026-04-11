<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import PlanCard from '@/components/cards/PlanCard.vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <Head>
    <title>App Plans - Control Panel</title>
  </Head>
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
    app: Object
  }
}
</script>

<style lang="scss"></style>
