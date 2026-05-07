<script setup>
import BlankLayout from '@/layouts/BlankLayout.vue'
import VuesticLogo from '../../components/VuesticLogo.vue'
import { Head } from '@inertiajs/vue3'
</script>

<template>
  <Head>
    <title>{{ $t('auth.orgRegisterPendingTitle') }} - Control Panel</title>
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
          <va-card-title>{{ $t('auth.orgRegisterPendingHeading') }}</va-card-title>
          <va-card-content>
            <p class="mb-3">
              {{ $t('auth.orgRegisterPendingLine1', { org: organization.name }) }}
            </p>
            <p class="mb-3">
              {{ $t('auth.orgRegisterPendingLine2') }}
            </p>
            <div class="d-flex justify-center mt-3">
              <va-button
                color="secondary"
                :href="'/public/org/' + organization.slug + '/register'"
              >
                {{ $t('auth.orgRegisterPendingTryAgain') }}
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
    organization: Object,
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
