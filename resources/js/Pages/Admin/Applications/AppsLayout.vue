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
    const pathname = (new URL(window.location.href)).pathname
    const basePath = '/admin/apps/' + app.slug

    return {
      pathname,
      value: pathname,
      tabs: [
        { title: this.$t('form.view'), url: basePath },
        { title: this.$t('form.edit'), url: basePath + '/edit' },
        { title: this.$t('admin.versions.versions'), url: basePath + '/versions' },
        { title: this.$t('admin.plans.plan'), url: basePath + '/plans' },
        { title: this.$t('admin.roles.details'), url: basePath + '/roles' }
      ]
    }
  }
}
</script>

<style lang="scss"></style>
