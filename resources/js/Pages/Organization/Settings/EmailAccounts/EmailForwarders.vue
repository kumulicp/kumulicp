<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import EmailAccountsLayout from './EmailAccountsMain.vue'
import { useForm } from '@inertiajs/vue3'

</script>
<template>
  <Head>
    <title>{{ $t('organization.emailAccounts.emailForwarders') }} - Control Panel</title>
  </Head>
  <div class="email-forwarders">
    <div class="row justify-end">
      <va-button v-model="showAddEmailForwarder" class="mb-3" @click="showAddEmailForwarder = !showAddEmailForwarder">{{ $t('organization.emailAccounts.addForwarder') }}</va-button>
    </div>
    <div class="table-wrapper">
      <table class="va-table va-table--hoverable mt-3">
        <thead>
          <tr>
            <th>{{ $t('organization.emailAccounts.forwarderEmail') }}</th>
            <th>{{ $t('organization.emailAccounts.destinationEmail') }}</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="forwarder in forwarders.slice((curPageValue - 1), (curPageValue - 1 + pageSize % 10))" :key="forwarder.address">
            <td>{{ forwarder.address }}</td>
            <td><span v-for="(destination, index) in forwarder.destinations" :key="index">{{ destination.address }}<br /></span></td>
            <td>
              <span class="clickable-icon"><va-icon name="entypo-cancel" color="danger"
                  @click="showRemoveEmailForwarderModal(forwarder.address)" /></span>
            </td>
          </tr>
        </tbody>
      </table>

      <va-pagination class="mt-3 mb-3" v-model="curPageValue" :total="forwarders.length" :direction-links="false"
        :page-size="pageSize" />

    </div>
  </div>

  <va-modal v-model="showAddEmailForwarder" no-padding no-dismiss>
    <template #content>
      <va-card-title>{{ $t('organization.emailAccounts.addForwarderDestination') }}</va-card-title>
      <va-card-content>
        <form @submit.prevent="addEmailForwarder">
          <p class="mb-3">
            {{ $t('organization.emailAccounts.forwardersDesc') }}
          </p>
          <va-select v-model="add.forwarder"
            immediateValidation
            :options="emailForwarders"
            class="mb-3"
            text-by="text"
            value-by="value"
            :error="$page.props.errors.forwarder"
            :error-messages="$page.props.errors.forwarder"
            :label="$t('organization.emailAccounts.forwarderEmailAddress')"
            :placeholder="$t('organization.emailAccounts.forwarderEmailPlaceholder')" />
          <va-input v-model="add.new_forwarder"
            immediateValidation
            v-if="add.forwarder == 'new'"
            :error="$page.props.errors.new_forwarder"
            :error-messages="$page.props.errors.new_forwarder"
            class="mb-3"
            :label="$t('organization.emailAccounts.email')">
            <template #append>
              <va-chip v-if="domains.length == 1" shadow class="mb-2 mr-2" color="primary">@</va-chip>
              <va-select v-model="add.domain"
                immediateValidation
                v-else-if="domains.length > 1"
                :options="domains"
                text-by="text"
                value-by="value"
                :label="$t('organization.emailAccounts.domain')"
                :error="$page.props.errors.domain"
                :error-messages="$page.props.errors.domain">
                <template #prepend>
                  <va-chip shadow color="primary">@</va-chip>
                </template>
              </va-select>
            </template>
          </va-input>
          <va-input v-model="add.destination"
            immediateValidation
            class="mb-3"
            type="email"
            :label="$t('organization.emailAccounts.destinationEmailAddress')"
            :messages="$t('organization.emailAccounts.destinationMessage')"
            placeholder="bob@email.com"
            :error="$page.props.errors.destination"
            :error-messages="$page.props.errors.destination" />
          <div class="row justify-end">
            <va-button color="backgroundSecondary" class="mr-3">{{ $t('common.cancel') }}</va-button>
            <va-button type="submit" :disabled="add.processing">{{ $t('organization.emailAccounts.addForwarder') }}</va-button>
          </div>
        </form>
      </va-card-content>
    </template>
  </va-modal>
  <va-modal v-model="showRemoveEmailForwarder" hide-default-actions :title="$t('organization.emailAccounts.removeForwarderTitle', { email: removeEmailForwarder })"
    :message="$t('organization.emailAccounts.removeForwarderMessage', { email: removeEmailForwarder })">
    <template #footer>
      <va-button color="backgroundSecondary" @click="showRemoveEmailForwarder = !showRemoveEmailForwarder">
        {{ $t('common.cancel') }}
      </va-button>
      <va-button color="danger"
        @click="remove.delete('/settings/email/forwarder/'+removeEmailForwarder); showRemoveEmailForwarder = !showRemoveEmailForwarder">{{ $t('common.delete') }}</va-button>
    </template>
  </va-modal>
</template>

<script lang="ts">
export default {
  layout: (h, page) => {
    return h(AppLayout, () => h(EmailAccountsLayout, () => page))
  },
  props: {
    forwarders: Object,
    domains: Object,
    default_domain: Object,
    errors: Object
  },
  data () {
    const emailForwarders = []
    let num = 0
    Object.values(this.forwarders).forEach((forwarder) => {
      emailForwarders[num] = {
        text: forwarder.address,
        value: forwarder.id
      }
      num++
    })

    emailForwarders[num] = {
      text: this.$t('organization.emailAccounts.createNewEmail'),
      value: 'new'
    }

    return {
      curPageValue: 1,
      pageSize: 3,
      emailForwarders,
      showAddEmailForwarder: false,
      removeEmailForwarder: '',
      showRemoveEmailForwarder: false,
      createEmail: false,
      add: useForm({
        forwarder: '',
        new_forwarder: '',
        domain: this.default_domain,
        destination: ''
      }),
      remove: useForm({})
    }
  },
  computed: {
    newEmailForwarder () {
      if (this.add.forwarder === 'new') {
        return true
      } else {
        return false
      }
    }
  },
  methods: {
    showRemoveEmailForwarderModal (address) {
      this.removeEmailForwarder = address
      this.showRemoveEmailForwarder = true
    },
    addEmailForwarder () {
      this.add.clearErrors('forwarder', 'destination')
      this.add.post('/settings/email/forwarders', {
        onSuccess: () => {
          this.add.reset('forwarder', 'new_forwarder', 'destination')
          this.showAddEmailForwarder = false
        }
      })
    }
  }
}
</script>

<style lang="scss"></style>
