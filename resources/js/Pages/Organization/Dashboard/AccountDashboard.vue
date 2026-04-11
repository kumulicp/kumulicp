<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import DashboardTrialInfo from './components/DashboardTrialInfo.vue'
import DashboardInfoBlock from './components/DashboardInfoBlock.vue'
import DashboardFoldersOverview from './components/DashboardFoldersOverview.vue'
import DashboardAnnouncements from './components/DashboardAnnouncements.vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <Head>
    <title>Dashboard - Control Panel</title>
  </Head>
  <div class="dashboard">
    <DashboardTrialInfo v-if="trial_plan" />
    <DashboardInfoBlock class="mb-2" :blocks="info_blocks" />
    <div class="row">
      <div v-if="nextcloud_folders" class="flex flex-col lg6 xs12">
        <DashboardFoldersOverview v-if="nextcloud_folders" :folders="nextcloud_folders" />
      </div>
      <div :class="'flex flex-col xs12 '+announcementsWidth">
        <DashboardAnnouncements :announcements="announcements" />
      </div>
    </div>
  </div>
</template>

<script lang="ts">
export default {
  layout: (h, page) => h(AppLayout, [page]),
  props: {
    announcements: Object,
    info_blocks: Object,
    nextcloud_folders: Object,
    trial_plan: Object
  },
  data () {
    let announcementsWidth = 'lg12'
    if (this.nextcloud_folders) {
      announcementsWidth = 'lg6'
    }

    return {
      announcementsWidth
    }
  }
}
</script>

<style lang="scss">
  .row-equal .flex {
    .va-card {
      height: 100%;
    }
  }

  .dashboard {
    .va-card {
      margin-bottom: 0 !important;
      &__title {
        display: flex;
        justify-content: space-between;
      }
    }
  }
</style>
