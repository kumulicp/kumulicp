<script setup lang="ts">
import AuthLayout from '@/layouts/AuthLayout.vue'
import { Head } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <Head>
    <title>{{ t('auth.registeredTitle') }} - Control Panel</title>
  </Head>
  <p>{{ t('auth.registeredCongrats') }}</p>
  <p>{{ t('auth.registeredVerifyEmail') }}</p>
</template>

<script lang="ts">
export default {
  layout: (h, page) => h(AuthLayout, [page])
}
</script>
