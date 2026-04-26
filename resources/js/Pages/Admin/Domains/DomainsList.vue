<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import AppsLayout from './DomainsLayout.vue'
import { Link } from '@inertiajs/vue3'

</script>

<template>
  <Head>
    <title>{{ $t('admin.domains.domains') }} - Control Panel</title>
  </Head>
  <VaScrollContainer
    color="primary"
    horizontal
  >
    <table class="va-table va-table--hoverable mt-3">
      <thead>
        <tr>
          <th>{{ $t('admin.domains.id') }}</th>
          <th>{{ $t('admin.domains.domainName') }}</th>
          <th>{{ $t('admin.domains.user') }}</th>
          <th>{{ $t('admin.domains.created') }}</th>
          <th>{{ $t('admin.domains.expires') }}</th>
          <th>{{ $t('admin.domains.isExpired') }}</th>
          <th>{{ $t('admin.domains.isLocked') }}</th>
          <th>{{ $t('admin.domains.autoRenew') }}</th>
          <th>{{ $t('admin.domains.whoisGuard') }}</th>
          <th>{{ $t('admin.domains.isPremium') }}</th>
          <th>{{ $t('admin.domains.isNamecheapDns') }}</th>
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
            <Link :href="'/admin/service/domains/'+domain.name">{{ $t('admin.domains.updateDb') }}</Link>
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
