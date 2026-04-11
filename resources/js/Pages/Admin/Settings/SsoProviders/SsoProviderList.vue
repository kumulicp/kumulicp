<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import SettingsLayout from '../SettingsLayout.vue'
import { Link, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

</script>
<template>
  <Head>
    <title>SSO Providers - Control Panel</title>
  </Head>
  <div class="row justify-center">
    <va-button id="createProvider" class="" @click="showAddProvider = !showAddProvider">Add SSO Provider</va-button>
    <va-modal v-model="showAddProvider" no-outside-dismiss no-padding size="small" class="p-0">
      <template #content="{ ok }">
        <form @submit.prevent="form.post('/admin/settings/sso-providers')">
          <va-card-title class="m-0"> Add SSO Provider </va-card-title>
          <va-card-content class="m-0">
            <va-input v-model="form.name"
              immediateValidation
              id="name"
              required-mark
              label="Name"
              class="mb-3"
              messages="Must be lowercase and dashes only"
              :error="$page.props.errors.name"
              :error-messages="$page.props.errors.name" />
            <va-input v-model="form.label"
              immediateValidation
              id="label"
              required-mark
              label="Label"
              class="mb-3"
              messages="Public facing label"
              :error="$page.props.errors.label"
              :error-messages="$page.props.errors.label" />
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
          <th style="width:20rem">Name</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="(provider, i) in providers" :key="i" class="table-row">
          <td>
            <Link :href="'/admin/settings/sso-providers/'+provider.id">{{ provider.label }}</Link>
          </td>
        </tr>
      </tbody>
    </table>
  </va-scroll-container>

  <va-pagination v-if="providers.length > pageSize" class="mt-3 mb-3 justify-center" v-model="curPageValue" :pages="pages" input />
  <va-modal v-model="showRemoveProvider" hide-default-actions :title="'Remove ' + removeProvider + '?'"
    :message="'Are you sure you want to remove ' + removeProvider + '? This action is permanent.'">
    <template #footer>
      <va-button color="backgroundSecondary" @click="showRemoveProvider = false">
        Cancel
      </va-button>
      <va-button id="delete" color="danger"
        @click="remove.delete('/admin/settings/sso-providers/' + removeProvider); showRemoveProvider = !showRemoveProvider">{{ $t('modal.delete') }}</va-button>
    </template>
  </va-modal>
</template>

<script lang="ts">
export default {
  layout: (h, page) => {
    return h(AppLayout, () => h(SettingsLayout, () => page))
  },
  props: {
    providers: Object,
    errors: Object
  },
  data () {
    return {
      curPageValue: 1,
      pages: 1,
      pageSize: 10,
      showAddProvider: false,
      showRemoveProvider: false,
      removeProvider: '',
      form: useForm({
        name: '',
        label: ''
      }),
      remove: useForm({})
    }
  },
  computed: {
    initialListValue () {
      return (this.curPageValue - 1) * this.pageSize
    }
  },
  methods: {
    showRemoveProviderModal (provider) {
      this.removeProvider = provider
      this.showRemoveProvider = !this.showRemoveProvider
    }
  }
}
</script>

<style lang="scss">
.show-on-hover {
  display: none;
}
.table-row {
  height: 55px;
}
.table-row:hover > td .show-on-hover{
  display: inline;
}
</style>
