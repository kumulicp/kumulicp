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
  computed: {
    basePath () {
      const app = this.$page.props.app
      const version = this.$page.props.version
      return '/admin/apps/' + app.slug + '/versions/' + version.version
    },
    tabs () {
      return [
        {
          title: this.$t('common.edit'),
          url: this.basePath
        },
        {
          title: this.$t('admin.roles.roles'),
          url: this.basePath + '/roles'
        }
      ]
    },
    value () {
      const pathname = (new URL(window.location.href)).pathname
      const active = this.tabs.find((tab) => tab.url === pathname)
      return active ? active.title : this.$t('common.edit')
    }
  }
}
</script>

<style lang="scss"></style>
