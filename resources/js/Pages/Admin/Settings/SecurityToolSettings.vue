<script setup>
import AppLayout from '@/layouts/AppLayout.vue'
import SettingsLayout from './SettingsLayout.vue'
import AdminSettings from '@/components/AdminSettings.vue'
import { useForm } from '@inertiajs/vue3'
</script>

<template>
  <Head>
    <title>{{ $t('settings.securityTools') }} - Control Panel</title>
  </Head>
  <form @submit.prevent="form.put('/admin/settings/security-tools')">
    <AdminSettings>
      <template #name>{{ $t('settings.securityTools') }}</template>
      <template #description>{{ $t('settings.securityToolsDescription') }}</template>
      <template #settings>
        <va-alert color="warning" icon="warning" outline class="mb-3">
          {{ $t('settings.securityToolsWarning') }}
        </va-alert>
        <div v-for="tool in tools" :key="tool.name" class="mb-3">
          <va-input
            v-model="form.images[tool.name]"
            :label="tool.name"
            :placeholder="tool.default_image"
            :messages="[`${$t('settings.securityToolDefaultImage')}: ${tool.default_image}`]"
            :error="$page.props.errors[`images.${tool.name}`]"
            :error-messages="$page.props.errors[`images.${tool.name}`]"
          />
        </div>
      </template>
    </AdminSettings>
    <va-button type="submit" :disabled="form.processing" class="mr-2 my-2">
      {{ $t('common.update') }}
    </va-button>
  </form>
</template>

<script>
export default {
  layout: (h, page) => h(AppLayout, () => h(SettingsLayout, () => page)),
  props: {
    tools: Array,
    errors: Object,
  },
  data () {
    const images = {}
    this.tools.forEach((tool) => {
      images[tool.name] = tool.image ?? ''
    })

    return {
      form: useForm({
        images,
      }),
    }
  },
}
</script>
