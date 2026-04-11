<script setup>
import AppLayout from '@/layouts/AppLayout.vue'
import { Link, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>

<template>
  <Head>
    <title>Announcements - Control Panel</title>
  </Head>
  <div class="announcements-list">
    <div class="row">
      <div class="flex flex-col xs12 lg12">
        <va-card class="mb-4">
          <va-card-title>{{ t('admin.announcement.title') }}</va-card-title>
          <va-card-content>
            <div class="row justify-center">
              <va-button id="addAnnouncement" @click="showAddAnnouncement = !showAddAnnouncement">{{ t('admin.announcement.addAnnouncement') }}</va-button>
            </div>
              <va-modal v-model="showAddAnnouncement"
                hide-default-actions
                no-padding
                class="p-0"
                >
                <template #content="{ ok }">
                  <va-card-title class="m-0">{{ t('admin.announcement.addAnnouncement') }}</va-card-title>
                  <form @submit.prevent="form.post('/admin/service/announcements')">
                    <va-card-content class="m-0 p-0">
                      <va-input v-model="form.title"
                        id="title"
                        required-mark
                        immediateValidation
                        label="Title"
                        :error="$page.props.errors.title"
                        :error-messages="$page.props.errors.title"
                      />
                    </va-card-content>
                    <va-card-actions align="right" class="">
                      <va-button color="textInverted" :disabled="form.processing" @click="ok">{{ t('modal.cancel') }}</va-button>
                      <va-button type="submit" id="submit" class="mr-2 mb-2" :disabled="form.processing">{{ t('form.submit') }}</va-button>
                    </va-card-actions>
                  </form>
                </template>
              </va-modal>
              <table class="va-table va-table--hoverable mt-3">
                <thead>
                  <tr>
                    <th style="width: 20%">{{ t('admin.announcement.title') }}</th>
                    <th>{{ t('admin.announcement.summary') }}</th>
                    <th style="width: 10px"></th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(announcement, index) in announcements.slice((curPageValue - 1), (curPageValue - 1 + pageSize))" :key="index">
                    <td>
                      <Link :href="'/admin/service/announcements/'+announcement.id+'/edit'">{{ announcement.title }}</Link>
                    </td>
                    <td>
                      {{ announcement.short_description }}
                    </td>
                    <td class="va-text-center">
                      <va-button color="danger"
                        @click="showRemoveAnnouncementModal(announcement)">
                        {{ t('form.remove') }}
                      </va-button>
                    </td>
                  </tr>
                </tbody>
              </table>
              <va-pagination v-if="announcements.length > pageSize" class="mt-3 mb-3 justify-center" v-model="curPageValue" :total="announcements.length" direction-links
                :page-size="pageSize" />
          </va-card-content>
        </va-card>
        <va-modal v-model="showRemoveAnnouncement" hide-default-actions :title="'Remove ' + removeAnnouncement.title + '?'"
          :message="'Are you sure you want to remove '+ removeAnnouncement.title +'? This action is permanent.'">
          <template #footer="{ cancel }">
            <va-button color="backgroundSecondary" @click="cancel">
              Cancel
            </va-button>
            <va-button color="danger"
              @click="remove.delete('/admin/service/announcements/' + removeAnnouncement.id); showRemoveAnnouncement = !showRemoveAnnouncement">{{ t('modal.delete') }}</va-button>
          </template>
        </va-modal>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  layout: (h, page) => h(AppLayout, [page]),
  props: {
    interfaces: Object,
    announcements: Object,
    errors: Object
  },
  data () {
    return {
      curPageValue: 1,
      pageSize: 10,
      showAddAnnouncement: false,
      showRemoveAnnouncement: false,
      removeAnnouncement: '',
      form: useForm({
        title: ''
      }),
      remove: useForm({})
    }
  },
  methods: {
    showRemoveAnnouncementModal (announcement) {
      this.removeAnnouncement = announcement
      this.showRemoveAnnouncement = !this.showRemoveAnnouncement
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

.announcements-list {
  .va-card {
    margin-bottom: 0 !important;

    &__title {
      display: flex;
      justify-content: space-between;
    }
  }
}

.clickable-icon {
  transition: 0.3s;

  &:hover {
    opacity: 0.25;
    cursor: pointer;
  }
}
</style>
