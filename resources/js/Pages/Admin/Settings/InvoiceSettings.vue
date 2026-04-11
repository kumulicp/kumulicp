<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import SettingsLayout from './SettingsLayout.vue'
import AdminSettings from '@/components/AdminSettings.vue'
import { useForm } from '@inertiajs/vue3'
import { ref } from 'vue'
import { useInputMask, createRegexMask } from 'vuestic-ui'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const phoneNumber = ref()
useInputMask(createRegexMask(/(\+\d \(\d{3}\)|\d{3}) (\d){3}-(\d){4}/), phoneNumber)
</script>
<template>
  <Head>
    <title>Edit Server Settings - Control Panel</title>
  </Head>
  <form @submit.prevent="form.put('/admin/settings/invoice')">
    <AdminSettings>
        <template #name>Invoice Info</template>
        <template #settings>
          <va-input v-model="form.invoice_vendor_name"
            id="invoiceVendorName"
            class="mb-3"
            :label="t('settings.vendorName')"
            immediateValidation
            mark-required
            :error="$page.props.errors.invoice_vendor_name"
            :error-messages="$page.props.errors.invoice_vendor_name"
          />
          <va-input v-model="form.invoice_vendor_product"
            id="invoiceVendorProduct"
            class="mb-3"
            :label="t('settings.productName')"
            immediateValidation
            :error="$page.props.errors.invoice_vendor_product"
            :error-messages="$page.props.errors.invoice_vendor_product"
          />
          <va-input v-model="form.invoice_vendor_street"
            id="invoiceVendorStreet"
            class="mb-3"
            :label="t('settings.vendorStreet')"
            immediateValidation
            :error="$page.props.errors.invoice_vendor_street"
            :error-messages="$page.props.errors.invoice_vendor_street"
          ></va-input>
          <va-input v-model="form.invoice_vendor_location"
            id="invoiceVendorLocation"
            class="mb-3"
            :label="t('settings.vendorLocation')"
            immediateValidation
            :error="$page.props.errors.invoice_vendor_location"
            :error-messages="$page.props.errors.invoice_vendor_location"
          />
          <va-input v-model="form.invoice_vendor_phone_number"
            id="invoiceVendorPhoneNumber"
            class="mb-3"
            :label="t('settings.vendorPhoneNumber')"
            immediateValidation
            type="tel"
            placeholder="+1 (###) ### ####"
            ref="phoneNumber"
            :error="$page.props.errors.invoice_vendor_phone_number"
            :error-messages="$page.props.errors.invoice_vendor_phone_number"
          />
          <va-input v-model="form.invoice_vendor_email"
            id="invoiceVendorEmail"
            type="email"
            class="mb-3"
            :label="t('settings.vendorEmail')"
            immediateValidation
            :error="$page.props.errors.invoice_vendor_email"
            :error-messages="$page.props.errors.invoice_vendor_email"
          />
          <va-input v-model="form.invoice_vendor_url"
            id="invoiceVendorUrl"
            class="mb-3"
            :label="t('settings.vendorWebside')"
            immediateValidation
            type="url"
            placeholder="https://example.com"
            :error="$page.props.errors.invoice_vendor_url"
            :error-messages="$page.props.errors.invoice_vendor_url"
          />
          <va-input v-model="form.invoice_vendor_vat"
            id="invoiceVendorVat"
            class="mb-3"
            :label="t('settings.vendorVAT')"
            immediateValidation
            :error="$page.props.errors.invoice_vendor_vat"
            :error-messages="$page.props.errors.invoice_vendor_vat"
          />
        </template>
    </AdminSettings>
  <va-button type="submit"
    id="submit"
    :disabled="form.processing"
    class="mr-2 my-2"
  >
    {{ t('form.update') }}
  </va-button>
</form>
</template>

<script lang="ts">
export default {
  layout: (h, page) => {
    return h(AppLayout, () => h(SettingsLayout, () => page))
  },
  props: {
    settings: Object,
    errors: Object
  },
  data () {
    return {
      form: useForm({
        invoice_vendor_name: this.settings.invoice_vendor_name,
        invoice_vendor_product: this.settings.invoice_vendor_product,
        invoice_vendor_street: this.settings.invoice_vendor_street,
        invoice_vendor_location: this.settings.invoice_vendor_location,
        invoice_vendor_phone_number: this.settings.invoice_vendor_phone_number,
        invoice_vendor_email: this.settings.invoice_vendor_email,
        invoice_vendor_url: this.settings.invoice_vendor_url,
        invoice_vendor_vat: this.settings.invoice_vendor_vat
      })
    }
  }
}
</script>

<style></style>
