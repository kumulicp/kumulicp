<script setup>
import BlankLayout from '@/layouts/BlankLayout.vue'
import VuesticLogo from '../../components/VuesticLogo.vue'
import { Head } from '@inertiajs/vue3'
</script>

<template>
  <Head>
    <title>{{ $t('auth.setPasswordExpiredTitle') }} - Control Panel</title>
  </Head>
  <div class="auth-layout row align-content-center">
    <div class="flex xs12 pa-3 justify-center">
      <div class="py-5 justify-center d-flex">
        <vuestic-logo height="32" />
      </div>
    </div>

    <div class="flex xs12 pa-3">
      <div class="d-flex justify-center">
        <va-card class="auth-layout__card">
          <va-card-title>{{ $t('auth.setPasswordExpiredHeading') }}</va-card-title>
          <va-card-content>
            <p class="mb-3">
              {{ $t('auth.setPasswordExpiredLine1') }}
            </p>
            <p class="mb-3">
              {{ $t('auth.setPasswordExpiredLine2') }}
            </p>
            <div class="d-flex justify-center mt-3">
              <va-button :href="resetUrl">
                {{ $t('auth.setPasswordExpiredAction') }}
              </va-button>
            </div>
          </va-card-content>
        </va-card>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  layout: (h, page) => h(BlankLayout, [page]),
  props: {
    resetUrl: String,
  },
}
</script>

<style lang="scss">
  .auth-layout {
    min-height: 100vh;
    background-image: linear-gradient(to right, var(--va-background-primary), var(--va-white));

    &__card {
      width: 100%;
      max-width: 600px;
    }
  }
</style>
