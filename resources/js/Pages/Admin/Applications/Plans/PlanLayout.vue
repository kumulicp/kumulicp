<script setup lang="ts">
import { Link } from '@inertiajs/vue3'

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
    const app = this.$page.props.app
    const plan = this.$page.props.plan
    const pathname = (new URL(window.location.href)).pathname
    const basePath = '/admin/apps/' + app.slug + '/plans/' + plan.id
    const tabs = [
      {
        title: this.$t('common.view'),
        url: basePath
      },
      {
        title: this.$t('admin.plans.settings'),
        url: basePath + '/edit'
      }
    ]

    // Which of these a plan has is decided by the server: a shared app's hidden
    // plan only has them for an app with a registered profile, and a plan that
    // connects to a shared app has its features (unless the app is multisite),
    // configurations and secrets set on that shared app instead.
    if (plan.tabs?.features) {
      tabs.push({
        title: this.$t('admin.plans.features'),
        url: basePath + '/features'
      })
    }
    if (plan.tabs?.configurations) {
      tabs.push({
        title: this.$t('admin.plans.serverConfigurations'),
        url: basePath + '/configurations'
      })
    }
    if (plan.tabs?.secrets) {
      tabs.push({
        title: this.$t('admin.plans.secrets'),
        url: basePath + '/secrets'
      })
    }

    let value = this.$t('common.view')
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
