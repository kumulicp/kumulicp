<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <Head>
    <title>Logs - Control Panel</title>
  </Head>
  <va-card class="mb-4">
    <va-card-title>Logs</va-card-title>
      <va-card-content>
        <va-scroll-container
            color="warning"
            horizontal
        >
          <table class="va-table va-table--hoverable mt-3">
          <thead>
              <tr>
              <th style="width: 10rem">Level</th>
              <th style="width: 15rem">Organization</th>
              <th>Message</th>
              <th style="width: 10rem">Time</th>
              </tr>
          </thead>
          <tbody>
              <tr v-for="log in logs" :key="log.name" style="min-height:300px;">
              <td>{{ log.level }}</td>
              <td>{{ log.organization }}</td>
              <td>{{ log.message }}</td>
              <td>{{ log.time }}</td>
              </tr>
          </tbody>
          </table>
      </va-scroll-container>

      <va-pagination v-if="meta.total > pageSize" class="mt-3 mb-3 justify-center" v-model="curPageValue" :pages="pages" input @update:modelValue="changePage" />
    </va-card-content>
  </va-card>
</template>

<script lang="ts">
export default {
  layout: (h, page) => h(AppLayout, [page]),
  props: {
    logs: Object,
    meta: Object
  },
  data () {
    return {
      curPageValue: this.meta.page,
      pages: this.meta.pages,
      pageSize: 30
    }
  },
  methods: {
    changePage () {
      const url = location.protocol + '//' + location.host + location.pathname
      router.visit(url + '?page=' + this.curPageValue, { method: 'get', preserveScroll: true })
    }
  }
}
</script>

<style lang="scss">
.clickable-icon {
  transition: 0.3s;

  &:hover {
    opacity: 0.25;
    cursor: pointer;
  }
}
</style>
