<script setup>
import AppLayout from '@/layouts/AppLayout.vue'
import { useForm } from '@inertiajs/vue3'
import TinymceEditor from '@/components/FormInputs/TinymceEditor.vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <Head>
    <title>Announcement - Control Panel</title>
  </Head>
  <va-card class="mb-4">
    <va-card-title>{{ announcement.title }}</va-card-title>
      <va-card-content>
        <form @submit.prevent="form.put('/admin/service/announcements/'+announcement.id)">
        <va-list>
          <va-list-item class="py-3">
            <va-list-item-section label>
              <va-list-item-label>
                <h5>{{ t('admin.announcement.title') }}</h5>
              </va-list-item-label>
            </va-list-item-section>
            <va-list-item-section>
              <va-list-item-label>
                <va-input v-model="form.title"
                  id="title"
                  immediateValidation
                  :error="$page.props.errors.title"
                  :error-messages="$page.props.errors.title"
                />
              </va-list-item-label>
            </va-list-item-section>
          </va-list-item>

          <va-list-separator class="my-1" fit />
          <va-list-item class="py-3">
            <va-list-item-section label>
              <va-list-item-label>
                <h5>{{ t('admin.announcement.relevantApps') }}</h5>
              </va-list-item-label>
            </va-list-item-section>
            <va-list-item-section>
              <va-select v-model="form.apps"
                :options="apps"
                id="apps"
                value-by="name"
                text-by="name"
                multiple
                clearable
                immediateValidation
                :error="$page.props.errors.apps"
                :error-messages="$page.props.errors.apps"
              />
            </va-list-item-section>
          </va-list-item>

          <va-list-separator class="my-1" fit />
          <va-list-item class="py-3">
            <va-list-item-section label>
              <va-list-item-label>
                <h5>{{ t('admin.announcement.summary') }}</h5>
              </va-list-item-label>
            </va-list-item-section>
            <va-list-item-section>
              <va-input v-model="form.short_description"
                id="shortDescription"
                immediateValidation
                :error="$page.props.errors.short_description"
                :error-messages="$page.props.errors.short_description"
              />
            </va-list-item-section>
          </va-list-item>

          <va-list-separator class="my-1" fit />
          <va-list-item class="py-3">
            <va-list-item-section label>
              <va-list-item-label>
                <h5>{{ t('admin.announcement.description') }}</h5>
              </va-list-item-label>
            </va-list-item-section>
            <va-list-item-section>
            </va-list-item-section>
          </va-list-item>
        </va-list>
        <div class="mb-3">
          <tinymce-editor v-model:htmlContent="form.description" />
        </div>
        <va-button type="submit"
          id="submit"
          :disabled="form.processing"
          class="mr-2 mb-2"
        >
          {{ t('form.update') }}
        </va-button>
      </form>
    </va-card-content>
  </va-card>
</template>

<script>
export default {
  layout: (h, page) => h(AppLayout, [page]),
  props: {
    apps: Object,
    announcement: Object,
    errors: Object
  },
  data () {
    return {
      form: useForm({
        title: this.announcement.title,
        short_description: this.announcement.short_description,
        description: this.announcement.description,
        apps: this.announcement.apps
      })
    }
  }
}
</script>

<style>
.full-width {
  width: 100%
}
</style>
