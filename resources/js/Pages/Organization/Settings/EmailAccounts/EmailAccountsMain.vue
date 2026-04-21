<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <Head>
    <title>{{ t('organization.emailAccounts.emailAccounts') }} - Control Panel</title>
  </Head>
  <va-card class="mb-4">
    <va-card-title>{{ t('organization.emailAccounts.emailAccounts') }}</va-card-title>
    <va-card-content>
      <va-tabs v-model="value" hide-slider>
        <template #tabs>
          <Link v-for="(tab, index) in tabs" :href="tab.url" :key="index"><va-tab :key="tab.title" :name="tab.title">{{ tab.title }}</va-tab></Link>
        </template>
      </va-tabs>
      <slot />
    </va-card-content>
  </va-card>
</template>

<script lang="ts">
export default {
  data () {
    const i18n = useI18n()
    const pathname = (new URL(window.location.href)).pathname
    const tabs = [
      {
        title: i18n.t('organization.emailAccounts.accounts'),
        url: '/settings/email/accounts'
      },
      {
        title: i18n.t('organization.emailAccounts.forwarders'),
        url: '/settings/email/forwarders'
      }
    ]
    let value = i18n.t('organization.emailAccounts.accounts')
    Object.values(tabs).forEach((tab) => {
      if (tab.url === pathname) {
        value = tab.title
      }
    })

    return {
      tabs,
      value
    }
  }
}
</script>

<style lang="scss">
  .va-table {
    width: 100%;
  }
</style>
