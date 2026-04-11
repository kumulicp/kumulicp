<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import PlanCard from '@/components/cards/PlanCard.vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <Head>
    <title>{{ app.name }} Plans - Control Panel</title>
  </Head>
  <div class="discover-app">
    <div class="row justify-center">
      <h4 class="va-h4">{{ app.label }} Plans</h4>
    </div>
    <div class="row justify-center">
      <div v-for="(plan, index) in plans" class="flex xs12 md6 lg4 xl3" :key="index">
        <plan-card :plan="plan" :select="!plan.current" :current="plan.current" fullHeight />
      </div>
    </div>
  </div>
</template>

<script lang="ts">
export default {
  layout: (h, page) => h(AppLayout, [page]),
  props: {
    plans: Object,
    display_info: Object,
    app: Object
  },
  data () {
    return {
    }
  }
}
</script>

<style lang="scss"></style>
