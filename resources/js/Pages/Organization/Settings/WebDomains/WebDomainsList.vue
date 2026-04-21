<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { Link, router } from '@inertiajs/vue3'
import NewDomainModal from './modals/NewDomainModal.vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <Head>
    <title>{{ t('organization.webDomains.domains') }} - Control Panel</title>
  </Head>
  <va-card class="mb-4">
    <va-card-title>{{ t('organization.webDomains.webDomains') }}</va-card-title>
    <va-card-content>
      <div v-if="can.register_domains || can.transfer_domains" class="row justify-center">
        <va-button v-if="can.add_domains" id="addDomain" class="" @click="showAddDomain = !showAddDomain">{{ t('organization.webDomains.addDomain') }}</va-button>
        <new-domain-modal :showModal="showAddDomain" @update:showModal="showAddDomain = $event" />
      </div>
      <div v-else-if="can.connect_domains"  class="row justify-center">
        <Link id="connect" href="/settings/domains/connect">
          <va-button id="addDomain">{{ t('organization.webDomains.addDomain') }}</va-button>
        </Link>
      </div>
      <div class="web-domains-domains">
        <div class="table-wrapper">
          <va-scroll-container
            color="primary"
            horizontal
          >
            <table class="va-table va-table--hoverable mt-3">
              <thead>
                <tr>
                  <th style="width: 20%">{{ t('organization.webDomains.domainName') }}</th>
                  <th style="width: 20%">{{ t('organization.webDomains.pointsTo') }}</th>
                  <th v-if="suborganization_count > 0">{{ t('organization.organization') }}</th>
                  <th>{{ t('organization.webDomains.registered') }}</th>
                  <th>{{ t('organization.webDomains.expires') }}</th>
                  <th>{{ t('admin.apps.status') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="domain in domains" :key="domain.name" style="min-height:300px;">
                  <td>
                    <Link :href="'/settings/domains/'+domain.name">{{ domain.name }}</Link>
                    <span v-if="domain.email_status === 'activating'" style="color: var(--va-list-item-label-caption-color)"> - {{ t('organization.webDomains.emailActivating') }}<span v-if="domain.type === 'custom'">. {{ t('organization.webDomains.confirmDnsSettings') }}</span></span>
                    <span v-else-if="domain.email_status === 'waiting_dns' && domain.type === 'organization'" style="color: var(--va-list-item-label-caption-color)"> - {{ t('organization.webDomains.waitingDns') }}</span>
                  </td>
                  <td><Link :href="'/apps/'+domain.app.id+'/edit'">{{ domain.app.name }}</Link></td>
                  <td v-if="suborganization_count > 0">{{ domain.organization.name }}</td>
                  <td>{{ domain.registered }}</td>
                  <td>{{ domain.expires }}</td>
                  <td>{{ domain.status }}</td>
                </tr>
              </tbody>
            </table>
          </va-scroll-container>
          <va-pagination v-if="meta.total > pageSize" class="mt-3 mb-3 justify-center" v-model="curPageValue" :pages="pages" input @update:modelValue="changePage" />
        </div>
      </div>
    </va-card-content>
  </va-card>
</template>

<script lang="ts">
export default {
  layout: (h, page) => {
    return h(AppLayout, () => page)
  },
  props: {
    domains: Object,
    can: Object,
    suborganization_count: Number,
    meta: Object
  },
  data () {
    return {
      curPageValue: this.meta.page,
      pages: this.meta.pages,
      pageSize: 20,
      showAddDomain: false
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
  .va-table {
    width: 100%;
  }
  .va-table tbody tr td{
    vertical-align: middle;
  }
</style>
