<script setup lang="ts">
import { Link } from '@inertiajs/vue3'

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
    const app = this.$page.props.app
    const version = this.$page.props.version
    const pathname = (new URL(window.location.href)).pathname
    const basePath = '/admin/apps/' + app.slug + '/versions/' + version.version
    const tabs = [
      {
        title: this.$t('form.edit'),
        url: basePath
      },
      {
        title: this.$t('admin.roles.roles'),
        url: basePath + '/roles'
      }
    ]

    let value = this.$t('form.edit')
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
