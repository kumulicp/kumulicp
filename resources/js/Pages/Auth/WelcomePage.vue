<script setup>
import BlankLayout from '@/layouts/BlankLayout.vue'
import VuesticLogo from '../../components/VuesticLogo.vue'
import { Head, Link } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>

<template>
  <Head>
    <title>{{ t('auth.welcomeTitle') }} - Control Panel</title>
  </Head>
  <div class="auth-layout row align-content-center">
    <div class="flex xs12 pa-3 justify-center">
      <Link class="py-5 justify-center d-flex" href="/">
        <vuestic-logo height="32" />
      </Link>
    </div>

    <div class="flex xs12 pa-3">
      <div class="d-flex justify-center">
        <va-card class="auth-layout__card">
          <va-card-title>{{ t('auth.welcomeGreeting') }}</va-card-title>
          <va-card-content>
            <h5 class="va-h5">{{ user.name }},</h5>
            <p>{{ t('auth.passwordUpdated') }}</p>
            <p class="va-p mb-3">{{ t('auth.oneStopShop') }}</p>
            <p class="va-p mb-3">
              <template v-if="user.apps.length">{{ t('auth.accessGivenSingle') }}</template>
              <template v-else>{{ t('auth.accessGivenMultiple') }}</template>
            </p>

            <va-list>
              <template v-if="user.can.admin">
                <va-list-item
                  class="list__item py-2"
                >
                  <va-list-item-section avatar>
                    <va-avatar color="backgroundSecondary">
                      <img src="../../components/icons/KumuliLogo.png" />
                    </va-avatar>
                  </va-list-item-section>

                  <va-list-item-section>
                    <va-list-item-label>
                      {{ t('auth.controlPanelLabel') }}
                    </va-list-item-label>

                    <va-list-item-label caption :lines="3">
                      {{ t('auth.controlPanelDescription') }}
                    </va-list-item-label>
                  </va-list-item-section>

                  <va-list-item-section icon>
                    <Link href="/">{{ t('auth.login') }}</Link>
                  </va-list-item-section>
                </va-list-item>
              </template>
              <va-list-label>
                {{ t('auth.yourApps') }}
              </va-list-label>

              <template v-if="user.apps.length > 0">
                <va-list-item
                  class="list__item py-2"
                  v-for="(app, index) in user.apps"
                  :key="index"
                >
                  <va-list-item-section avatar>
                    <va-avatar color="backgroundSecondary">
                      <img :src="'/images/'+app.slug+'.png'">
                    </va-avatar>
                  </va-list-item-section>

                  <va-list-item-section>
                    <va-list-item-label>
                      {{ app.name }}
                    </va-list-item-label>

                    <va-list-item-label caption :lines="2">
                      {{ app.description }}
                    </va-list-item-label>
                  </va-list-item-section>

                  <va-list-item-section icon>
                    <a :href="app.address" target="_blank">{{ t('auth.login') }}</a>
                  </va-list-item-section>
                </va-list-item>
              </template>
              <template v-else>
                {{ t('auth.noAppsAccess') }}
              </template>
            </va-list>
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
    user: Object,
    errors: Object
  },
  data () {
    return {
    }
  }
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
