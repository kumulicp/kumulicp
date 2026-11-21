<script setup lang="ts">
import MailboxAliases from '@/components/MailboxAliases.vue'
import AppLayout from '@/layouts/AppLayout.vue'
import UserLayout from './UserLayout.vue'
import { useForm } from '@inertiajs/vue3'
import { ref } from 'vue'
import { useInputMask, createRegexMask } from 'vuestic-ui'


const phoneNumber = ref()
useInputMask(createRegexMask(/(\+\d \(\d{3}\)|\d{3}) (\d){3}-(\d){4}/), phoneNumber)
</script>
<template>
  <Head>
    <title>{{ $t('organization.users.editUser') }} - Control Panel</title>
  </Head>
  <div class="user-profile">
    <div class="row">
      <div class="flex xs12">
        <h5 class="va-h5 mt-0 pt-0">{{ user.name }}</h5>
      </div>
    </div>
    <form @submit.prevent="form.put('/users/'+user.id)">
      <div class="row">
        <div class="flex flex-col xs12 lg6 mb-2">
          <va-input v-model="form.id"
            :label="$t('auth.username')"
            id="userName"
            readonly
            />
        </div>
        <div class="flex flex-col xs12 lg6 mb-2">
          <va-input v-if="user.org_email"
            :model-value="user.org_email"
            :label="$t('organization.users.loginEmail')"
            id="loginEmail"
            readonly
            >
            <template #appendInner>
              <a href="#" id="deleteLoginEmail" role="button" @click.prevent="confirmDeleteEmail(true)">
                <va-icon name="entypo-cancel"
                  :title="$t('organization.users.deleteEmail')"
                  color="danger"
                  />
              </a>
            </template>
          </va-input>
          <va-input v-else
            v-model="form.personal_email"
            :label="$t('organization.users.loginRecoveryEmail')"
            id="personalEmail"
            required-mark
            immediateValidation
            :error="$page.props.errors.personal_email"
            :error-messages="$page.props.errors.personal_email"
            />
        </div>
      </div>
      <div v-if="organizations.length > 1" class="row">
        <div class="flex flex-col xs12 lg6 mb-2">
          <va-select
            v-model="form.organization"
            :label="$t('organization.organization')"
            :options="organizations"
            text-by="name"
            value-by="id"
            placement="auto"
            immediateValidation
            :errors="$page.props.errors.organization"
            :error-messages="$page.props.errors.organization"
            />
        </div>
      </div>
      <div class="row">
        <div class="flex flex-col xs12 lg6 mb-2">
          <va-input v-model="form.first_name"
            :label="$t('organization.users.firstName')"
            id="firstName"
            required-mark
            immediateValidation
            :error="$page.props.errors.first_name"
            :error-messages="$page.props.errors.first_name"
            />
        </div>
        <div class="flex flex-col xs12 lg6 mb-2">
          <va-input v-model="form.last_name"
            :label="$t('organization.users.lastName')"
            id="lastName"
            required-mark
            immediateValidation
            :error="$page.props.errors.last_name"
            :error-messages="$page.props.errors.last_name"
            />
        </div>
      </div>
      <div class="row">
        <div class="flex flex-col xs12 lg6 mb-2">
          <va-input v-model="form.phone_number"
            :label="$t('organization.users.phoneNumber')"
            id="phoneNumber"
            immediateValidation
            placeholder="(###) ###-####"
            ref="phoneNumber"
            :error="$page.props.errors.phone_number"
            :error-messages="$page.props.errors.phone_number"
            />
        </div>
        <div v-if="user.org_email" class="flex flex-col xs12 lg6 mb-2">
          <va-input v-model="form.personal_email"
            :label="$t('recoveryEmail.label')"
            id="personalEmail"
            disabled
            />
        </div>
      </div>
      <va-alert v-if="user.offered_email"
        id="offeredEmail"
        color="info"
        outline
        icon="fa-envelope"
        class="mb-4"
        >
        <div class="org-email-message">
          <div>
            <b>{{ user.offered_email }}</b>
            <div class="va-text-secondary">{{ $t('organization.users.offeredEmail') }}</div>
          </div>
          <a href="#" id="removeOfferedEmail" role="button" @click.prevent="confirmDeleteEmail(false)">
            <va-icon name="entypo-cancel" color="danger" class="mr-1" />{{ $t('organization.users.deleteEmail') }}
          </a>
        </div>
      </va-alert>
      <va-alert v-else-if="canCreateOrgEmail"
        id="orgEmailPrompt"
        color="info"
        outline
        icon="fa-envelope"
        class="mb-4"
        >
        <div class="org-email-message">
          <div>
            <b>{{ $t('organization.users.orgEmailsEnabled') }}</b>
            <div>{{ $t('organization.users.usingOtherEmail', { name: user.name, email: user.personal_email }) }}</div>
          </div>
          <va-button type="button" size="small" id="createOrgEmail" @click="showCreateOrgEmail = true">
            {{ $t('organization.users.createOrgEmail') }}
          </va-button>
        </div>
      </va-alert>
      <mailbox-aliases v-if="aliases"
        :mailbox-id="aliases.mailbox_id"
        :aliases="aliases.list"
        :domains="aliases.domains"
        :enabled="aliases.enabled"
      />
      <div v-for="(storage, index) in user.additional_storage" :key="index" class="row">
        <div class="flex flex-col xs12 lg6 mb-2">
          <h5>{{ $t('organization.users.appStorage', { app: storage.app_name }) }}</h5>
        </div>
        <div class="flex flex-col xs12 lg6 mb-2">
          <va-select
            v-model="form['additional_storage'][storage.id]"
            :options="storage.options"
            immediateValidation
            text-by="text"
            value-by="value"
            placement="auto"
            />
          <div v-if="form['additional_storage'][storage.id] != storage.quantity" class="subscription_warning"><b>{{ $t('organization.users.warningLabel') }}</b> {{ $t('organization.users.storageWarning', { name: storage.name }) }}</div>
        </div>
      </div>
      <va-button type="submit" id="submit" class="mr-2 mb-2" :disabled="form.processing">{{ $t('common.submit') }}</va-button>
    </form>

    <va-modal v-model="showCreateOrgEmail" no-padding size="small" class="p-0">
      <template #content="{ ok }">
        <va-card-title class="m-0">{{ $t('organization.users.createOrgEmail') }}</va-card-title>
        <va-card-content class="m-0">
          <p class="mb-3">{{ $t('organization.users.createOrgEmailIntro', { name: user.name, email: user.personal_email }) }}</p>
          <va-alert color="warning" outline icon="warning" class="mb-3">
            {{ $t('organization.users.orgEmailAppsWarning', { name: user.name }) }}
          </va-alert>
          <p class="mb-3">{{ $t('organization.users.createOrgEmailAccept', { name: user.name }) }}</p>
          <h6 class="va-h6 mb-2">{{ $t('organization.users.createOrgEmailChoose') }}</h6>
          <div class="org-email-options">
            <va-button v-for="domain in domainList"
              :key="domain.id"
              preset="secondary"
              :href="'/users/'+user.id+'/create/accountemail/'+domain.id"
              class="createOrgEmailOption"
              >
              {{ user.id.toLowerCase() }}@{{ domain.name }}
              <small v-if="domain.activating" class="ml-1">({{ $t('organization.users.domainActivating') }})</small>
            </va-button>
          </div>
        </va-card-content>
        <va-card-actions align="right">
          <va-button color="textInverted" @click="ok">{{ $t('common.cancel') }}</va-button>
        </va-card-actions>
      </template>
    </va-modal>

    <va-modal v-model="showDeleteEmail" no-padding size="small" class="p-0">
      <template #content="{ ok }">
        <va-card-title class="m-0">{{ $t('organization.users.deleteOrgEmailTitle') }}</va-card-title>
        <va-card-content class="m-0">
          <p v-if="deleteIsActive" id="deleteOrgEmailActive" class="mb-3">{{ $t('organization.users.deleteOrgEmailIntro', { name: user.name, email: deleteAddress }) }}</p>
          <p v-else id="deleteOrgEmailOffered" class="mb-3">{{ $t('organization.users.deleteOfferedEmailIntro', { name: user.name, email: deleteAddress }) }}</p>
          <va-alert v-if="deleteIsActive" color="warning" outline icon="warning" class="mb-3">
            {{ $t('organization.users.orgEmailAppsWarning', { name: user.name }) }}
          </va-alert>
        </va-card-content>
        <va-card-actions align="right">
          <va-button color="textInverted" @click="ok">{{ $t('common.cancel') }}</va-button>
          <va-button color="danger" id="confirmDeleteEmail" :href="'/users/'+user.id+'/remove/accountemail/'+deleteAddress" class="mr-2 mb-2">
            {{ $t('common.delete') }}
          </va-button>
        </va-card-actions>
      </template>
    </va-modal>
  </div>
