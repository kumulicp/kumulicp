<script setup>
import AppLayout from '@/layouts/AppLayout.vue'
import { Link } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>

<template>
  <Head>
    <title>Discover Apps - Control Panel</title>
  </Head>
  <div class="discover-apps">
    <div class="row">
      <h3 class="va-h5 mb-4 px-3">App Discovery</h3>
    </div>
    <div class="row">
      <!-- a single app card -->
      <div class="flex xs12 md12 lg6 xl4" v-for="(app, index) in apps" :key="index">
        <va-card class="mb-4 pb-0">
          <div class="flex flex-col h-full py-0 px-0">
          <div class="py-0 px-0 flex-none" style="display: flex; flex-direction: column; align-items: start; flex: none">
            <va-card-title>{{ app.name }}</va-card-title>
          </div>
          <va-card-content class="pt-0 pb-1">
            <div class="flex flex-row xs12 px-0 py-0 h-full">
              <!-- image column -->
              <div class="flex flex-col xs4 px-0 py-0">
                <va-image :src="'/images/'+app.slug+'.png'"
                  fit="scale-down"
                  :ratio="1"
                  fallbackSrc="/images/generic.png"
                  lazy
                />
              </div>
              <!-- text column-->
                <div class="flex flex-col xs8 lg:pl-3 sm:pl-8 pr-0 py-0 items-start">
                    <div class="pb-3" style="flex:none">
                      <h5 class="mb-3">{{ app.category }}</h5>
                      <p>{{ app.description }}</p>
                    </div>
                    <div class="w-full" style="display: grid; flex-direction: row; align-items: end; place-items: end;">
                      <Link :href="'/discover/'+app.slug"><va-button class="px-2">View</va-button></Link>
                    </div>
                  </div>
              </div>
          </va-card-content>
          <div style="display: flex; flex-direction: row; align-items: end; flex:none">
            <va-alert color="success" icon="info" center class="mb-0 flex-none py-2 px-3 w-full">{{ app.count }} <template v-if="app.count === 1">instance</template><template v-else>instances</template> activated</va-alert>
          </div>
        </div>
        </va-card>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  layout: (h, page) => h(AppLayout, [page]),
  props: {
    apps: Object
  }
}
</script>

<style lang="scss"></style>
