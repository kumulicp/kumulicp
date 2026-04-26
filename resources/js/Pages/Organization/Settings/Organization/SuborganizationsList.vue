<script setup lang="ts">
import CountryDropdown from '@/components/FormInputs/CountryDropdown.vue'
import StateDropdown from '@/components/FormInputs/StateDropdown.vue'
import { Link, useForm } from '@inertiajs/vue3'
import { useInputMask, createRegexMask } from 'vuestic-ui'
import { ref } from 'vue'


const phoneNumber = ref()
useInputMask(createRegexMask(/(\+\d \(\d{3}\)|\d{3}) (\d){3}-(\d){4}/), phoneNumber)
</script>
<template>
  <Head>
    <title>{{ $t('organization.settings.suborganizations') }} - Control Panel</title>
  </Head>
  <div class="user">
    <div class="row">
      <div class="flex xs12 lg12">
        <va-card class="mb-4">
          <va-card-title>{{ $t('organization.settings.suborganizations') }}</va-card-title>
          <va-card-content>
            <div class="row justify-center">
              <va-button v-if="can.add_org" id="createOrg" class="" @click="showAddOrg = !showAddOrg">{{ $t('organization.settings.createSuborganization') }}</va-button>
              <va-modal v-if="can.add_org" v-model="showAddOrg" no-outside-dismiss no-padding size="small" class="p-0">
                <template #content="{ ok }">
                  <form @submit.prevent="form.post('/settings/suborganizations')">
                    <va-card-title class="m-0"> {{ $t('organization.settings.addSuborganization') }} </va-card-title>
                    <va-card-content class="m-0">
                        <va-select
                            v-model="form.type"
                            :options="org_types"
                            id="type"
                            class="mb-3"
                            text-by="name"
                            value-by="value"
                            :label="$t('organization.settings.typeOfOrganization')"
                            immediateValidation
                            :error="$page.props.errors.type"
                            :error-messages="$page.props.errors.type"
                        />

                        <va-input
                            v-model="form.name"
                            id="name"
                            class="mb-3"
                            :label="$t('organization.settings.organizationName')"
                            immediateValidation
                            :error="$page.props.errors.name"
                            :error-messages="$page.props.errors.name"
                        />

                        <va-input
                            v-model="form.description"
                            id="description"
                            class="mb-3"
                            :label="$t('organization.settings.describeOrganization')"
                            immediateValidation
                            :error="$page.props.errors.description"
                            :error-messages="$page.props.errors.description"
                        />

                        <va-input
                            v-model="form.email"
                            id="email"
                            class="mb-3"
                            :label="$t('auth.email')"
                            immediateValidation
                            :error="$page.props.errors.email"
                            :error-messages="$page.props.errors.email"
                        />

                        <va-input
                            v-model="form.phone_number"
                            id="phoneNumber"
                            ref="phoneNumber"
                            maxlength="30"
                            class="mb-3"
                            :label="$t('organization.users.phoneNumber')"
                            immediateValidation
                            :error="$page.props.errors.phone_number"
                            :error-messages="$page.props.errors.phone_number"
                        />

                        <va-divider class="mb-3" />

                        <va-input
                            v-model="form.street"
                            id="street"
                            class="mb-3"
                            :label="$t('organization.street')"
                            immediateValidation
                            :error="$page.props.errors.street"
                            :error-messages="$page.props.errors.street"
                        />

                        <va-input
                            v-model="form.zipcode"
                            id="zipcode"
                            class="mb-3"
                            :label="$t('organization.zipcode')"
                            immediateValidation
                            :error="$page.props.errors.zipcode"
                            :error-messages="$page.props.errors.zipcode"
                        />

                        <va-input
                            v-model="form.city"
                            id="city"
                            class="mb-3"
                            :label="$t('organization.city')"
                            immediateValidation
                            :error="$page.props.errors.city"
                            :error-messages="$page.props.errors.city"
                        />

                        <country-dropdown class="va-input mb-3" v-model:country="form.country" />

                        <state-dropdown class="va-input mb-3" :country="form.country" v-model:state="form.state" />

                        <va-divider class="mb-3" />

                        <va-input
                            v-model="form.subdomain"
                            id="subdomain"
                            maxlength="30"
                            class="mb-3"
                            :label="$t('organization.settings.subdomainName')"
                            immediateValidation
                            :error="$page.props.errors.subdomain"
                            :error-messages="$page.props.errors.subdomain"
                            :messages="$t('organization.settings.subdomainMessage', { url: orgURL })"
                        >
                            <template #appendInner>
                            .{{ base_domain }}
                            </template>
                        </va-input>

                        <div id="termsOfUse" class="auth-layout__options d-flex align-center">
                            <va-checkbox
                            v-model="form.terms_of_use"
                            class="mb-0"
                            immediateValidation
                            :error="$page.props.errors.terms_of_use"
                            :error-messages="$page.props.errors.terms_of_use"
                            >
                            <template #label>
                            </template>
                            </va-checkbox>
                                <span class="ml-2">
                                {{ $t('organization.settings.iAgreeTo') }}
                                <a :href="terms_url" target="blank" class="va-link">{{ $t('organization.settings.termsOfUse') }}</a>
                                </span>
                        </div>
                    </va-card-content>
                    <va-card-actions align="right" class="">
                      <va-button color="textInverted" :disabled="form.processing" @click="ok">{{ $t('modal.cancel') }}</va-button>
                      <va-button type="submit" :disabled="form.processing" id="submit" class="mr-2 mb-2">{{ $t('form.submit') }}</va-button>
                    </va-card-actions>
                  </form>
                </template>
              </va-modal>
            </div>

            <va-scroll-container
              color="warning"
              horizontal
            >
              <table class="va-table va-table--hoverable mt-3">
                <thead>
                  <tr>
                    <th style="width:20rem">{{ $t('form.name') }}</th>
                    <th>{{ $t('auth.email') }}</th>
                    <th style="width: 10rem"></th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(organization, i) in organizations.slice((curPageValue - 1), (curPageValue - 1 + pageSize))" :key="i">
                    <td>
                      <Link :href="'/settings/suborganizations/'+organization.id">{{ organization.name }}</Link>
                    </td>
                    <td>
                      <a :href="'mailto:'+organization.main_contact.email">{{ organization.main_contact.email }}</a>
                    </td>
                    <td class="va-text-right">
                      <va-button
                        color="danger"
                        :id="'delete'+organization.id"
                        @click="showRemoveOrgModal(organization.id)">
                        {{ $t('modal.delete') }}
                      </va-button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </va-scroll-container>

            <va-pagination v-if="meta.total > pageSize" class="mt-3 mb-3 justify-center" v-model="curPageValue" :pages="pages" input @update:modelValue="changePage" />
            <va-modal v-model="showRemoveOrg" hide-default-actions :title="$t('organization.settings.removeOrgTitle', { name: removeOrg })"
              :message="$t('organization.settings.removeOrgMessage', { name: removeOrg })">
              <template #footer>
                <va-button color="backgroundSecondary" @click="showRemoveOrg = false">
                  {{ $t('modal.cancel') }}
                </va-button>
                <va-button id="delete" color="danger"
                  @click="remove.delete('/settings/suborganizations/' + removeOrg); showRemoveOrg = !showRemoveOrg">{{ $t('modal.delete') }}</va-button>
              </template>
        </va-modal>
          </va-card-content>
        </va-card>
      </div>
    </div>
  </div>
</template>

<script lang="ts">
export default {
  props: {
    organizations: Object,
    terms_url: Object,
    org_types: Object,
    base_domain: Object,
    can: Object,
    errors: Object,
    meta: Object
  },
  data () {
    return {
      curPageValue: this.meta.page,
      pages: this.meta.pages,
      pageSize: 15,
      showAddOrg: false,
      showRemoveOrg: false,
      removeOrg: '',
      form: useForm({
        username: '',
        contact_email: '',
        password: '',
        password_confirmation: '',
        contact_first_name: '',
        contact_last_name: '',
        contact_phone_number: '',
        type: '',
        name: '',
        email: '',
        phone_number: '',
        subdomain: '',
        description: '',
        street: '',
        zipcode: '',
        city: '',
        state: '',
        country: 'US',
        terms_of_use: false
      }),
      remove: useForm({})
    }
  },
  computed: {
    orgURL () {
      let subdomain = '___'
      if (this.form.subdomain) {
        subdomain = this.form.subdomain
      }

      return subdomain + '.' + this.base_domain
    }
  },
  methods: {
    showRemoveOrgModal (org) {
      this.removeOrg = org
      this.showRemoveOrg = !this.showRemoveOrg
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
