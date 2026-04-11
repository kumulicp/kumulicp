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
          <Link v-for="(tab, index) in tabs" :href="tab.url" :key="index"><va-tab :key="tab.title" :name="tab.title">{{ tab.title }}</va-tab></Link>
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
    const server = this.$page.props.server
    const pathname = (new URL(window.location.href)).pathname
    const basePath = '/admin/server/servers/' + server.id
    const tabs = [
      {
        title: 'Edit',
        url: basePath
      },
      {
        title: 'Helm Chart Values',
        url: basePath + '/chart'
      }
    ]

    let value = 'Edit'
    Object.values(tabs).forEach((tab) => {
      if (tab.url === pathname) {
        value = tab.title
      }
    })

    return {
      server,
      tabs,
      value
    }
  }
}
</script>

<style lang="scss"></style>
