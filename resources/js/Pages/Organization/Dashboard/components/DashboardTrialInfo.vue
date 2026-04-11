<script setup>
import { Link } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
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
          title: 'Welcome!',
          description: 'Welcome to the Control Panel<br /> You are currently on our 1 month trial',
          link: ''
        },
        {
          title: 'Apps',
          description: 'We have many apps to offer',
          link: {
            label: 'Discover apps',
            url: '/discover'
          }
        },
        {
          title: 'Plans',
          description: 'Once you\'re done trialling our apps, upgrade your plan to gain the full range of benefits and features',
          link: {
            url: '/subscription/options',
            label: 'Upgrade plan'
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