</template>

<script lang="ts">
export default {
  layout: (h, page) => {
    return h(AppLayout, () => h(UserLayout, () => page))
  },
  props: {
    user: Object,
    errors: Object,
    organizations: Object,
    email_domains: Object,
    aliases: Object
  },
  data () {
    const additionalStorage = {}
    Object.values(this.user.additional_storage).forEach((storage) => {
      additionalStorage[storage.id] = storage.quantity
    })

    return {
      showCreateOrgEmail: false,
      showDeleteEmail: false,
      deleteIsActive: false,
      form: useForm({
        id: this.user.id,
        first_name: this.user.first_name,
        last_name: this.user.last_name,
        phone_number: this.user.phone_number,
        personal_email: this.user.personal_email,
        additional_storage: additionalStorage,
        organization: this.user.organization
      })
    }
  },
  computed: {
    domainList () {
      return Object.values(this.email_domains ?? {})
    },
    // The address the delete confirmation is about: the login email, or the one still waiting to be accepted
    deleteAddress () {
      return this.deleteIsActive ? this.user.org_email : this.user.offered_email
    },
    // Org emails are on and this person signs in with something else: nothing created yet and nothing waiting
    canCreateOrgEmail () {
      return !!this.user.can.add_email_account && !this.user.org_email && !this.user.offered_email && this.domainList.length > 0
    }
  },
  methods: {
    confirmDeleteEmail (active) {
      this.deleteIsActive = active
      this.showDeleteEmail = true
    }
  }
}
</script>

<style lang="scss">
.subscription_warning {
 color: var(--va-red-800)
}

.org-email-message {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 0.75rem 1rem;
}

.org-email-options {
  display: flex;
  flex-direction: column;
  align-items: stretch;
  gap: 0.5rem;
}
</style>
