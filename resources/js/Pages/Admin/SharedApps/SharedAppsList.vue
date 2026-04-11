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
    <va-card-title>Shared Apps</va-card-title>
    <va-card-content v-if="enabled">
      <div class="row justify-center">
        <va-button id="createApp" class="" @click="showAddApp = !showAddApp">Add App</va-button>
        <va-modal v-model="showAddApp" no-outside-dismiss no-padding size="small" class="p-0">
          <template #content="{ ok }">
            <form @submit.prevent="form.post('/admin/service/shared-apps')">
              <va-card-title class="m-0"> Add App </va-card-title>
              <va-card-content class="m-0">
                <va-select v-model="form.app"
                  label="Available Apps"
                  :options="available_apps"
                  id="app"
                  value-by="id"
                  text-by="name"
                  class="mb-3"
                  immediateValidation
                  @update:modelValue="form.plan = null"
                  :error="$page.props.errors.app"
                  :error-messages="$page.props.errors.app"
                />
                <va-select v-if="form.app" v-model="form.plan"
                  label="Plan"
                  :options="plans[form.app]"
                  id="plan"
                  value-by="id"
                  text-by="name"
                  class="mb-3"
                  immediateValidation
                  :error="$page.props.errors.plan"
                  :error-messages="$page.props.errors.plan"
                />
                <va-input v-model="form.label"
                  id="label"
                  required-mark
                  immediateValidation
                  label="Label"
                  class="mb-3"
                  :error="$page.props.errors.label"
                  :error-messages="$page.props.errors.label" />
                <va-checkbox v-model="form.activate"
                  id="activate"
                  required-mark
                  immediateValidation
                  label="Activate"
                  class="mb-3"
                  :error="$page.props.errors.activate"
                  :error-messages="$page.props.errors.activate" />
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
            <td><Link :href="'/admin/service/shared-apps/'+app.id">{{ app.label }}</Link></td>
            <td>{{ app.status }}</td>
          </tr>
        </tbody>
      </table>
      </va-scroll-container>
      <va-pagination v-if="meta.total > pageSize" class="mt-3 mb-3 justify-center" v-model="curPageValue" :pages="pages" input @update:modelValue="changePage" />
    </va-card-content>
    <va-card-content v-else>
      <div class="row m-5">
        <div class="flex lg12 va-text-center mt-4">
          <va-icon name="fa-user-group" style="color: var(--va-list-item-label-caption-color)"  size="5rem" />
        </div>
      </div>
      <div class="row">
        <div class="flex lg12 va-text-center mb-1">
          <h2 class="va-h2 mb-3 sm12" style="color: var(--va-list-item-label-caption-color)">Shared apps requires some setup. Do you want to enable this?</h2>
        </div>
      </div>
      <div class="row">
        <div class="flex lg12 va-text-center mb-1">
          <Link href="/admin/service/shared-apps/activate"><va-button>Enable Shared Apps</va-button></Link>
        </div>
      </div>
    </va-card-content>
  </va-card>
</template>

<script lang="ts">
export default {
  layout: (h, page) => h(AppLayout, [page]),
  props: {
    enabled: Boolean,
    available_apps: Array,
    plans: Object,
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
        app: null,
        plan: null,
        label: '',
        activate: false
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
