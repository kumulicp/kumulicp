<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import DomainReadinessPanel from '@/components/DomainReadinessPanel.vue'
import WebDomainsLayout from './WebDomainsLayout.vue'
import EnableEmailDomainModal from './modals/EnableEmailDomainModal.vue'
import RenewDomainModal from './modals/RenewDomainModal.vue'
import ReactivateDomainModal from './modals/ReactivateDomainModal.vue'
import RequestTransferModal from './modals/RequestTransferModal.vue'
import SelfManageDomainModal from './modals/SelfManageDomainModal.vue'
import RemoveDomainModal from './modals/RemoveDomainModal.vue'
import TransferInDomainModal from './modals/TransferInDomainModal.vue'
import { Link, useForm } from '@inertiajs/vue3'
import { useToast } from 'vuestic-ui'

</script>
<template>
  <Head>
    <title>{{ $t('organization.webDomains.domainSettings') }} - Control Panel</title>
  </Head>
  <div class="domain-header">
    <div class="domain-header__title">
      <h4 class="va-h4 domain-header__name">{{ domain.name }}</h4>
      <div class="domain-header__badges">
        <va-chip v-if="domainTypeLabel" size="small" outline color="secondary">{{ domainTypeLabel }}</va-chip>
        <va-chip size="small" :color="emailStatus.color" :outline="emailStatus.outline" class="domain-header__email">
          <va-icon name="fa-envelope" size="small" class="mr-1" />
          {{ emailStatus.label }}
        </va-chip>
      </div>
    </div>
    <va-button-dropdown v-if="number_of_actions > 0"
      size="medium"
      :label="$t('organization.webDomains.actions')"
      color="secondary"
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
    class="mb-4 py-2"
  >
    <span v-if="domain.type == 'connection'">{{ $t('organization.webDomains.beforeEmailAccounts') }}</span>
    <span v-else>{{ $t('organization.webDomains.waitingDns') }}</span>
  </va-alert>
  <domain-readiness-panel v-if="domain.email.readiness" :domain-name="domain.name" :readiness="domain.email.readiness" />
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

  <va-collapse v-if="domain.email.status != 'disabled' && domain.required_records.length > 0" class="records-panel mb-3">
    <template #header-content>
      <div class="records-panel__header">
        <va-icon name="fa-envelope" color="secondary" />
        <span class="records-panel__title">{{ $t('organization.webDomains.emailRecords') }}</span>
        <span class="va-text-secondary">{{ $t('organization.webDomains.recordCount', { count: domain.required_records.length }) }}</span>
      </div>
    </template>
    <p class="va-text-secondary mb-3">
      {{ domain.type === 'connection' ? $t('organization.webDomains.emailRecordsConnectionHelp') : $t('organization.webDomains.emailRecordsManagedHelp') }}
    </p>
    <table class="va-table dns-table">
      <thead>
        <tr>
          <th>{{ $t('organization.webDomains.type') }}</th>
          <th>{{ $t('organization.webDomains.host') }}</th>
          <th>{{ $t('organization.webDomains.value') }}</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="record in domain.required_records" :key="record.type + record.host">
          <td><span class="record-type">{{ record.type }}</span></td>
          <td class="dns-table__host">{{ record.host }}</td>
          <td class="dns-table__value-cell">
            <div class="dns-table__value-wrap">
              <div class="dns-table__value" tabindex="0">{{ record.value }}</div>
              <va-button
                size="small"
                preset="plain"
                :icon="copiedKey === record.type + record.host ? 'fa-check' : 'fa-copy'"
                :color="copiedKey === record.type + record.host ? 'success' : 'secondary'"
                :title="$t('organization.webDomains.copyValue')"
                :aria-label="$t('organization.webDomains.copyValue')"
                @click="copyValue(record)"
              />
            </div>
          </td>
        </tr>
      </tbody>
    </table>
  </va-collapse>

  <div class="section-header">
    <h6 class="va-h6 section-header__title">{{ $t('organization.webDomains.dnsRecords') }}</h6>
    <va-button size="small" icon="fa-plus" @click="showAddRecordModal = true">{{ $t('organization.webDomains.addRecord') }}</va-button>
  </div>
  <div v-if="subdomains.length === 0" class="va-text-secondary py-4 text-center">{{ $t('organization.webDomains.noDnsRecords') }}</div>
  <va-scroll-container
    v-else
    color="primary"
    horizontal
  >
    <table class="va-table va-table--hoverable dns-table">
      <thead>
        <tr>
          <th>{{ $t('organization.webDomains.type') }}</th>
          <th>{{ $t('organization.webDomains.host') }}</th>
          <th>{{ $t('organization.webDomains.value') }}</th>
          <th v-if="domain.type === 'managed'">{{ $t('organization.webDomains.ttl') }}</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="subdomain in subdomains.slice((curPageValue - 1), (curPageValue - 1 + pageSize))" :key="subdomain.name">
          <td><span class="record-type">{{ subdomain.type }}</span></td>
          <td class="dns-table__host" :title="subdomain.name">{{ subdomain.host }}</td>
          <td class="dns-table__value-cell">
            <div class="dns-table__value-wrap">
              <div class="dns-table__value" tabindex="0">
                <Link v-if="subdomain.app !== null" :href="'/apps/'+subdomain.app.id+'/edit'">{{ subdomain.app.name }}</Link>
                <span v-else>{{ subdomain.value }}</span>
              </div>
            </div>
          </td>
          <td v-if="domain.type === 'managed'" class="dns-table__ttl">{{ subdomain.ttl }}</td>
          <td class="dns-table__actions">
            <va-button v-if="subdomain.can.edit"
              size="small"
              preset="plain"
              icon="fa-pen"
              color="secondary"
              :title="$t('organization.webDomains.editName', { name: subdomain.name })"
              :aria-label="$t('organization.webDomains.editName', { name: subdomain.name })"
              @click="showEditRecord(subdomain)"
            />
            <va-button v-if="subdomain.can.delete"
              size="small"
              preset="plain"
              icon="fa-trash"
              color="danger"
              :title="$t('organization.webDomains.removeName', { name: subdomain.name })"
              :aria-label="$t('organization.webDomains.removeName', { name: subdomain.name })"
              @click="deleteSubdomain(subdomain)"
            />
          </td>
        </tr>
      </tbody>
    </table>
  </va-scroll-container>
  <va-modal v-model="showAddRecordModal" no-outside-dismiss no-padding size="small" class="p-0">
    <template #content="{ ok }">
      <form @submit.prevent="recordForm.post('/settings/domains/'+domain.name+'/subdomains', {onSuccess: () => recordAdded()})">
        <va-card-title class="m-0">{{ $t('organization.webDomains.addDnsRecord') }}</va-card-title>
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

  <enable-email-domain-modal :showModal="actions.enable_email" @update:showModal="actions.enable_email = $event" :domain="domain" :preview="enable_preview" />
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
    enable_preview: Object,
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
      copiedKey: null,
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
      toast: useToast(),
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
    domainTypeLabel () {
      return {
        managed: this.$t('organization.webDomains.typeManaged'),
        connection: this.$t('organization.webDomains.typeConnection')
      }[this.domain.type] ?? null
    },
    emailStatus () {
      const statuses = {
        active: { label: this.$t('organization.webDomains.emailStatusActive'), color: 'success', outline: false },
        activating: { label: this.$t('organization.webDomains.emailStatusActivating'), color: 'primary', outline: false },
        waiting_dns: { label: this.$t('organization.webDomains.waitingDns'), color: 'warning', outline: false }
      }

      return statuses[this.domain.email.status] ?? { label: this.$t('organization.webDomains.emailStatusDisabled'), color: 'secondary', outline: true }
    },
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
    async copyValue (record) {
      try {
        await navigator.clipboard.writeText(record.value)
      } catch {
        this.toast.init({ message: this.$t('organization.webDomains.copyFailed'), color: 'danger' })
        return
      }

      this.copiedKey = record.type + record.host
      setTimeout(() => { this.copiedKey = null }, 2000)
    },
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

  .domain-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 0.75rem 1rem;
    margin-bottom: 1.25rem;

    &__title {
      display: flex;
      align-items: center;
      flex-wrap: wrap;
      gap: 0.5rem 0.875rem;
      min-width: 0;
    }

    &__name {
      margin: 0;
      overflow-wrap: anywhere;
    }

    &__badges {
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }
  }

  .section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 0.5rem;
    padding-top: 0.75rem;
    border-top: 1px solid var(--va-background-border);

    &__title {
      margin: 0;
    }
  }

  .records-panel {
    border: 1px solid var(--va-background-border);
    border-radius: 0.5rem;
    overflow: hidden;

    &__header {
      display: flex;
      align-items: center;
      flex-wrap: wrap;
      gap: 0.25rem 0.625rem;
    }

    &__title {
      font-weight: 600;
    }
  }

  // Compact record tables: tight cells. Full width on mobile and tablet; on a wide screen the table stops growing
  // at a readable width instead of spanning the whole card. The value column takes the space the others leave and
  // scrolls on its own when a TXT/DKIM value is longer than that.
  .va-table.dns-table {
    width: 100%;

    th,
    td {
      padding: 0.375rem 0.75rem;
      vertical-align: middle;
    }

    @media (min-width: 1200px) {
      max-width: 64rem;
    }
  }

  .dns-table__value-cell {
    width: 100%;
    max-width: 0;
  }

  .dns-table__value-wrap {
    display: flex;
    align-items: center;
    gap: 0.25rem;
  }

  .dns-table__value {
    flex: 1 1 auto;
    min-width: 0;
    overflow-x: auto;
    white-space: nowrap;
    scrollbar-width: thin;
  }

  // The app's base styles set a font on every element, so the children (span, link) need it too
  .dns-table__value,
  .dns-table__value * {
    font-family: Menlo, Consolas, "DejaVu Sans Mono", "Liberation Mono", monospace;
  }

  .dns-table__host {
    white-space: nowrap;
    font-family: Menlo, Consolas, "DejaVu Sans Mono", "Liberation Mono", monospace;
  }

  .dns-table__ttl {
    white-space: nowrap;
    color: var(--va-text-secondary);
  }

  .dns-table__actions {
    width: 1%;
    white-space: nowrap;
    text-align: right;
  }

  .record-type {
    display: inline-block;
    min-width: 3rem;
    padding: 0.0625rem 0.5rem;
    border-radius: 0.25rem;
    background: var(--va-background-element);
    font-size: 0.75rem;
    font-weight: 600;
    text-align: center;
  }
</style>
