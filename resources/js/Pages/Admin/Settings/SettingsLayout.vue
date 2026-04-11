<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
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
        title: useI18n().t('settings.controlPanel'),
        url: basePath
      },
      {
        title: useI18n().t('settings.invoice'),
        url: basePath + '/invoice'
      },
      {
        title: useI18n().t('settings.ldap'),
        url: basePath + '/ldap'
      },
      {
        title: useI18n().t('settings.ssoProviders'),
        url: basePath + '/sso-providers'
      }
    ]

    let value = useI18n().t('settings.controlPanel')
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
