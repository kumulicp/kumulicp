<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { Link, useForm, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <Head>
    <title>Apps - Control Panel</title>
  </Head>
  <va-card class="mb-4">
    <va-card-title>Apps</va-card-title>
    <va-card-content>
      <div class="row justify-center">
        <va-button id="createApp" class="" @click="showAddApp = !showAddApp">Add App</va-button>
        <va-modal v-model="showAddApp" no-outside-dismiss no-padding size="small" class="p-0">
          <template #content="{ ok }">
            <form @submit.prevent="form.post('/admin/apps')">
              <va-card-title class="m-0"> Add App </va-card-title>
              <va-card-content class="m-0">
                <va-input v-model="form.name"
                  immediateValidation
                  id="name"
                  required-mark
                  label="Name"
                  class="mb-3"
                  :error="$page.props.errors.name"
                  :error-messages="$page.props.errors.name" />
                <va-input v-model="form.slug"
                  immediateValidation
                  id="slug"
                  required-mark
                  label="Slug"
                  class="mb-3"
                  :error="$page.props.errors.slug"
                  :error-messages="$page.props.errors.slug" />
                <va-input v-model="form.category"
                  id="category"
                  required-mark
                  immediateValidation
                  label="Category"
                  class="mb-3"
                  :error="$page.props.errors.category"
                  :error-messages="$page.props.errors.category" />
                <va-input v-model="form.description"
                  id="description"
                  required-mark
                  immediateValidation
                  label="Description"
                  class="mb-3"
                  :error="$page.props.errors.description"
                  :error-messages="$page.props.errors.description" />
              </va-card-content>
              <va-card-actions align="right" class="">
                <va-button color="textInverted" :disabled="form.processing" @click="ok">Cancel</va-button>
                <va-button type="submit" :disabled="form.processing" id="submit" class="mr-2 mb-2">Submit</va-button>
              </va-card-actions>
            </form>
          </template>
        </va-modal>
      </div>

      <va-scroll-container
        color="primary"
        horizontal
      >
      <table class="va-table va-table--hoverable mt-3">
        <thead>
          <tr>
            <th>Name</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(app, index) in apps" :key="index" style="min-height:300px;">
            <td><Link :href="'/admin/apps/'+app.slug">{{ app.name }}</Link></td>
            <td>{{ app.status }}</td>
          </tr>
        </tbody>
      </table>
      </va-scroll-container>
      <va-pagination v-if="meta.total > pageSize" class="mt-3 mb-3 justify-center" v-model="curPageValue" :pages="pages" input @update:modelValue="changePage" />
    </va-card-content>
  </va-card>
</template>

<script lang="ts">
export default {
  layout: (h, page) => h(AppLayout, [page]),
  props: {
    apps: Object,
    meta: Object,
    errors: Object
  },
  data () {
    return {
      curPageValue: this.meta.page,
      pages: this.meta.pages,
      pageSize: 20,
      showAddApp: false,
      form: useForm({
        name: '',
        slug: '',
        description: '',
        category: ''
      })
    }
  },
  methods: {
    changePage () {
      const url = location.protocol + '//' + location.host + location.pathname
      router.visit(url + '?page=' + this.curPageValue, { method: 'get', preserveScroll: true })
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
