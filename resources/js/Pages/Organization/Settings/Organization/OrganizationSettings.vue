<script setup lang="ts">
import CountryDropdown from '@/components/FormInputs/CountryDropdown.vue'
import StateDropdown from '@/components/FormInputs/StateDropdown.vue'
import { useForm } from '@inertiajs/vue3'
import { ref } from 'vue'
import { useInputMask, createRegexMask } from 'vuestic-ui'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const orgPhoneNumber = ref()
useInputMask(createRegexMask(/(\+\d \(\d{3}\)|\d{3}) (\d){3}-(\d){4}/), orgPhoneNumber)

const phoneNumber = ref()
useInputMask(createRegexMask(/(\+\d \(\d{3}\)|\d{3}) (\d){3}-(\d){4}/), phoneNumber)
</script>
<template>
  <Head>
    <title>Organization - Control Panel</title>
  </Head>
  <va-card class="mb-4">
    <va-card-title>Settings</va-card-title>
    <va-card-content class="m-0">
      <form @submit.prevent="form.put('/settings/organization')">
      <h6 class="va-h6 mb-2">About</h6>
      <div class="row">
        <div class="flex flex-col xs12 lg6 mb-2">
          <va-input v-model="form.name"
            id="name"
            label="Organization Name"
            immediateValidation
            :error="$page.props.errors.name"
            :error-messages="$page.props.errors.name"
            />
        </div>
        <div class="flex flex-col xs12 lg6 mb-2">
        </div>
      </div>
      <div class="row">
        <div class="flex flex-col xs12 lg6 mb-2">
          <va-input v-model="form.org_email"
            label="Organization Email"
            id="orgEmail"
            immediateValidation
            :error="$page.props.errors.org_email"
            :error-messages="$page.props.errors.org_email"
            />
        </div>
        <div class="flex flex-col xs12 lg6 mb-2">
            <va-input v-model="form.org_phone_number"
              label="Organization Phone Number"
              id="orgPhoneNumber"
              immediateValidation
              ref="orgPhoneNumber"
              placeholder="### ### ####"
              :mask="{ blocks: [3, 3, 4] }"
              :error="$page.props.errors.org_phone_number"
              :error-messages="$page.props.errors.org_phone_number"
              />
        </div>
      </div>
      <h6 class="va-h6 my-3">Billing Address</h6>
      <div class="row">
        <div class="flex flex-col xs12 lg6 mb-2">
          <va-input v-model="form.street"
            label="Street"
            id="street"
            immediateValidation
            :error="$page.props.errors.street"
            :error-messages="$page.props.errors.street"
            />
        </div>
        <div class="flex flex-col xs12 lg6 mb-2">
          <va-input v-model="form.zipcode"
            label="Zip/Postal code"
            id="zipcode"
            immediateValidation
            :error="$page.props.errors.zipcode"
            :error-messages="$page.props.errors.zipcode"
            />
        </div>
      </div>
      <div class="row">
        <div class="flex flex-col xs12 lg6 mb-2">
          <va-input v-model="form.city"
            label="City"
            id="city"
            immediateValidation
            :error="$page.props.errors.city"
            :error-messages="$page.props.errors.city"
            />
        </div>
        <div class="flex flex-col xs12 lg6 mb-2">
          <state-dropdown label="State\Province" class="va-input" id="province" :country="form.country" v-model:state="form.state" />
        </div>
      </div>
      <div class="row">
        <div class="flex flex-col xs12 lg6 mb-2">
          <country-dropdown label="Country" required class="va-input" id="country" v-model:country="form.country" />
        </div>
        <div class="flex flex-col xs12 lg6 mb-2">
        </div>
      </div>
      <h6 class="va-h6 my-3">Billing Contact Info</h6>
      <div class="row">
        <div class="flex flex-col xs12 lg6 mb-2">
          <va-input v-model="form.user_first_name"
            label="First Name"
            id="user_first_name"
            immediateValidation
            :error="$page.props.errors.user_first_name"
            :error-messages="$page.props.errors.user_first_name"
            />
        </div>
        <div class="flex flex-col xs12 lg6 mb-2">
          <va-input v-model="form.user_last_name"
            label="Last Name"
            id="user_last_name"
            immediateValidation
            :error="$page.props.errors.user_last_name"
            :error-messages="$page.props.errors.user_last_name"
            />
        </div>
      </div>
      <div class="row">
        <div class="flex flex-col xs12 lg6 mb-2">
          <va-input v-model="form.user_phone_number"
            label="Phone Number"
            id="user_phone_number"
            immediateValidation
            ref="phoneNumber"
            :error="$page.props.errors.user_phone_number"
            :error-messages="$page.props.errors.user_phone_number"
            />
        </div>
        <div class="flex flex-col xs12 lg6 mb-2">
          <va-input v-model="form.user_email"
            label="Email"
            id="user_email"
            immediateValidation
            :error="$page.props.errors.user_email"
            :error-messages="$page.props.errors.user_email"
            />
        </div>
      </div>
      <div class="row justify">
        <div class="flex flex-col">
          <va-button type="submit" id="submit" class="mt-3">Update</va-button>
        </div>
      </div>
    </form>
    </va-card-content>
  </va-card>
</template>
<script lang="ts">
export default {
  props: {
    org: Object,
    users: Object,
    errors: Object
  },
  data () {
    return {
      contact_search: false,
      form: useForm({
        _token: this.$page.props.csrf_token,
        name: this.org.name,
        description: this.org.description,
        org_email: this.org.email,
        org_phone_number: this.org.phone_number,
        street: this.org.street,
        zipcode: this.org.zipcode,
        city: this.org.city,
        state: this.org.state,
        country: this.org.country,
        user_id: this.org.main_contact.id,
        user_email: this.org.main_contact.email,
        user_phone_number: this.org.main_contact.phone_number,
        user_first_name: this.org.main_contact.first_name,
        user_last_name: this.org.main_contact.last_name
      })
    }
  },
  computed: {
    contact_url () {
      return '/users/' + this.form.user_id + '/edit'
    },
    contact_errors () {
      return [this.errors.user_phone_number]
    },
    has_contact_errors () {
      return (this.errors.user_id || this.errors.user_email || this.errors.user_phone_number || this.errors.first_name || this.errors.user_last_name)
    }
  }
}
</script>

<style lang="scss">
  .row-equal .flex {
    .va-card {
      height: 100%;
    }
  }

  .red {
    color: red;
  }

  .dashboard {
    .va-card {
      margin-bottom: 0 !important;

      &__title {
        display: flex;
        justify-content: space-between;
      }
    }
  }
</style>
