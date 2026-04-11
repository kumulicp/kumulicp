<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import DomainsLayout from './DomainsLayout.vue'
import { Link, useForm, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>

<template>
  <Head>
    <title>TLDs - Control Panel</title>
  </Head>
  <div class="row justify-center">
    <va-button @click="showAddTldModal = ! showAddTldModal">Add TLD</va-button><Link :href="'/admin/service/domains/tlds/refresh'" class="ml-2"><va-button>Refresh List</va-button></Link>
  </div>
  <va-modal v-model="showAddTldModal" no-outside-dismiss no-padding size="small" class="p-0">
    <template #content="{ ok }">
      <form @submit.prevent="form.post('/admin/service/domains/tlds')">
        <va-card-title class="m-0"> Add TLD </va-card-title>
        <va-card-content class="m-0">
          <va-input v-model="form.tld"
            immediateValidation
            id="tld"
            required-mark
            label="TLD"
            class="mb-3"
            :error="$page.props.errors.tld"
            :error-messages="$page.props.errors.tld" />
        </va-card-content>
        <va-card-actions align="right" class="">
          <va-button color="textInverted" :disabled="form.processing" @click="ok">Cancel</va-button>
          <va-button type="submit" :disabled="form.processing" id="submit" class="mr-2 mb-2">Submit</va-button>
        </va-card-actions>
      </form>
    </template>
  </va-modal>
  <table class="va-table va-table--hoverable mt-3">
    <thead>
      <tr>
        <th>TLD</th>
        <th>Standard Price</th>
        <th>Registration Allowed?</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <tr v-for="(tld, index) in tlds" :key="index">
        <td>
          <Link :href="'/admin/service/domains/tlds/'+tld.id">{{ tld.name }}</Link>
        </td>
        <td>
          {{ tld.standard_price }}
        </td>
        <td>
          {{ tld.registration_allowed }}
        </td>
        <td class="va-text-center">
          <va-icon name="entypo-cancel" color="danger" class="clickable-icon"
            @click="showRemoveTldModal(tld)" />
        </td>
      </tr>
    </tbody>
  </table>
  <va-pagination v-if="meta.total > pageSize" class="mt-3 mb-3 justify-center" v-model="curPageValue" :pages="pages" input @update:modelValue="changePage" />
  <va-modal v-model="showRemoveTld" hide-default-actions :title="'Remove ' + removeTld.name + '?'"
    :message="'Are you sure you want to remove '+ removeTld.name +'? This action is permanent.'">
    <template #footer="{ cancel }">
      <va-button color="backgroundSecondary" @click="cancel">
        Cancel
      </va-button>
      <va-button color="danger"
        @click="remove.delete('/admin/service/domains/tlds/' + removeTld.id); showRemoveTld = !showRemoveTld">Delete</va-button>
    </template>
  </va-modal>
</template>

<script lang="ts">
export default {
  layout: (h, page) => {
    return h(AppLayout, () => h(DomainsLayout, () => page))
  },
  props: {
    tlds: Object,
    meta: Object
  },
  data () {
    return {
      curPageValue: this.meta.page,
      pages: this.meta.pages,
      pageSize: 20,
      removeTld: '',
      showRemoveTld: false,
      showAddTldModal: false,
      remove: useForm({}),
      form: useForm({
        tld: ''
      })
    }
  },
  methods: {
    showRemoveTldModal (tld) {
      this.showRemoveTld = true
      this.removeTld = tld
    },
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
