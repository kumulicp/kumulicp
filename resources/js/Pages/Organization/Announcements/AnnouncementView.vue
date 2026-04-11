<script setup>
import AppLayout from '@/layouts/AppLayout.vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <Head>
    <title>Announcement: {{ announcement.title }} - Control Panel</title>
  </Head>
  <va-card class="mb-4">
    <va-card-title>Announcement: {{ announcement.title }}</va-card-title>
    <va-card-content>
      <div class="mb-3">{{ announcement.date }}</div>
      <div id="announcement" style="overflow-x: auto; overflow-y: hidden" v-html="announcement.content"></div>
    </va-card-content>
  </va-card>
</template>

<script>
export default {
  layout: (h, page) => {
    return h(AppLayout, () => page)
  },
  props: {
    announcement: Object
  }
}
</script>

<style>
  #announcement ol,
  #announcement li,
  #announcement ul {
    all: revert
  }

</style>
