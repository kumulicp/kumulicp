<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <va-card class="mb-4">
    <va-card-content>
      <va-tabs v-model="value" hide-slider>
        <template #tabs>
          <Link v-for="(tab, index) in tabs" :key="index" :href="tab.url"><va-tab :key="tab.title" :name="tab.title">{{ tab.title }}</va-tab></Link>
        </template>
      </va-tabs>
      <va-separator />
      <slot></slot>
    </va-card-content>
  </va-card>
</template>

<script lang="ts">

export default {
  data () {
    const organization = this.$page.props.organization
    const pathname = (new URL(window.location.href)).pathname
    const basePath = '/admin/organizations/' + organization.id
    const tabs = [
      {
        title: 'Details',
        url: basePath
      },
      {
        title: 'Apps',
        url: basePath + '/apps'
      },
      {
        title: 'Logs',
        url: basePath + '/logs'
      },
      {
        title: 'Tasks',
        url: basePath + '/tasks'
      },
      {
        title: 'Backups',
        url: basePath + '/backups'
      },
      {
        title: 'Domains',
        url: basePath + '/domains'
      }
    ]

    let value = 'View'
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
