<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import EmailAccountsLayout from './EmailAccountsMain.vue'
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <Head>
    <title>Email Accounts - Control Panel</title>
  </Head>
  <div class="web-domains-domains">
    <div class="row justify-center">
      <va-button class="mb-3" v-if="can.add_email_accounts" @click="showAddEmailAccount = true">Create Email</va-button>
    </div>
    <va-alert
      outline
      >Please do not use this to create email accounts for people. We recommend creating a new user and giving them an
      email account through their user account. Then they can change their own password.</va-alert
    >
    <template v-if="accounts.length === 0">
      <div class="row m-5">
        <div class="flex lg12 va-text-center mt-4">
          <va-icon name="fa-envelopes-bulk" style="color: var(--va-list-item-label-caption-color)" size="5rem" />
        </div>
        <div class="flex lg12 va-text-center mb-4">
          <h2 class="va-h2" style="color: var(--va-list-item-label-caption-color)">No Email Accounts</h2>
        </div>
      </div>
    </template>
    <div v-else class="table-wrapper">
      <table class="va-table va-table--hoverable mt-3">
        <thead>
          <tr>
            <th style="width: 1%"></th>
            <th style="width: 10%">Name</th>
            <th style="width: 90%">Email</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="account in accounts.slice((curPageValue - 1), (curPageValue - 1 + pageSize))" :key="account.email">
            <td>
              <va-icon name="fa-edit" class="clickable-icon" color="primary" @click="showEditEmailAccountModal(account)" />
            </td>
            <td>{{ account.name }}</td>
            <td>{{ account.email }}</td>
            <td>
              <va-icon name="entypo-cancel" class="clickable-icon" color="danger" @click="showRemoveEmailAccountModal(account.email)" />
            </td>
          </tr>
        </tbody>
      </table>

      <va-pagination v-if="accounts.length > pageSize" class="mt-3 mb-3 justify-center" v-model="curPageValue" :total="accounts.length" :direction-links="false" :page-size="pageSize" />

    </div>
  </div>
  <va-modal
    v-model="showRemoveEmailAccount"
    hide-default-actions
    no-outside-dismiss
    no-padding
    size="small"
    class="p-0"
  >
    <template #content="{ ok }">
      <va-card-title class="m-0"> Remove {{ removeEmailAccount }}? </va-card-title>
      <va-card-content class="m-0">
        Are you sure you want to remove {{ removeEmailAccount }}? This action is permanent.
      </va-card-content>
      <va-card-actions align="right">
        <va-button
          color="backgroundSecondary"
          @click="ok"
        >
          Cancel
        </va-button>
        <va-button color="danger" @click="remove.delete('/settings/email/accounts/'+removeEmailAccount); showRemoveEmailAccount = !showRemoveEmailAccount">Delete</va-button>
      </va-card-actions>
    </template>
  </va-modal>
  <va-modal v-model="showAddEmailAccount" hide-default-actions no-dismiss no-padding size="small">
    <template #content="{ cancel }">
      <form @submit.prevent="addEmailAccount()">
        <va-card-title>Add Email Account</va-card-title>
        <va-card-content>
            <va-input v-model="add.name"
              immediateValidation
              :error="$page.props.errors.name"
              :error-messages="$page.props.errors.name"
              class="mb-3"
              label="Name"
            > </va-input>

            <va-input v-model="add.email"
              immediateValidation
              :error="$page.props.errors.email"
              :error-messages="$page.props.errors.email"
              class="mb-3"
              label="Email"
            >
              <template #append>
                <va-select
                  v-model="add.domain"
                  immediateValidation
                  :options="domains"
                  text-by="text"
                  value-by="value"
                  :error="$page.props.errors.domain"
                  :error-messages="$page.props.errors.domain"
                >
                  <template #prepend>
                    <div class="mx-1">@</div>
                  </template>
                </va-select>
              </template>
            </va-input>

            <va-input v-model="add.password"
              immediateValidation
              :error="$page.props.errors.password"
              :error-messages="$page.props.errors.password"
              class="mb-3"
              type="password"
              label="Password"
            > </va-input>

            <va-input v-model="add.password_confirmation"
              immediateValidation
              type="password"
              label="Confirm Password" />
          </va-card-content>
          <va-card-actions align="right">
            <va-button color="backgroundSecondary" @click="cancel">Cancel</va-button>
            <va-button type="submit" color="primary" :disabled="add.processing">Create</va-button>
          </va-card-actions>
        </form>
    </template>
  </va-modal>
  <va-modal v-model="showEditEmailAccount" hide-default-actions no-dismiss no-padding size="small">
    <template #content="{ cancel }">
      <form @submit.prevent="updateEmailAccount()">
        <va-card-title>Edit {{ editEmailAccount.email }}</va-card-title>
        <va-card-content>
            <va-input v-model="edit.name"
              immediateValidation
              :error="$page.props.errors.name"
              :error-messages="$page.props.errors.name"
              class="mb-3"
              label="Name"
            />

            <va-input v-model="edit.password"
              immediateValidation
              :error="$page.props.errors.password"
              :error-messages="$page.props.errors.password"
              class="mb-3"
              type="password"
              label="Password"
            />

            <va-input v-model="edit.password_confirmation"
              immediateValidation
              type="password"
              label="Confirm Password" />
          </va-card-content>
          <va-card-actions align="right">
            <va-button color="backgroundSecondary" @click="cancel">Cancel</va-button>
            <va-button type="submit" color="primary" :disabled="add.processing">Update</va-button>
          </va-card-actions>
        </form>
    </template>
  </va-modal>
</template>

<script lang="ts">
export default {
  layout: (h, page) => {
    return h(AppLayout, () => h(EmailAccountsLayout, () => page))
  },
  props: {
    accounts: Object,
    domains: Object,
    default_domain: Object,
    can: Object,
    errors: Object
  },
  data () {
    return {
      curPageValue: 1,
      pageSize: 10,
      showAddEmailAccount: false,
      removeEmailAccount: '',
      showRemoveEmailAccount: false,
      editEmailAccount: '',
      showEditEmailAccount: false,
      remove: useForm({}),
      add: useForm({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
        domain: this.default_domain
      }),
      edit: useForm({
        name: '',
        password: '',
        password_confirmation: ''
      })
    }
  },
  methods: {
    showRemoveEmailAccountModal (account) {
      this.showRemoveEmailAccount = true
      this.removeEmailAccount = account
    },
    showEditEmailAccountModal (account) {
      this.showEditEmailAccount = true
      this.editEmailAccount = account
      this.edit.name = account.name
    },
    addEmailAccount () {
      this.add.post('/settings/email/accounts', {
        onSuccess: () => { this.add.reset('password', 'name', 'email', 'password_confirmation'); this.showAddEmailAccount = false }
      })
    },
    updateEmailAccount () {
      this.edit.put('/settings/email/accounts/' + this.editEmailAccount.email, {
        onSuccess: () => { this.showEditEmailAccount = false }
      })
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
