<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import ServerLayout from './ServerLayout.vue'
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <Head>
    <title>Server - Values - Control Panel</title>
  </Head>
  <form @submit.prevent="form.put('/admin/server/servers/'+server.id)">
    <v-ace-editor
        v-model:value="form.chart"
        lang="yaml"
        theme="chrome"
        style="height: 500px" />
    <va-button type="submit"
    id="submit"
    :disabled="form.processing"
    class="mr-2 mb-2"
    >
    Update
    </va-button>
</form>
</template>

<script lang="ts">
export default {
  layout: (h, page) => {
    return h(AppLayout, () => h(ServerLayout, () => page))
  },
  props: {
    server: Object,
    configs: Object,
    errors: Object
  },
  data () {
    return {
      form: useForm({
        chart: this.server.chart
      })
    }
  }
}
</script>

<style>
.full-width {
  width: 100%
}
</style>
