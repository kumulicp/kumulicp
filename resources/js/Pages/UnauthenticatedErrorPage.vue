<script setup>
import BlankLayout from '@/layouts/BlankLayout.vue'
import { Link } from '@inertiajs/vue3'

</script>
<template>
  <Head>
    <title>{{ title }} - Control Panel</title>
  </Head>
  <div class="row justify-center pt-5">
    <va-card class="flex flex-col lg8">
      <va-card-content class="py-5">
        <div class="va-text-center justify-center">
          <h1 class="va-h1">{{ title }}</h1>
          <p v-if="message && status !== 500" class="mb-3">{{ message }}</p>
          <p v-else>{{ description }}</p>
          <Link href="/">
            <va-button>
                {{ $t('errors.goHome') }}
            </va-button>
          </Link>
        </div>
      </va-card-content>
    </va-card>
  </div>
</template>

<script>
export default {
  layout: (h, page) => h(BlankLayout, [page]),
  props: {
    status: Number,
    message: String
  },
  computed: {
    title () {
      return {
        503: this.$t('errors.503title'),
        500: this.$t('errors.500title'),
        404: this.$t('errors.404title'),
        403: this.$t('errors.403title')
      }[this.status]
    },
    description () {
      return {
        503: this.$t('errors.503description'),
        500: this.$t('errors.500description'),
        404: this.$t('errors.404description'),
        403: this.$t('errors.403description')
      }[this.status]
    }
  }
}
</script>
