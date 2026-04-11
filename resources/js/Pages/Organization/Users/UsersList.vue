<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3'
import { ref } from 'vue'
import { useInputMask, createRegexMask } from 'vuestic-ui'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const phoneNumber = ref()
useInputMask(createRegexMask(/(\+\d \(\d{3}\)|\d{3}) (\d){3}-(\d){4}/), phoneNumber)
</script>
<template>
  <Head>
    <title>Users - Control Panel</title>
  </Head>
  <div class="users-list">
    <div class="row">
      <div class="flex flex-col xs12">
        <va-card class="mb-4">
          <va-card-title>Users</va-card-title>
          <va-card-content>
            <div class="row justify-center">
              <va-button v-if="can.add_user" id="createUser" class="" @click="showAddUser = !showAddUser">Create User</va-button>
              <va-modal v-if="can.add_user" v-model="showAddUser" no-outside-dismiss no-padding size="small" class="p-0">
                <template #content="{ ok }">
                  <form @submit.prevent="form.post('/users')">
                    <va-card-title class="m-0"> Add User </va-card-title>
                    <va-card-content class="m-0">
                      <va-input v-model="form.username"
                        immediateValidation
                        id="username"
                        required-mark
                        label="Username"
                        class="mb-3"
                        :error="$page.props.errors.username"
                        :error-messages="$page.props.errors.username" />
                      <va-input v-model="form.first_name"
                        id="firstName"
                        required-mark
                        immediateValidation
                        label="First name"
                        class="mb-3"
                        :error="$page.props.errors.first_name"
                        :error-messages="$page.props.errors.first_name" />
                      <va-input v-model="form.last_name"
                        id="lastName"
                        required-mark
                        immediateValidation
                        label="Last name"
                        class="mb-3"
                        :error="$page.props.errors.last_name"
                        :error-messages="$page.props.errors.last_name" />
                      <va-input v-model="form.personal_email"
                        id="personalEmail"
                        required-mark
                        immediateValidation
                        label="Personal email"
                        class="mb-3"
                        :error="$page.props.errors.personal_email"
                        :error-messages="$page.props.errors.personal_email" />
                      <va-input v-model="form.phone_number"
                        id="phoneNumber"
                        immediateValidation
                        ref="phoneNumber"
                        placeholder="### ### ####"
                        label="Phone number"
                        :error="$page.props.errors.phone_number"
                        :error-messages="$page.props.errors.phone_number" />
                    </va-card-content>
                    <va-card-actions align="right" class="">
                      <va-button color="textInverted" :disabled="form.processing" @click="ok">Cancel</va-button>
                      <va-button type="submit" :disabled="form.processing" id="submit" class="mr-2 mb-2">Submit</va-button>
                    </va-card-actions>
                  </form>
                </template>
              </va-modal>
            </div>
            <va-scroll-container
              color="primary"
              horizontal
            >
              <table class="va-table va-table--hoverable mt-3">
                <thead>
                  <tr>
                    <th style="width:20rem">Name</th>
                    <th>Email</th>
                    <th style="width:10rem">Type</th>
                    <th style="width:50rem"></th>
                    <th style="width:6rem" class="hidden xl:table-cell"></th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(user, i) in users.slice(initialListValue, (initialListValue + pageSize))" :key="i" class="table-row">
                    <td>
                      <Link :href="user.links.show">{{ user.name }}</Link>
                    </td>
                    <td>
                      <a :href="'mailto:'+user.personal_email">{{ user.personal_email }}</a>
                    </td>
                    <td>{{ user.access_type }}</td>
                    <td v-if="can.active" class="va-text-right vertical-middle hidden xl:table-cell">
                      <Link :href="user.links.edit" class="mr-3">Edit Profile</Link>
                      <Link :href="user.links.permissions" class="mr-3">Update Permissions</Link>
                      <Link :href="user.links.reset_password" class="mr-3">Send Password Reset Link</Link>
                    </td>
                    <td class="hidden xl:table-cell">
                      <va-button
                        color="danger"
                        :id="'delete'+user.id"
                        @click="showRemoveUserModal(user.id)"
                        :title="user.can.delete ? 'Delete '+user.name : 'You can\'t delete this user'"
                        :disabled="! user.can.delete">
                        Delete
                      </va-button>
                    </td>
                    <td class="xl:hidden va-text-right">
                      <va-button-dropdown
                        id="actions"
                        label="Actions"
                      >
                        <Link :href="user.links.edit" class="mr-3"><div class="py-1">Edit Profile</div></Link>
                        <Link :href="user.links.permissions" class="mr-3"><div class="py-1">Update Permissions</div></Link>
                        <Link :href="user.links.reset_password" class="mr-3"><div class="py-1">Send Password Reset Link</div></Link>
                        <a href="#"><div class="py-1" color="danger" @click="showRemoveUserModal(user.id)">Remove</div></a>
                      </va-button-dropdown>
                    </td>
                  </tr>
                </tbody>
              </table>
            </va-scroll-container>

            <va-pagination v-if="users.length > pageSize" class="mt-3 mb-3 justify-center" v-model="curPageValue" :pages="pages" input />
          </va-card-content>
        </va-card>
        <va-modal v-model="showRemoveUser" hide-default-actions :title="'Remove ' + removeUser + '?'"
          :message="'Are you sure you want to remove ' + removeUser + '? This action is permanent.'">
          <template #footer>
            <va-button color="backgroundSecondary" @click="showRemoveUser = false">
              Cancel
            </va-button>
            <va-button id="delete" color="danger"
              @click="remove.delete('/users/' + removeUser); showRemoveUser = !showRemoveUser">{{ $t('modal.delete') }}</va-button>
          </template>
        </va-modal>
      </div>
    </div>
  </div>
</template>

<script lang="ts">
export default {
  props: {
    users: Object,
    can: Object,
    errors: Object
  },
  data () {
    return {
      curPageValue: 1,
      pages: Math.ceil(this.users.length / 10),
      pageSize: 10,
      showAddUser: false,
      showRemoveUser: false,
      removeUser: '',
      form: useForm({
        username: '',
        first_name: '',
        last_name: '',
        personal_email: '',
        phone_number: ''
      }),
      remove: useForm({})
    }
  },
  computed: {
    initialListValue () {
      return (this.curPageValue - 1) * this.pageSize
    }
  },
  methods: {
    showRemoveUserModal (user) {
      this.removeUser = user
      this.showRemoveUser = !this.showRemoveUser
    }
  }
}
</script>

<style lang="scss">
.show-on-hover {
  display: none;
}
.table-row {
  height: 55px;
}
.table-row:hover > td .show-on-hover{
  display: inline;
}
</style>
