<script setup>
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
          <p v-else class="mb-3">{{ description }}</p>
          <Link href="/">
            <va-button>
                Go Home
            </va-button>
          </Link>
        </div>
      </va-card-content>
    </va-card>
  </div>
</template>

<script>
export default {
  props: {
    status: Number,
    message: String
  },
  computed: {
    title () {
      return {
        503: '503: Service Unavailable',
        500: '500: Server Error',
        404: '404: Page Not Found',
        403: '403: Forbidden'
      }[this.status]
    },
    description () {
      return {
        503: 'Sorry, we are doing some maintenance. Please check back soon.',
        500: 'Whoops, something went wrong on our servers.',
        404: 'Sorry, the page you are looking for could not be found.',
        403: 'Sorry, you are forbidden from accessing this page.'
      }[this.status]
    }
  }
}
</script>
