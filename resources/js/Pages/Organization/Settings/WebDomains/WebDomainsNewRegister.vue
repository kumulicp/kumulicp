<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import CountryDropdown from '@/components/FormInputs/CountryDropdown.vue'
import StateDropdown from '@/components/FormInputs/StateDropdown.vue'
import { useForm } from '@inertiajs/vue3'
import { countries } from '@/data/country_phone_codes.json'

</script>
<template>
  <head>
    <title>{{ domain.name }} {{ $t('organization.webDomains.registration') }} - Control Panel</title>
  </head>
  <va-card class="mb-4">
    <va-card-title>{{ $t('organization.webDomains.registrationInfo') }}</va-card-title>
    <va-card-content>
      <div class="row">
        <div class="flex lg8">
          <form @submit.prevent="form.post('/settings/domains/register/'+domain.name)">
            <h5 class="h5">{{ $t('organization.webDomains.selectedDomainName') }}: <span class="va-text-secondary va-text-bold ml-2">{{ domain.name }}</span></h5>
            <va-alert class="mb-3">
              <template #icon>
                <va-icon name="info" />
              </template>
              <strong>{{ $t('organization.webDomains.reviewInfoBelow') }}</strong>
              {{ $t('organization.webDomains.autoPopulatedInfo') }}</va-alert
            >

            <va-select
              v-model="form.years"
              v-if="!is_premium"
              id="years"
              :options="years"
              immediateValidation
              class="mb-3"
              :label="$t('organization.webDomains.years')"
              :error="!!errors.years"
              :error-messages="$page.props.errors.years"
              :messages="$t('organization.webDomains.yearsMessage')"
            />

            <va-input
              v-model="form.organization_name"
              class="mb-3"
              :label="$t('organization.webDomains.organizationName')"
              immediateValidation
              :error="$page.props.errors.organization_name"
              :error-messages="$page.props.errors.organization_name"
            />

            <va-input
              v-model="form.email_address"
              class="mb-3"
              type="email"
              :label="$t('organization.webDomains.organizationEmail')"
              immediateValidation
              :error="$page.props.errors.email_address"
              :error-messages="$page.props.errors.email_address"
              :messages="$t('organization.webDomains.organizationEmailMessage')"
            />

            <va-input
              v-model="form.first_name"
              class="mb-3"
              :label="$t('organization.webDomains.registrantFirstName')"
              immediateValidation
              :error="$page.props.errors.first_name"
              :error-messages="$page.props.errors.first_name"
            />

            <va-input
              v-model="form.last_name"
              class="mb-3"
              :label="$t('organization.webDomains.registrantLastName')"
              immediateValidation
              :error="$page.props.errors.last_name"
              :error-messages="$page.props.errors.last_name"
            />

            <va-input
              v-model="form.phone"
              class="mb-3"
              placeholder="(###) ### ####"
              :label="$t('organization.webDomains.phoneNumber')"
              immediateValidation
              :error="$page.props.errors.phone"
              :error-messages="$page.props.errors.phone"
            >
              <template #prepend>
                <va-select
                  v-model="form.country_phone_code"
                  :options="phone_codes"
                  value-by="code"
                  text-by="code"
                  immediateValidation
                  :error="$page.props.errors.country_phone_code"
                  :error-messages="$page.props.errors.country_phone_code"
                />
              </template>
            </va-input>

            <va-input
              v-model="form.address_1"
              class="mb-3"
              :label="$t('organization.webDomains.addressLine1')"
              immediateValidation
              :error="$page.props.errors.address_1"
              :error-messages="$page.props.errors.address_1"
            />

            <va-input
              v-model="form.address_2"
              class="mb-3"
              :label="$t('organization.webDomains.addressLine2')"
              immediateValidation
              :error="$page.props.errors.address_2"
              :error-messages="$page.props.errors.address_2"
            />

            <va-input
              v-model="form.city"
              class="mb-3"
              :label="$t('organization.webDomains.city')"
              immediateValidation
              :error="$page.props.errors.cira_language"
              :error-messages="$page.props.errors.cira_language"
            />

            <va-input
              v-model="form.postal_code"
              class="mb-3"
              :label="$t('organization.webDomains.zipPostalCode')"
              immediateValidation
              :error="$page.props.errors.postal_code"
              :error-messages="$page.props.errors.postal_code"
            />

            <state-dropdown class="va-input mb-3" :country="form.country" v-model:state="form.state" />
            <country-dropdown class="va-input mb-3" v-model:country="form.country" />
            <div v-if="tld == 'ca'">
              <va-select
                v-model="form.cira_legal_type"
                :options="cira_legal_type"
                text-by="text"
                value-by="value"
                class="mb-3"
                :label="$t('organization.webDomains.ciraLegalType')"
                immediateValidation
                :error="$page.props.errors.cira_legal_type"
                :error-messages="$page.props.errors.cira_legal_type"
              />
              <va-select
                v-model="form.cira_language"
                :options="cira_language"
                text-by="text"
                value-by="value"
                class="mb-3"
                :label="$t('organization.webDomains.language')"
                immediateValidation
                :error="$page.props.errors.cira_language"
                :error-messages="$page.props.errors.cira_language"
              />
                <p class="mb-2" v-html="$t('organization.webDomains.ciraAgreement1')"></p>
              <p class="mb-2" v-html="$t('organization.webDomains.ciraAgreement2')"></p>
              <p class="mb-2" v-html="$t('organization.webDomains.ciraAgreement3')"></p>
            </div>
            <div class="auth-layout__options d-flex align-center mb-4">
              <va-checkbox
                v-model="form.accept_terms"
                id="acceptTerms"
                class="mb-0"
                immediateValidation
                :error="$page.props.errors.accept_terms"
                :error-messages="$page.props.errors.accept_terms"
              >
                <template #label>
                  <div class="ml-2" v-html="$t('organization.webDomains.namecheapTerms')"></div>
                </template>
              </va-checkbox>
            </div>
            <p></p>
            <va-button id="submit" type="submit"
              :disabled="form.processing"
              class="my-0"
            >
              {{ $t('organization.webDomains.register') }}
            </va-button>
          </form>
        </div>
        <div class="flex lg4">
          <div>
            <div class="row text-center pricing">
              <div class="flex lg12">
                <div>
                  <div class="va-title">{{ $t('organization.webDomains.totalPrice') }}</div>
                  <h1 v-if="!is_premium" class="va-h3">{{ price }}</h1>
                  <h1 v-else class="va-h1">${{ registration_price }}</h1>
                  <p v-if="is_premium" class="va-text-bold va-text-secondary">{{ $t('organization.webDomains.premiumDomain') }}</p>
                  <p>{{ $t('organization.webDomains.pricesInUsd') }}</p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </va-card-content>
  </va-card>
