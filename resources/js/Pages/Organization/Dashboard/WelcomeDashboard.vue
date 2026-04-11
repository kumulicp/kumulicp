<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <Head>
    <title>Welcome! - Control Panel</title>
  </Head>
  <div class="welcome">
    <div class="row">
      <div class="flex flex-col xs12 lg12">
        <va-card class="mb-4">
          <va-card-content>
            <div v-html="page_content"></div>
          </va-card-content>
        </va-card>
      </div>
    </div>
  </div>
</template>

<script lang="ts">
export default {
  layout: (h, page) => h(AppLayout, [page]),
  props: {
    page_content: String
  },
  data () {
    return {
      value: 0,
      items: ['', '', '', '', '', ''],
      showModal: true,
      welcomeTitle: [
        'Subscription',
        'Apps',
        'Users',
        'Domains',
        'Emails'
      ]
    }
  }
}
</script>

<style lang="scss">
  #welcome ol,
  #welcome li,
  #welcome ul{
    all: revert
  }
</style>
