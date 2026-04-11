<script setup>
import VuesticLogo from '../components/VuesticLogo.vue'
import { Link, usePage } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { useToast } from 'vuestic-ui'

const { t } = useI18n()
</script>
<template>
  <div class="auth-layout row align-content-center">
    <div class="flex xs12 pa-3 justify-center">
      <Link class="py-5 justify-center d-flex" href="/">
        <vuestic-logo height="32" />
      </Link>
    </div>

    <div class="flex xs12 pa-3">
      <div class="d-flex justify-center">
        <va-card class="auth-layout__card">
          <va-card-content>
            <va-tabs v-model="selectTabIndex" center>
              <template #tabs>
                <Link id="login" href="/login"><va-tab key="/login" name="/login">{{ t('auth.login') }}</va-tab></Link>
                <Link id="register" href="/register"><va-tab key="/register" name="/register">{{ t('auth.sign_up') }}</va-tab></Link>
              </template>
            </va-tabs>

            <va-separator />

            <div class="pa-3">
              <slot></slot>
            </div>
          </va-card-content>
        </va-card>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  name: 'AuthLayout',
  components: { VuesticLogo },
  data () {
    const pathname = (new URL(window.location.href)).pathname

    return {
      selectTabIndex: pathname
    }
  },
  computed: {
    tabIndex () {
      const pathname = (new URL(window.location.href)).pathname
      this.selectTabIndex = pathname

      return ''
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
