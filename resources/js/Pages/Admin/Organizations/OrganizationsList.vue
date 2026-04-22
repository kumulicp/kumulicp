<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { Link } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <Head>
    <title>{{ t('admin.organizations.organizations') }} - Control Panel</title>
  </Head>
  <va-card class="mb-4">
    <va-card-title>{{ t('admin.organizations.organizations') }}</va-card-title>
    <va-card-content>
      <VaScrollContainer
        color="primary"
        horizontal
      >
        <table class="va-table va-table--hoverable mt-3">
          <thead>
            <tr>
              <th>{{ t('admin.organizations.name') }}</th>
              <th>{{ t('admin.organizations.contactName') }}</th>
              <th>{{ t('admin.organizations.contactEmail') }}</th>
              <th>{{ t('admin.organizations.status') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="organization in organizations.slice((curPageValue - 1), (curPageValue - 1 + pageSize))" :key="organization.name" style="min-height:300px;">
              <td><Link :href="'/admin/organizations/'+organization.id">{{ organization.name }}</Link> <span v-if="organization.is_suborg" class="secondary">({{ t('admin.organizations.suborganization') }})</span></td>
              <td>{{ organization.contact_name}}</td>
              <td><Link :href="'mailto:'+organization.contact_email">{{ organization.contact_email}}</Link></td>
              <td>{{ organization.status }}</td>
            </tr>
          </tbody>
        </table>
      </VaScrollContainer>

      <va-pagination v-if="organizations.length > pageSize" class="mt-3 mb-3 justify-center" v-model="curPageValue" :total="organizations.length" :direction-links="false" :page-size="pageSize" />
    </va-card-content>
  </va-card>
</template>

<script lang="ts">
export default {
  layout: (h, page) => h(AppLayout, [page]),
  props: {
    organizations: Object
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
.secondary {
  color: #666E75
}
.clickable-icon {
  transition: 0.3s;

  &:hover {
    opacity: 0.25;
    cursor: pointer;
  }
}
</style>
