<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import OrganizationLayout from '../OrganizationLayout.vue'
import { Link } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <Head>
    <title>Organization App - Control Panel</title>
  </Head>
  <table class="va-table va-table--hoverable mt-3">
    <thead>
      <tr>
        <th>Name</th>
        <th>Version</th>
        <th>Domain name</th>
        <th>Status</th>
      </tr>
    </thead>
    <tbody>
      <tr v-for="app in apps.slice((curPageValue - 1), (curPageValue - 1 + pageSize))" :key="app.id" style="min-height:300px;">
        <td><Link :href="'/admin/organizations/'+organization.id+'/apps/'+app.id">{{ app.name }}</Link></td>
        <td>{{ app.version }}</td>
        <td>{{ app.domain.name }}</td>
        <td>{{ app.status }}</td>
      </tr>
    </tbody>
  </table>

  <va-pagination v-if="apps.length > pageSize" class="mt-3 mb-3 justify-center" v-model="curPageValue" :total="apps.length" :direction-links="false" :page-size="pageSize" />
</template>

<script lang="ts">
export default {
  layout: (h, page) => {
    return h(AppLayout, () => h(OrganizationLayout, () => page))
  },
  props: {
    organization: Object,
    apps: Object
  },
  data () {
    return {
      curPageValue: 1,
      pageSize: 10
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
