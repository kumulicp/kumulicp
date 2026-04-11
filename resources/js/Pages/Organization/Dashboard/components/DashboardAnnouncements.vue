<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <va-card class="mb-4">
    <va-card-title>Announcements</va-card-title>
    <va-card-content>
      <va-accordian v-model="basicAccordionValue" :multiple="false">
        <va-collapse
          v-for="(announcement, index) in announcements"
          :key="index"
          class="mb-3 px-3"
          :header="announcement.title"
        >
          <div class="pa-3 left-align-text">
            <div v-html="announcement.content" class="mb-3"></div>
            <div class="row">
              <div class="flex flex-col lg6">
                <div>
                  <va-chip v-for="(app, index) in announcement.apps" :key="index" size="small" outline class="mr-2">{{ app }}</va-chip>
                </div>
              </div>
              <div class="va-text-right flex flex-col lg6"><Link :href="announcement.link">{{ t('cards.link.readFullAnnouncement') }}</Link></div>
            </div>
          </div>
        </va-collapse>
      </va-accordian>
    </va-card-content>
  </va-card>
</template>

<script lang="ts">
export default {
  props: {
    announcements: Object
  },
  data () {
    return {
      basicAccordionValue: [true, false]
    }
  }
}
</script>

<style scoped>
  .va-collapse {
    width: 100%;
    background-color: var(--va-background-element);
    border-radius: 10px;
  }

  .left-align-text {
    text-align: left;
  }
</style>