</template>

<script lang="ts">
export default {
  layout: (h, page) => h(AppLayout, [page]),
  props: {
    organization: Object,
    user: Object,
    domain: Object,
    standard_prices: Array,
    registration_price: Number,
    is_premium: Boolean,
    tld: String,
    errors: Object
  },
  data () {
    const years = []
    Object.keys(this.standard_prices).forEach((year) => {
      years.push(year)
    })

    return {
      phone_codes: countries.sort((a, b) => { return a.code - b.code }),
      years,
      form: useForm({
        years: '1',
        organization_name: this.organization.name,
        email_address: this.organization.email,
        phone: this.organization.phone_number,
        country_phone_code: '+1',
        first_name: this.user.first_name,
        last_name: this.user.last_name,
        address_1: this.organization.address_1,
        address_2: this.organization.address_2,
        city: this.organization.city,
        postal_code: this.organization.postal_code,
        state: this.organization.state,
        country: this.organization.country,
        accept_terms: false,
        tld: this.tld,
        cira_legal_type: '',
        cira_language: ''
      }),
      cira_language: [
        {
          value: 'en',
          text: 'English'
        },
        {
          value: 'fr',
          text: 'French'
        }
      ],
      cira_legal_type: [
        {
          value: 'CCO',
          text: 'Corporation (Canada or Canadian province or territory)'
        },
        {
          value: 'CCT',
          text: 'Canadian citizen'
        },
        {
          value: 'RES',
          text: 'Permanent Resident of Canada'
        },
        {
          value: 'GOV',
          text: 'Government entity in Canada'
        },
        {
          value: 'EDU',
          text: 'Canadian Educational Institution'
        },
        {
          value: 'ASS',
          text: 'Canadian Unincorporated Association'
        },
        {
          value: 'HOP',
          text: 'Canadian Hospital'
        },
        {
          value: 'PRT',
          text: 'Partnership Registered in Canada'
        },
        {
          value: 'TDM',
          text: 'Trade-mark registered in Canada by non-Canadian owner'
        },
        {
          value: 'TRD',
          text: 'Canadian Trade Union'
        },
        {
          value: 'PLT',
          text: 'Canadian Political Party'
        },
        {
          value: 'LAM',
          text: 'Canadian Library, Archive or Museum'
        },
        {
          value: 'TRS',
          text: 'Trust established in Canada'
        },
        {
          value: 'ABO',
          text: 'Aboriginal Peoples (individuals) indigenous to Canada'
        },
        {
          value: 'INB',
          text: 'Indian Band recognized by the Indian Act of Canada'
        },
        {
          value: 'LGR',
          text: 'Legal Representative of a Canadian Citizen or Permanent Resident'
        },
        {
          value: 'OMK',
          text: 'Official marks registered in Canada'
        },
        {
          value: 'MAJ',
          text: 'Her Majesty the Queen'
        }
      ]
    }
  },
  computed: {
    price () {
      let number = this.standard_prices[this.form.years] * this.form.years

      const decimalplaces = 2
      const decimalcharacter = '.'
      const thousandseparater = ','
      number = parseFloat(number)
      const sign = number < 0 ? '-' : ''
      let formatted = new String(number.toFixed(decimalplaces))
      if (decimalcharacter.length && decimalcharacter !== '.') { formatted = formatted.replace(/\./, decimalcharacter) }
      let integer = ''
      let fraction = ''
      const strnumber = new String(formatted)
      const dotpos = decimalcharacter.length ? strnumber.indexOf(decimalcharacter) : -1
      if (dotpos > -1) {
        if (dotpos) { integer = strnumber.substr(0, dotpos) }
        fraction = strnumber.substr(dotpos + 1)
      } else { integer = strnumber }
      if (integer) { integer = String(Math.abs(integer)) }
      while (fraction.length < decimalplaces) { fraction += '0' }
      const temparray = []
      while (integer.length > 3) {
        temparray.unshift(integer.substr(-3))
        integer = integer.substr(0, integer.length - 3)
      }
      temparray.unshift(integer)
      integer = temparray.join(thousandseparater)
      return '$' + sign + integer + decimalcharacter + fraction
    }
  }
}
</script>
<style lang="scss" scoped>
  .row-equal .flex {
    .va-card {
      height: 100%;
    }
  }

  .va-button {
    max-width: 80px;
  }

  .text-center-aligned {
    text-align: center;
  }

  .full-width-button {
    width: 100%;
  }

  .pricing {
    border-width: 1px;
    border-color: var(--va-background-element);
    border-style: solid;
    border-radius: 5px;
    box-shadow: 3px 3px 5px 3px var(--va-background-element);
  }
</style>
