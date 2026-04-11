<script setup>
import BlankLayout from '@/layouts/BlankLayout.vue'
import VuesticLogo from '../../components/VuesticLogo.vue'
import { Head, Link } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>

<template>
  <Head>
    <title>Welcome! - Control Panel</title>
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
          <va-card-title>Welcome!</va-card-title>
          <va-card-content>
            <h5 class="va-h5">{{ user.name }},</h5>
            <p>Your password was successfully updated!</p>
            <p class="va-p mb-3">We aim to be a one-stop-shop for all the online apps your organization might need to run smoothly</p>
            <p class="va-p mb-3">You've been given access to the <span v-if="user.apps.length"> app </span><span v-else> apps </span> below. You can use the username you were assigned and password you just set to access any app.</p>

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
                      Control Panel
                    </va-list-item-label>

                    <va-list-item-label caption :lines="3">
                      The Control Panel is the central hub of our product. From here you can manage your apps, subscription, users and user groups and more!
                    </va-list-item-label>
                  </va-list-item-section>

                  <va-list-item-section icon>
                    <Link href="/">Login</Link>
                  </va-list-item-section>
                </va-list-item>
              </template>
              <va-list-label>
                Your Apps
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
                    <a :href="app.address" target="_blank">Login</a>
                  </va-list-item-section>
                </va-list-item>
              </template>
              <template v-else>
                You currently don't have access to any applications. If you believe you should, please contact your organization admins to give you access.
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
