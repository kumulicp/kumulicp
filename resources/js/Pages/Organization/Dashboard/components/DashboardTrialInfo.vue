<script setup>
import { Link } from '@inertiajs/vue3'

</script>
<template>
  <va-carousel
    v-model="value"
    :items="items"
    class="mb-2"
    arrows
    indicators
  >
    <template #default="{ item }">
      <div class="va-text-center" style="margin-top: -100px">
        <h1 class="va-h1">{{ item.title }}</h1>
        <p class="mb-3"><span v-html="item.description"></span></p>
        <Link :href="item.link.url" v-if="item.link"><va-button>{{ item.link.label }}</va-button></Link>
      </div>
    </template>
  </va-carousel>
</template>
<script>
export default {
  data () {
    return {
      value: 0,
      items: [
        {
          title: this.$t('auth.welcomeGreeting'),
          description: this.$t('organization.dashboard.trialWelcome'),
          link: ''
        },
        {
          title: this.$t('admin.apps.appWord'),
          description: this.$t('organization.dashboard.trialApps'),
          link: {
            label: this.$t('organization.dashboard.discoverApps'),
            url: '/discover'
          }
        },
        {
          title: this.$t('organization.dashboard.plans'),
          description: this.$t('organization.dashboard.trialPlans'),
          link: {
            url: '/subscription/options',
            label: this.$t('organization.dashboard.upgradePlan')
          }
        },
        {
          title: '',
          description: '',
          link: ''
        }
      ]
    }
  }
}
</script>
