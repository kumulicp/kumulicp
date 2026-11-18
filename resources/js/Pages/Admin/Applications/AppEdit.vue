<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import AppsLayout from './AppsLayout.vue'
import AdminSettings from '@/components/AdminSettings.vue'
import TinymceEditor from '@/components/FormInputs/TinymceEditor.vue'
import ScreenshotGallery from '@/components/ScreenshotGallery.vue'
import AppReadiness from '@/components/App/AppReadiness.vue'
import { Link, useForm } from '@inertiajs/vue3'
</script>
<template>
  <Head>
    <title>{{ $t('admin.apps.editPageTitle') }} - Control Panel</title>
  </Head>
  <div class="row justify-center mb-3">
    <va-chip id="app-status" :color="app.enabled ? 'success' : 'danger'">{{ app.status_label }}</va-chip>
  </div>
  <div v-if="discover_url" class="row justify-center mb-3">
    <Link id="view-in-discover" :href="discover_url">{{ $t('admin.apps.viewInDiscover') }}</Link>
  </div>
  <app-readiness :readiness="readiness" />
  <form @submit.prevent="form.post('/admin/apps/'+app.slug)">
    <AdminSettings>
      <template #name>{{ $t('admin.apps.aboutApp') }}</template>
      <template #settings>
        <va-input v-model="form.name"
          :label="$t('admin.apps.name')"
          :messages="$t('admin.apps.nameMessage')"
          class="my-2"
          id="name"
          required-mark
          immediateValidation
          :error="$page.props.errors.name"
          :error-messages="$page.props.errors.name"
          />
        <va-input v-model="form.category"
          :label="$t('admin.apps.category')"
          :messages="$t('admin.apps.categoryMessage')"
          id="category"
          class="my-2"
          required-mark
          immediateValidation
          :error="$page.props.errors.category"
          :error-messages="$page.props.errors.category"
          />
        <va-select
          v-model="form.tags"
          :label="$t('admin.apps.tags')"
          :messages="$t('admin.apps.tagsMessage')"
          :options="tag_options"
          id="tags"
          class="my-2"
          multiple
          searchable
          allow-create="unique"
          :error="!!$page.props.errors.tags"
          :error-messages="$page.props.errors.tags"
          />
        <VaFileUpload
          v-model="form.image"
          :label="$t('admin.apps.image')"
          :messages="$t('admin.apps.imageMessage')"
          immediateValidation
          type="single"
          class="my-2"
          dropzone
          file-types="image/*"
          :error="$page.props.errors.image"
          :error-messages="$page.props.errors.image"
        />
        <va-textarea
          v-model="form.short_description"
          :label="$t('admin.apps.shortDescription')"
          :messages="$t('admin.apps.shortDescriptionMessage')"
          immediateValidation
          id="shortDescription"
          class="my-2 full-width"
          min-rows="5"
          max-length="125"
          counter
          autosize
          :error="$page.props.errors.short_description"
          :error-messages="$page.props.errors.short_description"
          />
      </template>
    </AdminSettings>
    <h4 class="va-h4">{{ $t('admin.apps.description') }}</h4>
    <div>
      {{ errors.description }}
    </div>
    <div class="mb-3">
      <tinymce-editor v-model:htmlContent="form.description" />
    </div>
    <h4 class="va-h4">{{ $t('admin.apps.screenshots') }}</h4>
    <p class="va-list-item-label--caption mb-2">{{ $t('admin.apps.screenshotsMessage') }}</p>
    <div class="mb-3">
      <screenshot-gallery
        :screenshots="visibleScreenshots"
        editable
        @remove="removeScreenshot"
      />
      <VaFileUpload
        v-model="form.screenshots"
        type="multiple"
        dropzone
        file-types="image/*"
        class="my-2"
        :error="!!$page.props.errors['screenshots.0']"
        :error-messages="$page.props.errors['screenshots.0']"
      />
    </div>
    <va-button type="submit" id="submit" class="mr-2 mb-2" :disabled="form.processing">{{ $t('common.update') }}</va-button>
  </form>
</template>

<script lang="ts">
export default {
  layout: (h, page) => {
    return h(AppLayout, () => h(AppsLayout, () => page))
  },
  props: {
    app: Object,
    discover_url: String,
    readiness: Object,
    tag_options: Array,
    errors: Object
  },
  data () {
    return {
      form: useForm({
        id: this.app.id,
        name: this.app.name,
        category: this.app.category,
        tags: this.app.tags ?? [],
        image: this.app.image,
        short_description: this.app.short_description,
        description: this.app.description,
        screenshots: [],
        remove_screenshots: []
      })
    }
  },
  computed: {
    visibleScreenshots () {
      return this.app.screenshots.filter((screenshot) => !this.form.remove_screenshots.includes(screenshot.id))
    }
  },
  methods: {
    removeScreenshot (id) {
      this.form.remove_screenshots.push(id)
    }
  }
}
</script>
