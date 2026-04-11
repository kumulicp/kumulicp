<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import EmailAccountsLayout from './EmailAccountsMain.vue'
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <Head>
    <title>Email Forwarders - Control Panel</title>
  </Head>
  <div class="email-forwarders">
    <div class="row justify-end">
      <va-button v-model="showAddEmailForwarder" class="mb-3" @click="showAddEmailForwarder = !showAddEmailForwarder">Add
        Forwarder</va-button>
    </div>
    <div class="table-wrapper">
      <table class="va-table va-table--hoverable mt-3">
        <thead>
          <tr>
            <th>Forwarder Email</th>
            <th>Destination Email</th>
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
      <va-card-title>Add Forwarder/Destination</va-card-title>
      <va-card-content>
        <form @submit.prevent="addEmailForwarder">
          <p class="mb-3">
            Email forwarders allow you to have a single email address that forwards emails to a group within your organization.
            Example: elders@example.com might forward emails to your organization's board members: bob@examle.com, rob@example.com, and
            robert@example.com.
          </p>
          <va-select v-model="add.forwarder"
            immediateValidation
            :options="emailForwarders"
            class="mb-3"
            text-by="text"
            value-by="value"
            :error="$page.props.errors.forwarder"
            :error-messages="$page.props.errors.forwarder"
            label="Forwarder Email Addresss"
            placeholder="-- choose an email address to forward from --" />
          <va-input v-model="add.new_forwarder"
            immediateValidation
            v-if="add.forwarder == 'new'"
            :error="$page.props.errors.new_forwarder"
            :error-messages="$page.props.errors.new_forwarder"
            class="mb-3"
            label="Email">
            <template #append>
              <va-chip v-if="domains.length == 1" shadow class="mb-2 mr-2" color="primary">@</va-chip>
              <va-select v-model="add.domain"
                immediateValidation
                v-else-if="domains.length > 1"
                :options="domains"
                text-by="text"
                value-by="value"
                label="Domain"
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
            label="Destination Email Address"
            messages="Any email address will work here"
            placeholder="bob@email.com"
            :error="$page.props.errors.destination"
            :error-messages="$page.props.errors.destination" />
          <div class="row justify-end">
            <va-button color="backgroundSecondary" class="mr-3">Cancel</va-button>
            <va-button type="submit" :disabled="add.processing">Add Forwarder</va-button>
          </div>
        </form>
      </va-card-content>
    </template>
  </va-modal>
  <va-modal v-model="showRemoveEmailForwarder" hide-default-actions :title="'Remove ' + removeEmailForwarder + '?'"
    :message="'Are you sure you want to remove ' + removeEmailForwarder + '? This action is permanent.'">
    <template #footer>
      <va-button color="backgroundSecondary" @click="showRemoveEmailForwarder = !showRemoveEmailForwarder">
        Cancel
      </va-button>
      <va-button color="danger"
        @click="remove.delete('/settings/email/forwarder/'+removeEmailForwarder); showRemoveEmailForwarder = !showRemoveEmailForwarder">Delete</va-button>
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
      text: 'Create New Email',
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
