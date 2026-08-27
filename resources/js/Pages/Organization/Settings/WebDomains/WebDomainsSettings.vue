<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import WebDomainsLayout from './WebDomainsLayout.vue'
import EnableEmailDomainModal from './modals/EnableEmailDomainModal.vue'
import RenewDomainModal from './modals/RenewDomainModal.vue'
import ReactivateDomainModal from './modals/ReactivateDomainModal.vue'
import RequestTransferModal from './modals/RequestTransferModal.vue'
import SelfManageDomainModal from './modals/SelfManageDomainModal.vue'
import RemoveDomainModal from './modals/RemoveDomainModal.vue'
import TransferInDomainModal from './modals/TransferInDomainModal.vue'
import { Link, useForm } from '@inertiajs/vue3'

</script>
<template>
  <Head>
    <title>{{ $t('organization.webDomains.domainSettings') }} - Control Panel</title>
  </Head>
  <div class="row justify-center mb-3">
    <new-domain-modal :showModal="showAddDomain" @update:showModal="showAddDomain = $event" />
    <va-button-dropdown v-if="number_of_actions > 0"
      size="medium"
      :label="$t('organization.webDomains.actions')"
      color="secondary"
      class="ml-3 my-2"
      :verticalScrollOnOverflow="false"
    >
      <div>
        <a v-if="domain.actions.enable_email" href="#" @click="showActionModal(domain.name, 'enable_email')"><div class="action_dropdown">{{ $t('organization.webDomains.enableEmailAccounts') }}</div></a>
        <a v-if="domain.actions.renew" href="#" @click="showActionModal(domain.name, 'renew')"><div class="action_dropdown">{{ $t('organization.webDomains.renew') }}</div></a>
        <a v-if="domain.actions.reactivate" href="#" @click="showActionModal(domain.name, 'reactivate')"><div class="action_dropdown">{{ $t('organization.webDomains.reactivate') }}</div></a>
        <a v-if="domain.actions.self_manage" href="#" @click="showActionModal(domain.name, 'self_manage')"><div class="action_dropdown">{{ $t('organization.webDomains.selfManage') }}</div></a>
        <a v-if="domain.actions.transfer_in" href="#" @click="showActionModal(domain.name, 'transfer_in')"><div class="action_dropdown">{{ $t('organization.webDomains.transferIn') }}</div></a>
        <a v-if="domain.actions.request_transfer" href="#" @click="showActionModal(domain.name, 'request_transfer')"><div class="action_dropdown">{{ $t('organization.webDomains.requestTransfer') }}</div></a>
        <a v-if="domain.actions.remove" color="danger" href="#" @click="showActionModal(domain.name, 'remove')"><div class="action_dropdown">{{ $t('form.remove') }}</div></a>
      </div>
    </va-button-dropdown>
  </div>
  <h5 class="va-h5 mb-3">{{ domain.name }}</h5>
  <va-alert
    v-if="domain.email.status == 'activating'"
    dense
    color="primary"
    icon="star"
    outline
    class="mb-4 py-2"
  >
    {{ $t('organization.webDomains.emailBeingActivated') }} <span v-if="domain.type == 'connection'">{{ $t('organization.webDomains.dnsRecordsMust') }}</span>
  </va-alert>
  <va-alert
    v-if="domain.email.status == 'waiting_dns'"
    dense
    color="warning"
    icon="warning"
    outline
    class="mb-2 py-2"
  >
    <span v-if="domain.type == 'connection'">{{ $t('organization.webDomains.beforeEmailAccounts') }}</span>
  </va-alert>
  <form v-if="organizations.length > 1" @submit.prevent="form.post('/settings/domains/'+domain.name)">
    <div class="row my-3">
      <div class="flex flex-col xs12 lg6">
        <va-select
          v-model="form.organization"
          value-by="id"
          text-by="name"
          :label="$t('organization.organization')"
          immediateValidation
          :options="organizations"
          :error="$page.props.errors.organization"
          :error-messages="$page.props.errors.organization"
        />
      </div>
    </div>
    <div class="row ml-1 mb-3">
      <div class="flex flex-col xs12">
        <div>
          <va-button type="submit">{{ $t('common.update') }}</va-button>
        </div>
      </div>
    </div>
  </form>
  <div v-if="domain.email.status != 'disabled'">
    <va-list-separator fit />
    <va-scroll-container
      v-if="domain.type =='connection'"
      color="primary"
      horizontal
    >
      <table class="va-table va-table--hoverable mt-3">
        <thead>
          <tr>
            <th>{{ $t('organization.webDomains.type') }}</th>
            <th>{{ $t('organization.webDomains.host') }}</th>
            <th>{{ $t('organization.webDomains.value') }}</th>
            <th>{{ $t('organization.webDomains.priority') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(record, index) in domain.required_records" :key="index">
            <td>
                {{ record.type }}
            </td>
            <td>
                {{ record.host }}
            </td>
            <td style="max-width: 800px; overflow: auto">
                {{ record.value }}
            </td>
            <td>
                {{ record.ttl }}
            </td>
          </tr>
        </tbody>
      </table>
    </va-scroll-container>
  </div>

  <va-list-separator class="my-3" fit />
  <h6 class="va-h6">{{ $t('organization.webDomains.subdomains') }}</h6>
  <div class="row justify-center">
    <va-button @click="showAddRecordModal = true">{{ $t('organization.webDomains.addHost') }}</va-button>
  </div>
  <va-scroll-container
    color="primary"
    horizontal
  >
    <table class="va-table va-table--hoverable mt-3">
      <thead>
        <tr>
          <th style>{{ $t('organization.webDomains.type') }}</th>
          <th>{{ $t('organization.webDomains.host') }}</th>
          <th style="width: 30%">{{ $t('organization.webDomains.domainName') }}</th>
          <th style="width: 50%">{{ $t('organization.webDomains.ipValueApp') }}</th>
          <th v-if="domain.type === 'managed'">{{ $t('organization.webDomains.ttl') }}</th>
          <th style="width: 200px"></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="subdomain in subdomains.slice((curPageValue - 1), (curPageValue - 1 + pageSize))" :key="subdomain.name" style="min-height:300px;">
          <td>{{ subdomain.type }}</td>
          <td>{{ subdomain.host }}</td>
          <td>{{ subdomain.name }}</td>
          <td style="max-width: 800px; overflow: auto">
            <Link v-if="subdomain.app !== null" :href="'/apps/'+subdomain.app.id+'/edit'">{{ subdomain.app.name }}</Link>
            <span v-else>{{ subdomain.value }}</span>
          </td>
          <td v-if="domain.type === 'managed'">{{ subdomain.ttl }}</td>
          <td class="va-text-right">
            <va-button v-if="subdomain.can.edit"
              class="mr-2"
              :title="$t('organization.webDomains.editName', { name: subdomain.name })"
              @click="showEditRecord(subdomain)">
              {{ $t('common.edit') }}
            </va-button>
            <va-button v-if="subdomain.can.delete"
              color="danger"
              :title="$t('organization.webDomains.removeName', { name: subdomain.name })"
              @click="deleteSubdomain(subdomain)">
              {{ $t('common.delete') }}
            </va-button>
          </td>
        </tr>
      </tbody>
    </table>
  </va-scroll-container>
  <va-modal v-model="showAddRecordModal" no-outside-dismiss no-padding size="small" class="p-0">
    <template #content="{ ok }">
      <form @submit.prevent="recordForm.post('/settings/domains/'+domain.name+'/subdomains', {onSuccess: () => recordAdded()})">
        <va-card-title class="m-0">{{ $t('organization.webDomains.addHost') }}</va-card-title>
        <va-card-content class="m-0">
          <va-select v-model="recordForm.type"
            v-if="domain.type === 'managed'"
            immediateValidation
            id="type"
            required-mark
            :label="$t('organization.webDomains.type')"
            class="mb-3"
            value-by="value"
            text-by="text"
            :options="recordOptions"
            :error="$page.props.errors.type"
            :error-messages="$page.props.errors.type"
            />
          <va-input v-model="recordForm.host"
            id="host"
            required-mark
            immediateValidation
            :label="$t('organization.webDomains.host')"
            class="mb-3"
            :error="$page.props.errors.host"
            :error-messages="$page.props.errors.host"
            />
          <va-select v-model="recordForm.app"
            v-if="recordForm.type === 'app'"
            immediateValidation
            id="app"
            required-mark
            :label="$t('admin.apps.appWord')"
            class="mb-3"
            :options="all_app_instance_options"
            value-by="value"
            text-by="text"
            :error="$page.props.errors.app"
            :error-messages="$page.props.errors.app"
            />
          <va-input v-model="recordForm.value"
            v-else
            id="value"
            required-mark
            immediateValidation
            :label="$t('organization.webDomains.value')"
            class="mb-3"
            :error="$page.props.errors.value"
            :error-messages="$page.props.errors.value"
            />
          <va-input v-model="recordForm.ttl"
            v-if="domain.type === 'managed' && recordForm.type !== 'app'"
            type="number"
            id="ttl"
            required-mark
            immediateValidation
            :label="$t('organization.webDomains.ttl')"
            class="mb-3"
            :error="$page.props.errors.ttl"
            :error-messages="$page.props.errors.ttl"
            />
        </va-card-content>
        <va-card-actions align="right" class="">
          <va-button color="textInverted" :disabled="form.processing" @click="ok">{{ $t('common.cancel') }}</va-button>
          <va-button type="submit" :disabled="form.processing" id="submit" class="mr-2 mb-2">{{ $t('common.submit') }}</va-button>
        </va-card-actions>
      </form>
    </template>
  </va-modal>
  <va-modal v-model="showEditRecordModal"
    no-outside-dismiss
    no-padding
    size="small"
    class="p-0"
    :before-close="closeEditRecordModal"
    >
    <template #content="{ ok }">
      <form @submit.prevent="recordForm.put('/settings/domains/'+domain.name+'/subdomains/'+subdomainToUpdate.id, {onSuccess: () => recordAdded()})">
        <va-card-title class="m-0">{{ $t('organization.webDomains.editDnsRecord') }}</va-card-title>
        <va-card-content class="m-0">
          <va-select v-model="recordForm.type"
            v-if="domain.type === 'managed'"
            immediateValidation
            id="type"
            required-mark
            :label="$t('organization.webDomains.type')"
            class="mb-3"
            value-by="value"
            text-by="text"
            :options="recordOptions"
            :error="$page.props.errors.type"
            :error-messages="$page.props.errors.type"
            />
          <va-input v-model="recordForm.host"
            id="host"
            required-mark
            immediateValidation
            :label="$t('organization.webDomains.host')"
            class="mb-3"
            :error="$page.props.errors.host"
            :error-messages="$page.props.errors.host"
            />
          <va-select v-model="recordForm.app"
            v-if="recordForm.type === 'app'"
            immediateValidation
            id="app"
            required-mark
            :label="$t('admin.apps.appWord')"
            class="mb-3"
            :options="all_app_instance_options"
            value-by="value"
            text-by="text"
            :error="$page.props.errors.app"
            :error-messages="$page.props.errors.app"
            />
          <va-input v-model="recordForm.value"
            v-else
            id="value"
            required-mark
            immediateValidation
            :label="$t('organization.webDomains.value')"
            class="mb-3"
            :error="$page.props.errors.value"
            :error-messages="$page.props.errors.value"
            />
          <va-input v-model="recordForm.ttl"
            v-if="domain.type === 'managed' && recordForm.type !== 'app'"
            type="number"
            id="ttl"
            required-mark
            immediateValidation
            :label="$t('organization.webDomains.ttl')"
            class="mb-3"
            :error="$page.props.errors.ttl"
            :error-messages="$page.props.errors.ttl"
            />
        </va-card-content>
        <va-card-actions align="right" class="">
          <va-button color="textInverted" :disabled="form.processing" @click="ok">{{ $t('common.cancel') }}</va-button>
          <va-button type="submit" :disabled="form.processing" id="submit" class="mr-2 mb-2">{{ $t('common.submit') }}</va-button>
        </va-card-actions>
      </form>
    </template>
  </va-modal>
  <va-modal v-model="showDeleteSubdomainModal" hide-default-actions :title="$t('organization.webDomains.removeSubdomainTitle', { name: subdomainToDelete.name })"
    :message="$t('organization.webDomains.removeSubdomainMessage', { name: subdomainToDelete.name })">
    <template #footer>
      <va-button color="backgroundSecondary" @click="showDeleteSubdomainModal = false">
        {{ $t('common.cancel') }}
      </va-button>
      <va-button id="delete" color="danger"
        @click="remove.delete('/settings/domains/'+domain.name+'/subdomains/'+subdomainToDelete.id); showDeleteSubdomainModal = false">{{ $t('common.delete') }}</va-button>
    </template>
  </va-modal>

  <enable-email-domain-modal :showModal="actions.enable_email" @update:showModal="actions.enable_email = $event" :domain="domain" />
  <reactivate-domain-modal :showModal="actions.reactivate" @update:showModal="actions.reactivate = $event" :domain="domain" />
  <request-transfer-modal :showModal="actions.requeset_transfer" @update:showModal="actions.requeset_transfer = $event" :domain="domain" />
  <renew-domain-modal :showModal="actions.renew" @update:showModal="actions.renew = $event" :domain="domain" />
  <self-manage-domain-modal :showModal="actions.self_manage" @update:showModal="actions.self_manage = $event" :domain="domain" />
  <transfer-in-domain-modal :showModal="actions.transfer_in" @update:showModal="actions.transfer_in = $event" :domain="domain" />
  <remove-domain-modal :showModal="actions.remove" @update:showModal="actions.remove = $event" :primary_app="domain.primary_app.name" :domain="domain" />
</template>

<script lang="ts">
export default {
  layout: (h, page) => {
    return h(AppLayout, () => h(WebDomainsLayout, () => page))
  },
  props: {
    domain: Object,
    subdomains: Object,
    all_app_instance_options: Object,
    organizations: Object,
    errors: Object
  },
  data () {
    let redirectTo = this.domain.redirect_to
    if (!redirectTo) {
      redirectTo = 0
    }

    return {
      curPageValue: 1,
      pageSize: 100,
      actions: {
        enable_email: false,
        renew: false,
        reactivate: false,
        self_manage: false,
        transfer_in: false,
        request_transfer: false,
        remove: false
      },
      showDeleteSubdomainModal: false,
      subdomainToDelete: {},
      subdomainToUpdate: {},
      form: useForm({
        organization: this.domain.organization.id,
        redirect_to: redirectTo
      }),
      remove: useForm({}),
      recordForm: useForm({
        host: '',
        value: '',
        ttl: '',
        type: 'app',
        app: null
      }),
      showAddRecordModal: false,
      showEditRecordModal: false,
      recordOptions: [
        { value: 'app', text: this.$t('admin.apps.appWord') },
        { value: 'A', text: this.$t('organization.webDomains.aRecord') },
        { value: 'AAAA', text: this.$t('organization.webDomains.aaaaRecord') },
        { value: 'ALIAS', text: this.$t('organization.webDomains.aliasRecord') },
        { value: 'CAA', text: this.$t('organization.webDomains.caaRecord') },
        { value: 'CNAME', text: this.$t('organization.webDomains.cnameRecord') },
        { value: 'MX', text: this.$t('organization.webDomains.mxRecord') },
        { value: 'MXE', text: this.$t('organization.webDomains.mxeRecord') },
        { value: 'NS', text: this.$t('organization.webDomains.nsRecord') },
        { value: 'TXT', text: this.$t('organization.webDomains.txtRecord') },
        { value: 'URL', text: this.$t('organization.webDomains.urlRecord') },
        { value: 'URL301', text: this.$t('organization.webDomains.url301Record') },
        { value: 'FRAME', text: this.$t('organization.webDomains.frameRecord') }
      ]
    }
  },
  computed: {
    number_of_actions () {
      let n = 0
      Object.values(this.domain.actions).forEach((value) => {
        if (value) {
          n++
        }
      })

      return n
    }
  },
  methods: {
    showActionModal (domain, action) {
      this.actions[action] = true
      // this.showDomainAction = !this.showDomainAction
    },
    showEditRecord (subdomain) {
      this.recordForm.host = subdomain.host
      this.recordForm.value = subdomain.value
      this.recordForm.ttl = subdomain.ttl
      this.recordForm.type = subdomain.type

      if (subdomain.app) {
        this.recordForm.app = subdomain.app.id
      }

      this.subdomainToUpdate = subdomain
      this.showEditRecordModal = true
    },
    deleteSubdomain (subdomain) {
      this.deleteSubdomain.domain = subdomain.id
      this.showDeleteSubdomainModal = true
      this.subdomainToDelete = subdomain
    },
    recordAdded () {
      this.recordForm.clearErrors()
      this.recordForm.reset()
      this.showAddRecordModal = false
      this.showEditRecordModal = false
    },
    closeEditRecordModal () {
      this.recordForm.reset()
      this.showEditRecordModal = false
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

  div.action_dropdown {
    min-height: 30px;
    display: flex;
    justify-content: left;
    align-items: center;
  }
</style>
