<script setup lang="ts">
import { Link } from '@inertiajs/vue3'

</script>
<template>
  <div class="user">
    <div class="row">
      <div class="flex xs12 lg12">
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
      </div>
    </div>
  </div>
</template>

<script lang="ts">

export default {
  data () {
    const organization = this.$page.props.organization
    const app = this.$page.props.app
    const pathname = (new URL(window.location.href)).pathname
    const basePath = '/admin/organizations/' + organization.id + '/apps/' + app.id

    return {
      pathname,
      value: pathname,
      tabs: [
        {
          title: this.$t('form.view'),
          url: basePath
        },
        {
          title: this.$t('form.edit'),
          url: basePath + '/edit'
        }
      ]
    }
  }
}
</script>

<style lang="scss"></style>
