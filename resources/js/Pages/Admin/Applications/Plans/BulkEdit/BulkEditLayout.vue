<script setup lang="ts">
import { Link } from '@inertiajs/vue3'

</script>
<template>
  <div class="bulk-edit">
    <div class="row">
      <div class="flex xs12 lg12">
        <va-card class="mb-4">
          <va-card-content>
            <va-tabs v-model="value" hide-slider>
              <template #tabs>
                <Link v-for="(tab, index) in tabs" :href="tab.url" :key="index">
                  <va-tab :key="tab.title" :name="tab.title">{{ tab.title }}</va-tab>
                </Link>
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
    const planIds: number[] = this.$page.props.plan_ids ?? []
    const queryString = planIds.map((id: number) => `plans[]=${id}`).join('&')
    const basePath = '/admin/apps/' + app.slug + '/plans/bulk-edit'
    const pathname = (new URL(window.location.href)).pathname

    const tabs = [
      {
        title: this.$t('common.view'),
        url: basePath + '?' + queryString
      },
      {
        title: this.$t('admin.plans.settings'),
        url: basePath + '/edit?' + queryString
      },
      {
        title: this.$t('admin.plans.features'),
        url: basePath + '/features?' + queryString
      },
      {
        title: this.$t('admin.plans.serverConfigurations'),
        url: basePath + '/configurations?' + queryString
      }
    ]

    let value = this.$t('common.view')
    Object.values(tabs).forEach((tab) => {
      if (tab.url.startsWith(pathname)) {
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
