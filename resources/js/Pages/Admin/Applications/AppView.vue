<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import AppsLayout from './AppsLayout.vue'
import ScreenshotGallery from '@/components/ScreenshotGallery.vue'

</script>
<template>
  <Head>
    <title>{{ $t('admin.apps.viewApp') }} - Control Panel</title>
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
    <div class="row">
      <div class="flex flex-col xs12">
        <div id="description" v-html="app.description"></div>
        <screenshot-gallery :screenshots="app.screenshots" class="mt-3" />
      </div>
    </div>
    <div class="row">
      <div class="flex flex-col xs12">
        <h3 class="va-h6 mb-2">{{ $t('admin.applications.compatibility.compatibility') }}</h3>
        <div class="compatibility-list">
          <va-popover
            v-for="item in app.compatibility"
            :key="item.key"
            :message="item.description"
          >
            <va-chip
              size="small"
              :color="item.available ? 'success' : 'secondary'"
              :outline="!item.available"
              class="mr-2 mb-2"
            >
              <va-icon
                v-if="item.available"
                name="fa-check"
                size="small"
                class="mr-1"
              />
              {{ item.label }}
            </va-chip>
          </va-popover>
        </div>
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

.compatibility-list {
  display: flex;
  flex-wrap: wrap;
}
</style>
