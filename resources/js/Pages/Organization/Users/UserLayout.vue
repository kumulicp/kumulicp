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
    const user = this.$page.props.user
    const pathname = (new URL(window.location.href)).pathname
    const basePath = '/users/' + user.id
    const tabs = [
      {
        title: this.$t('common.view'),
        url: basePath
      },
      {
        title: this.$t('organization.profile.profile'),
        url: basePath + '/edit'
      },
      {
        title: this.$t('organization.groups.groups'),
        url: basePath + '/groups'
      },
      {
        title: this.$t('organization.users.permissions'),
        url: basePath + '/permissions'
      }
    ]

    let value = this.$t('common.view')
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
