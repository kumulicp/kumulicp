<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import OrganizationLayout from './OrganizationLayout.vue'
import { router } from '@inertiajs/vue3'

</script>
<template>
  <Head>
    <title>{{ $t('admin.organizations.logs') }} - Control Panel</title>
  </Head>
  <va-scroll-container
    color="warning"
    horizontal
  >
    <table class="va-table va-table--hoverable mt-3">
      <thead>
        <tr>
          <th>{{ $t('admin.organizations.level') }}</th>
          <th>{{ $t('admin.organizations.message') }}</th>
          <th>{{ $t('admin.organizations.time') }}</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="log in logs" :key="log.name">
          <td>{{ log.level }}</td>
          <td>{{ log.message }}</td>
          <td>{{ log.time }}</td>
        </tr>
      </tbody>
    </table>
  </va-scroll-container>

  <va-pagination v-if="meta.pages > 1" class="mt-3 mb-3 justify-center" v-model="curPageValue" :pages="meta.pages" input @update:modelValue="changePage" />
</template>

<script lang="ts">
export default {
  layout: (h, page) => {
    return h(AppLayout, () => h(OrganizationLayout, () => page))
  },
  props: {
    organization: Object,
    logs: Object,
    meta: Object
  },
  data () {
    return {
      curPageValue: this.meta.page
    }
  },
  watch: {
    // Inertia reuses this component between visits (e.g. the Logs tab), so keep in sync with the server.
    'meta.page' (page) {
      this.curPageValue = page
    }
  },
  methods: {
    changePage (page) {
      if (page === this.meta.page) {
        return
      }
      router.get(location.pathname, { page }, { preserveScroll: true, preserveState: true })
    }
  }
}
</script>

<style lang="scss">
.clickable-icon {
  transition: 0.3s;

  &:hover {
    opacity: 0.25;
    cursor: pointer;
  }
}
</style>
