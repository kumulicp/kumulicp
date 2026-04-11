<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import AppsLayout from './AppsLayout.vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <Head>
    <title>View App - Control Panel</title>
  </Head>
  <div class="app-profile">
    <div class="row">
      <div class="flex flex-col xs12 lg2">
        <va-image
          fit="contain"
          class="w-1/2"
          :src="'/images/'+app.slug+'.png'"
          fallbackSrc="/images/generic.png"
          lazy
        />
      </div>
      <div class="flex flex-col lg8">
        {{ app.name }}
      </div>
    </div>
  </div>
</template>

<script lang="ts">
export default {
  layout: (h, page) => {
    return h(AppLayout, () => h(AppsLayout, () => page))
  },
  props: {
    app: Object
  }
}
</script>

<style>
.full-width {
  width: 100%
}
</style>
