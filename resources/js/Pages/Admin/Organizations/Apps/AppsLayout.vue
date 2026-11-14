<script setup lang="ts">
import SharedAppLayout from '../../SharedApps/SharedAppLayout.vue'
import SharedAppTabs from '@/components/SharedAppTabs.vue'
import { Link } from '@inertiajs/vue3'

</script>
<template>
  <SharedAppLayout>
  <div class="user">
    <div class="row">
      <div class="flex xs12 lg12">
        <va-card class="mb-4">
          <va-card-content>
            <SharedAppTabs v-if="$page.props.shared_app" />
            <template v-else>
              <va-tabs v-model="value" hide-slider>
                <template #tabs>
                  <Link v-for="(tab, index) in tabs" :key="index" :href="tab.url"><va-tab :key="tab.title" :name="tab.title">{{ tab.title }}</va-tab></Link>
                </template>
              </va-tabs>
              <va-separator />
            </template>
            <slot></slot>
          </va-card-content>
        </va-card>
      </div>
    </div>
  </div>
  </SharedAppLayout>
</template>

<script lang="ts">

export default {
  data () {
    const organization = this.$page.props.organization
    const app = this.$page.props.app
    const pathname = (new URL(window.location.href)).pathname
    const basePath = '/admin/organizations/' + organization.id + '/apps/' + app.id

    // A shared app has its own tabs, between all its pages (see SharedAppTabs)
    const tabs = [
      {
        title: this.$t('common.view'),
        url: basePath
      },
      {
        title: this.$t('common.edit'),
        url: basePath + '/edit'
      },
      {
        title: this.$t('admin.apps.helmValues'),
        url: basePath + '/helm-values'
      }
    ]

    return {
      pathname,
      value: pathname,
      tabs
    }
  }
}
</script>

<style lang="scss"></style>
