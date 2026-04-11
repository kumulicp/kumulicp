<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <Head>
    <title>Email Accounts - Control Panel</title>
  </Head>
  <va-card class="mb-4">
    <va-card-title>Email Accounts</va-card-title>
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
    const pathname = (new URL(window.location.href)).pathname
    const tabs = [
      {
        title: 'Accounts',
        url: '/settings/email/accounts'
      },
      {
        title: 'Forwarders',
        url: '/settings/email/forwarders'
      }
    ]
    let value = 'Accounts'
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
