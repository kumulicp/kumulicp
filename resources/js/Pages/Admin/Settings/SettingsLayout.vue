<script setup lang="ts">
import { Link } from '@inertiajs/vue3'

</script>
<template>
  <div class="plan">
    <div class="row">
      <div class="flex xs12 lg12">
        <va-card class="mb-4">
          <va-card-content>
            <va-tabs v-model="value" hide-slider>
              <template #tabs>
                <Link v-for="(tab, index) in tabs" :href="tab.url" :key="index"><va-tab :key="tab.title" :name="tab.title">{{ tab.title }}</va-tab></Link>
              </template>
            </va-tabs>
            <va-separator />
            <slot></slot>
          </va-card-content>
        </va-card>
      </div>
    </div>
  </div>
</template>

<script lang="ts">

export default {
  data () {
    const pathname = (new URL(window.location.href)).pathname
    const basePath = '/admin/settings'
    const tabs = [
      {
        title: this.$t('settings.controlPanel'),
        url: basePath
      },
      {
        title: this.$t('settings.invoice'),
        url: basePath + '/invoice'
      },
      {
        title: this.$t('settings.ldap'),
        url: basePath + '/ldap'
      },
      {
        title: this.$t('settings.ssoProviders'),
        url: basePath + '/sso-providers'
      },
      {
        title: this.$t('settings.pullSecrets'),
        url: basePath + '/pull-secrets'
      },
      {
        title: this.$t('settings.selfRegistration'),
        url: basePath + '/registration'
      }
    ]

    let value = this.$t('settings.controlPanel')
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

<style lang="scss"></style>
