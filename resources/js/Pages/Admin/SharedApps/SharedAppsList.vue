<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { Link, useForm, router } from '@inertiajs/vue3'

</script>
<template>
  <Head>
    <title>{{ $t('admin.sharedApps.apps') }} - Control Panel</title>
  </Head>
  <va-card class="mb-4">
    <va-card-title>{{ $t('admin.sharedApps.sharedApps') }}</va-card-title>
    <va-card-content v-if="enabled">
      <div class="row justify-center">
        <va-button id="createApp" class="" @click="showAddApp = !showAddApp">{{ $t('admin.sharedApps.addApp') }}</va-button>
        <va-modal v-model="showAddApp" no-outside-dismiss no-padding size="small" class="p-0">
          <template #content="{ ok }">
            <form @submit.prevent="form.post('/admin/service/shared-apps')">
              <va-card-title class="m-0"> {{ $t('admin.sharedApps.addApp') }} </va-card-title>
              <va-card-content class="m-0">
                <va-select v-model="form.app"
                  :label="$t('admin.sharedApps.availableApps')"
                  :options="available_apps"
                  id="app"
                  value-by="id"
                  text-by="name"
                  class="mb-3"
                  immediateValidation
                  :error="$page.props.errors.app"
                  :error-messages="$page.props.errors.app"
                />
                <va-input v-model="form.label"
                  id="label"
                  required-mark
                  immediateValidation
                  :label="$t('admin.sharedApps.label')"
                  class="mb-3"
                  :error="$page.props.errors.label"
                  :error-messages="$page.props.errors.label" />
                <va-checkbox v-model="form.activate"
                  id="activate"
                  required-mark
                  immediateValidation
                  :label="$t('admin.sharedApps.activate')"
                  class="mb-3"
                  :error="$page.props.errors.activate"
                  :error-messages="$page.props.errors.activate" />
                <template v-if="form.activate">
                  <va-select v-model="form.web_server"
                    id="webServer"
                    required-mark
                    immediateValidation
                    :label="$t('admin.plans.webServer')"
                    class="mb-3"
                    value-by="value"
                    text-by="text"
                    :options="web_servers"
                    :error="$page.props.errors.web_server"
                    :error-messages="$page.props.errors.web_server" />
                  <va-select v-model="form.database_server"
                    id="databaseServer"
                    immediateValidation
                    :label="$t('admin.plans.databaseServer')"
                    class="mb-3"
                    clearable
                    value-by="value"
                    text-by="text"
                    :options="database_servers"
                    :error="$page.props.errors.database_server"
                    :error-messages="$page.props.errors.database_server" />
                  <va-select v-model="form.sso_server"
                    id="ssoServer"
                    immediateValidation
                    :label="$t('admin.plans.ssoServer')"
                    class="mb-3"
                    clearable
                    value-by="value"
                    text-by="text"
                    :options="sso_servers"
                    :error="$page.props.errors.sso_server"
                    :error-messages="$page.props.errors.sso_server" />
                </template>
              </va-card-content>
              <va-card-actions align="right" class="">
                <va-button color="textInverted" :disabled="form.processing" @click="ok">{{ $t('common.cancel') }}</va-button>
                <va-button type="submit" :disabled="form.processing" id="submit" class="mr-2 mb-2">{{ $t('common.submit') }}</va-button>
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
            <th>{{ $t('admin.sharedApps.name') }}</th>
            <th>{{ $t('admin.sharedApps.status') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(app, index) in apps" :key="index">
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
          <h2 class="va-h2 mb-3 sm12" style="color: var(--va-list-item-label-caption-color)">{{ $t('admin.sharedApps.setupPrompt') }}</h2>
        </div>
      </div>
      <div class="row">
        <div class="flex lg12 va-text-center mb-1">
          <Link href="/admin/service/shared-apps/activate"><va-button>{{ $t('admin.sharedApps.enableSharedApps') }}</va-button></Link>
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
    web_servers: Array,
    database_servers: Array,
    sso_servers: Array,
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
        label: '',
        activate: false,
        web_server: null,
        database_server: null,
        sso_server: null
      })
    }
  },
  watch: {
    'form.activate' (activate) {
      if (!activate) {
        this.form.web_server = null
        this.form.database_server = null
        this.form.sso_server = null
      }
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
