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
    const user = this.$page.props.user
    const pathname = (new URL(window.location.href)).pathname
    const basePath = '/settings/organization'
    const tabs = [
      {
        title: 'Settings',
        url: basePath
      },
      {
        title: 'Suborganizations',
        url: basePath + '/subs'
      }
    ]

    let value = 'Settings'
    Object.values(tabs).forEach((tab) => {
      if (tab.url === pathname) {
        value = tab.title
      }
    })

    return {
      user,
      tabs,
      value
    }
  }
}
</script>

<style lang="scss"></style>
