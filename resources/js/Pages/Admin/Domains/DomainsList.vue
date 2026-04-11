<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import AppsLayout from './DomainsLayout.vue'
import { Link } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>

<template>
  <Head>
    <title>Domains - Control Panel</title>
  </Head>
  <VaScrollContainer
    color="primary"
    horizontal
  >
    <table class="va-table va-table--hoverable mt-3">
      <thead>
        <tr>
          <th>ID</th>
          <th>Domain Name</th>
          <th>User</th>
          <th>Created</th>
          <th>Expires</th>
          <th>Is Expired</th>
          <th>Is Locked</th>
          <th>Auto Renew</th>
          <th>WhoisGuard</th>
          <th>Is Premium</th>
          <th>Is Namecheap DNS</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="(domain, index) in domains.slice((curPageValue - 1), (curPageValue - 1 + pageSize))" :key="index">
          <td>
            <Link :href="'/admin/service/domains/'+domain.id">{{ domain.id }}</Link>
          </td>
          <td>
            {{ domain.name }}
          </td>
          <td>
            {{ domain.user }}
          </td>
          <td>
            {{ domain.created }}
          </td>
          <td>
            {{ domain.expires }}
          </td>
          <td>
            {{ domain.is_expired }}
          </td>
          <td>
            {{ domain.is_locked }}
          </td>
          <td>
            {{ domain.auto_renew }}
          </td>
          <td>
            {{ domain.whois_guard }}
          </td>
          <td>
            {{ domain.is_premium }}
          </td>
          <td>
            {{ domain.is_our_dns }}
          </td>
          <td class="va-text-center">
            <Link :href="'/admin/service/domains/'+domain.name">Update DB</Link>
          </td>
        </tr>
      </tbody>
    </table>
  </VaScrollContainer>
  <va-pagination v-if="domains.length > pageSize" class="mt-3 mb-3 justify-center" v-model="curPageValue" :total="domains.length" direction-links
    :page-size="pageSize" />
</template>

<script lang="ts">
export default {
  layout: (h, page) => {
    return h(AppLayout, () => h(AppsLayout, () => page))
  },
  props: {
    domains: Object
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
.row-equal .flex {
  .va-card {
    height: 100%;
  }
}

.domains-list {
  .va-card {
    margin-bottom: 0 !important;

    &__title {
      display: flex;
      justify-content: space-between;
    }
  }
}

.clickable-icon {
  transition: 0.3s;

  &:hover {
    opacity: 0.25;
    cursor: pointer;
  }
}
</style>
