<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <Head>
    <title>Edit TLD - Control Panel</title>
  </Head>
  <va-card class="mb-4">
    <va-card-title>Edit .{{ tld.name }} TLD</va-card-title>
      <va-card-content>
      <form @submit.prevent="form.put('/admin/service/domains/tlds/'+tld.id)">
        <va-list>
          <va-list-item class="py-3">
            <va-list-item-section label>
              <va-list-item-label>
                <h5>Custom Price</h5>
              </va-list-item-label>
            </va-list-item-section>
            <va-list-item-section>
              <va-list-item-label>
                <va-input v-model="form.standard_price"
                  type="number"
                  min="0"
                  step="0.01"
                  messages="Override the registrars pricing."
                  :error="$page.props.errors.standard_price"
                  :error-messages="$page.props.errors.standard_price"
                >
                  <template #prependInner>
                    $
                  </template>
                </va-input>
              </va-list-item-label>
            </va-list-item-section>
          </va-list-item>
          <va-list-separator class="my-1" fit />
          <va-list-item class="py-3">
            <va-list-item-section label>
              <va-list-item-label>
                <h5>Registration Allowed</h5>
              </va-list-item-label>
            </va-list-item-section>
            <va-list-item-section>
              <va-list-item-label>
                <va-checkbox v-model="form.registration_allowed"
                  :error="$page.props.errors.registration_allowed"
                  :error-messages="$page.props.errors.registration_allowed"
                  />
              </va-list-item-label>
            </va-list-item-section>
          </va-list-item>
        </va-list>
        <va-button type="submit" class="mr-2 mb-2" :disabled="form.processing">Update</va-button>
      </form>
    </va-card-content>
  </va-card>
</template>

<script lang="ts">
export default {
  layout: (h, page) => h(AppLayout, [page]),
  props: {
    tld: Object,
    errors: Object
  },
  data () {
    return {
      form: useForm({
        standard_price: this.tld.standard_price,
        registration_allowed: this.tld.registration_allowed
      })
    }
  }
}
</script>

<style>
</style>
