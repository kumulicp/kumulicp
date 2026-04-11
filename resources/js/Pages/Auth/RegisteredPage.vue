<script setup lang="ts">
import AuthLayout from '@/layouts/AuthLayout.vue'
import { Head } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <Head>
    <title>Registered! - Control Panel</title>
  </Head>
  <p>Congraduations on registering your account with us!</p>
  <p>Please check your email to verify your account in order to complete your registration.</p>
</template>

<script lang="ts">
export default {
  layout: (h, page) => h(AuthLayout, [page])
}
</script>
