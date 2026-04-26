<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import EmailAccountsLayout from './EmailAccountsMain.vue'
import { useForm } from '@inertiajs/vue3'

</script>
<template>
  <Head>
    <title>{{ $t('organization.emailAccounts.emailAccounts') }} - Control Panel</title>
  </Head>
  <div class="web-domains-domains">
    <div class="row justify-center">
      <va-button class="mb-3" v-if="can.add_email_accounts" @click="showAddEmailAccount = true">{{ $t('organization.emailAccounts.createEmail') }}</va-button>
    </div>
    <va-alert
      outline
      >{{ $t('organization.emailAccounts.createEmailWarning') }}</va-alert
    >
    <template v-if="accounts.length === 0">
      <div class="row m-5">
        <div class="flex lg12 va-text-center mt-4">
          <va-icon name="fa-envelopes-bulk" style="color: var(--va-list-item-label-caption-color)" size="5rem" />
        </div>
        <div class="flex lg12 va-text-center mb-4">
          <h2 class="va-h2" style="color: var(--va-list-item-label-caption-color)">{{ $t('organization.emailAccounts.noEmailAccounts') }}</h2>
        </div>
      </div>
    </template>
    <div v-else class="table-wrapper">
      <table class="va-table va-table--hoverable mt-3">
        <thead>
          <tr>
            <th style="width: 1%"></th>
            <th style="width: 10%">{{ $t('organization.emailAccounts.name') }}</th>
            <th style="width: 90%">{{ $t('organization.emailAccounts.email') }}</th>
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
      <va-card-title class="m-0"> {{ $t('organization.emailAccounts.removeTitle', { email: removeEmailAccount }) }} </va-card-title>
      <va-card-content class="m-0">
        {{ $t('organization.emailAccounts.removeConfirm', { email: removeEmailAccount }) }}
      </va-card-content>
      <va-card-actions align="right">
        <va-button
          color="backgroundSecondary"
          @click="ok"
        >
          {{ $t('common.cancel') }}
        </va-button>
        <va-button color="danger" @click="remove.delete('/settings/email/accounts/'+removeEmailAccount); showRemoveEmailAccount = !showRemoveEmailAccount">{{ $t('common.delete') }}</va-button>
      </va-card-actions>
    </template>
  </va-modal>
  <va-modal v-model="showAddEmailAccount" hide-default-actions no-dismiss no-padding size="small">
    <template #content="{ cancel }">
      <form @submit.prevent="addEmailAccount()">
        <va-card-title>{{ $t('organization.emailAccounts.addEmailAccount') }}</va-card-title>
        <va-card-content>
            <va-input v-model="add.name"
              immediateValidation
              :error="$page.props.errors.name"
              :error-messages="$page.props.errors.name"
              class="mb-3"
              :label="$t('organization.emailAccounts.name')"
            > </va-input>

            <va-input v-model="add.email"
              immediateValidation
              :error="$page.props.errors.email"
              :error-messages="$page.props.errors.email"
              class="mb-3"
              :label="$t('organization.emailAccounts.email')"
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
              :label="$t('organization.emailAccounts.password')"
            > </va-input>

            <va-input v-model="add.password_confirmation"
              immediateValidation
              type="password"
              :label="$t('organization.emailAccounts.confirmPassword')" />
          </va-card-content>
          <va-card-actions align="right">
            <va-button color="backgroundSecondary" @click="cancel">{{ $t('common.cancel') }}</va-button>
            <va-button type="submit" color="primary" :disabled="add.processing">{{ $t('common.create') }}</va-button>
          </va-card-actions>
        </form>
    </template>
  </va-modal>
  <va-modal v-model="showEditEmailAccount" hide-default-actions no-dismiss no-padding size="small">
    <template #content="{ cancel }">
      <form @submit.prevent="updateEmailAccount()">
        <va-card-title>{{ $t('organization.emailAccounts.editTitle', { email: editEmailAccount.email }) }}</va-card-title>
        <va-card-content>
            <va-input v-model="edit.name"
              immediateValidation
              :error="$page.props.errors.name"
              :error-messages="$page.props.errors.name"
              class="mb-3"
              :label="$t('organization.emailAccounts.name')"
            />

            <va-input v-model="edit.password"
              immediateValidation
              :error="$page.props.errors.password"
              :error-messages="$page.props.errors.password"
              class="mb-3"
              type="password"
              :label="$t('organization.emailAccounts.password')"
            />

            <va-input v-model="edit.password_confirmation"
              immediateValidation
              type="password"
              :label="$t('organization.emailAccounts.confirmPassword')" />
          </va-card-content>
          <va-card-actions align="right">
            <va-button color="backgroundSecondary" @click="cancel">{{ $t('common.cancel') }}</va-button>
            <va-button type="submit" color="primary" :disabled="add.processing">{{ $t('common.update') }}</va-button>
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
