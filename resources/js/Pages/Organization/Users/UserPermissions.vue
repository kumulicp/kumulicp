<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import UserLayout from './UserLayout.vue'
import { useForm, Link } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <Head>
    <title>User Permissions - Control Panel</title>
  </Head>
  <va-modal v-model="showAppPermissionsModal" no-outside-dismiss no-padding size="small" class="p-0">
    <template #content>
      <va-card-title class="m-0"> Update {{ appPermissions.name }} </va-card-title>
      <va-card-content class="m-0"><!--
        <va-switch v-if="typeof appPermissions.allow === 'boolean'"
          v-model="appAccessType[appPermissions.id]"
          label="control panel"
          true-value="standard"
          false-value="none"
          @update:modelValue="updateRoleOptions(appPermissions)"
          />-->
        <va-select
            v-if="plan.type === 'app' && filteredPermissions[appPermissions.id]['full'] === true"
            :id="'roles-'+appPermissions.id"
            v-model="appAccessTypeFiltered[appPermissions.id]"
            class="my-2"
            :label="appPermissions.name+' Access Type'"
            messages="Filter app roles based on the access type you want for this user"
            :options="appPermissions.access_types"
            immediateValidation
            text-by="text"
            value-by="value"
            @update:modelValue="updateRoleOptions(appPermissions)"
          />
        <template v-for="(category, index) in filteredPermissions[appPermissions.id]['categories']" :key="index">
          <va-select
            :label="category.name"
            v-if="category.roles.length > 2"
            :id="'roles-'+appPermissions.id"
            v-model="form['permission'][appPermissions.id][category.id]"
            class="my-2"
            :options="category.roles"
            :placeholder="category.active_role"
            :messages="getMessage(appPermissions)"
            text-by="text"
            value-by="value"
            disabled-by="disabled"
            immediateValidation
            :error="errors[appPermissions.id][index]"
            :error-messages="errors[appPermissions.id][index]"
            @click="updateSelectedCategory(appPermissions.id, category.id)"
          />
          <div v-else>
            <va-switch
              v-model="form['permission'][appPermissions.id][category.id]"
              :label="category.name+' '+category['roles'][1]['text']"
              :messages="category.roles[1]['disabled'] ? 'Max users reach' : ''"
              class="my-2"
              immediateValidation
              :true-value="category.roles[1]['value']"
              false-value="none"
              :disabled="category.roles[1]['disabled']"
              @click="permissionsChanged = true"
            />
          </div>
        </template>
      </va-card-content>
      <va-card-actions align="right" class="">
        <va-button @click="updateAccessType(); showAppPermissionsModal = false" id="submit" class="mr-2 mb-2">OK</va-button>
      </va-card-actions>
    </template>
  </va-modal>
  <div class="user-permissions">
    <div class="row">
      <div class="flex flex-col w-full sm12 lg12">
        <h5 class="va-h5 mt-0 pt-0">{{ user.name }}</h5>
        <template v-if="permissions.length === 0">
          <div class="row m-5">
            <div class="flex lg12 va-text-center mt-4">
              <va-icon name="fa-box" style="color: var(--va-list-item-label-caption-color)"  size="5rem" />
            </div>
            <div class="flex flex-col lg12 va-text-center">
              <h2 class="va-h2" style="color: var(--va-list-item-label-caption-color)">No apps have been activated</h2>
            </div>
            <div class="flex flex-col lg12 va-text-center mb-4">
              <Link href="/discover"><va-button color="primary">Discover Apps Here</va-button></Link>
            </div>
          </div>
        </template>
        <form v-else @submit.prevent="updatePermissions">
          <va-select
            v-if="plan.type === 'package' && user.can.change_access_type"
            id="planType"
            label="User Access Type"
            v-model="form.user.access_type"
            class="my-2"
            messages="Filter app roles based on the access type you want for this user"
            :options="access_types"
            immediateValidation
            text-by="text"
            value-by="value"
            disabled-by="disabled"
            @update:modelValue="updateAllRoleOptions"
          />
          <va-alert
            v-else-if="plan.type === 'package' && !user.can.change_access_type"
            outline
            class="mb-4"
          >
            You are an admin. Only another admin can remove your admin permission
          </va-alert>
          <table class="va-table va-table--striped mb-2">
            <thead>
              <tr>
                <th style="width: 15rem">App</th>
                <th v-if="plan.type === 'app'">Access Type</th>
                <th>Permissions</th>
                <th style="width: 15rem"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(permission, index) in filteredPermissions" :key="index">
                <td>
                  {{ permission.name }} <a href="#" @click="showDescription(permission)"><va-icon name="fa-circle-info" title="Role Descriptions" /></a>
                </td>
                <template v-if="plan.type === 'app'">
                  <td>{{ accessTypes[appAccessType[permission.id]] }}</td>
                </template>
                <td v-if="permission.categories && permission.categories.length > 0">
                  <template v-for="(category, index) in permission.categories" :key="index">
                    <template v-if="form['permission'][permission.id][category.id] !== 'none'">
                      <va-chip outline class="mr-1" :title="allAppDescriptions[permission.id][category.id]">{{ roleNames[form['permission'][permission.id][category.id]] }}</va-chip>
                    </template>
                  </template>
                </td>
                <td v-else><va-chip outline class="mr-1">No Access</va-chip></td>
                <td>
                  <a v-if="plan.type === 'app' || (plan.type === 'package' && form.user.access_type !== 'none' && permission.categories && permission.categories.length > 0)" href="#" @click="showAppPermissions(permission)"><va-icon name="fa-lock" title="Update Permissions" /> Update Permissions</a>
                </td>
              </tr>
            </tbody>
          </table>
          <va-button type="submit" id="submit" class="mb-2 mr-2" :disabled="form.processing">Submit</va-button>
          <va-button @click="resetPermissions" id="reset" class="mb-2" color="backgroundSecondary" :disabled="form.processing || !form.isDirty">Reset</va-button>
        </form>
      </div>
    </div>
  </div>
  <va-modal v-model="showConfirmAccessTypeChange" no-outside-dismiss no-padding size="small" class="p-0">
    <template #content>
      <form @submit.prevent="form.post('/users')">
        <va-card-title class="m-0">Confirm Permission Changes</va-card-title>
        <va-card-content class="m-0">
          <template v-if="accessTypeChanges.length > 0">
            <b>This user's access has changed</b>
            <div class="mt-2 mb-3">
              <ul class="va-unordered">
                <li v-for="(change, index) in accessTypeChanges" :key="index">
                  {{ change }}
                </li>
              </ul>
            </div>
            <va-icon name="fa-warning" color="warning" class="mr-2" /> Note: this may affect your subscription price
          </template>
          <template v-else>
            Your subscription won't be affected. Are you sure you want to update this user's permissions?
          </template>
        </va-card-content>
        <va-card-actions align="right" class="">
          <va-button
            color="backgroundSecondary"
            @click="showConfirmAccessTypeChange = !showConfirmAccessTypeChange"
          >
            Cancel
          </va-button>
          <va-button
            @click="form.post('/users/'+user.id+'/permissions')"
            :disabled="form.processing"
          >
            Yes, Update Permissions
          </va-button>
        </va-card-actions>
      </form>
    </template>
  </va-modal>
  <va-modal v-model="showDescriptionModal" no-outside-dismiss no-padding size="small" class="p-0">
    <template #content>
      <form @submit.prevent="form.post('/users')">
        <va-card-title class="m-0">Description of {{ appDescription.name }} roles</va-card-title>
        <va-card-content class="m-0">
          <template v-for="(category, index) in appDescription.categories" :key="index">
            <template v-for="(role, index) in category.roles" :key="index">
              <template v-if="role.value !== 'none'">
                <h6 class="va-h6">{{ category.name }} {{ role.text }}</h6>
                <p class="mb-3">{{ role.description }}</p>
              </template>
            </template>
          </template>
        </va-card-content>
        <va-card-actions align="right">
          <va-button
            color="primary"
            @click="showDescriptionModal = !showDescriptionModal"
          >
            Sounds good!
          </va-button>
        </va-card-actions>
      </form>
    </template>
  </va-modal>
</template>

<script lang="ts">
export default {
  layout: (h, page) => {
    return h(AppLayout, () => h(UserLayout, () => page))
  },
  props: {
    user: Object,
    permissions: Object,
    plan: Object,
    access_types: Object
  },
  data () {
    // Build form model
    const permissions = {}
    const errors = {}
    const roleAccessTypes = {}
    const appAccessType = {}
    const appAccessTypeFiltered = {}
    const roleNames = {}
    const allAppDescriptions = {}
    Object.values(this.permissions).forEach((permission) => {
      permissions[permission.id] = {}
      allAppDescriptions[permission.id] = {}
      errors[permission.id] = {}
      roleAccessTypes[permission.id] = {}
      appAccessType[permission.id] = permission.access_type
      appAccessTypeFiltered[permission.id] = permission.access_type

      Object.values(permission.categories).forEach((category) => {
        roleAccessTypes[permission.id][category.id] = {}
        Object.values(category.roles).forEach((role) => {
          roleAccessTypes[permission.id][role.value] = role.access_type
          roleNames[role.value] = category.name + ' ' + role.text
          if (category.active_role === role.text) {
            allAppDescriptions[permission.id][category.id] = role.description
            permissions[permission.id][category.id] = role.value
            errors[permission.id][category.id] = null
          }
        })
      })
    })
    const accessTypes = {}

    for (const accessType of Object.entries(this.access_types)) {
      accessTypes[accessType.value] = accessType.text
    }

    return {
      form: useForm({
        organization: this.user.organization,
        user: {
          access_type: this.user.access_type
        },
        permission: permissions
      }),
      showConfirmAccessTypeChange: false,
      currentAccessType: this.user.access_type,
      roleNames,
      selected_app: null,
      selected_category: null,
      initialLoad: true,
      roleAccessTypes,
      unfilteredPermissions: '',
      filteredPermissions: JSON.parse(JSON.stringify(this.permissions)),
      accessTypeChanges: [],
      appAccessType,
      appAccessTypeFiltered,
      errors,
      accessTypes,
      showDescriptionModal: false,
      appDescription: [],
      appPermissions: {},
      showAppPermissionsModal: false,
      allAppDescriptions
    }
  },
  mounted () {
    if (this.plan.type === 'app') {
      Object.values(this.permissions).forEach((app) => {
        this.updateRoleOptions(app)
      })
    } else if (this.plan.type === 'package') {
      this.updateAllRoleOptions()
    }
    this.form.defaults()
    this.initialLoad = false
  },
  methods: {
    getMessage (app) {
      if (!app.edit) {
        return 'You have reached the max users for this app'
      }
    },
    updatePermissions () {
      this.checkAccessType()
      if (this.currentAccessType !== this.form.access_type) {
        this.showConfirmAccessTypeChange = true
      } else {
        this.form.post('/users/' + this.user.id + '/permissions')
      }
    },
    updateSelectedCategory (app, category) {
      this.selected_app = app
      this.selected_category = category
    },
    updateAccessType () {
      this.checkAccessType()
    },
    checkAccessType () {
      let accessType = this.user.access_type
      this.accessTypeChanges = []

      if (this.plan.type === 'app') {
        for (const [id, app] of Object.entries(this.permissions)) {
          let appAccessType = 'none'

          Object.values(this.form.permission[app.id]).forEach((role) => {
            const roleAccessType = this.roleAccessTypes[app.id][role]

            if (roleAccessType === 'standard') {
              appAccessType = 'standard'
            } else if (appAccessType !== 'standard' && roleAccessType === 'basic') {
              appAccessType = 'basic'
            } else if (appAccessType !== 'standard' && appAccessType !== 'basic' && roleAccessType === 'minimal') {
              appAccessType = 'minimal'
            }
          })

          if (appAccessType === 'standard') {
            accessType = 'standard'
          } else if (accessType !== 'standard' && appAccessType === 'basic') {
            accessType = 'basic'
          } else if (accessType !== 'standard' && appAccessType !== 'basic' && appAccessType === 'minimal') {
            accessType = 'minimal'
          }

          if (this.permissions[id].access_type !== appAccessType) {
            this.accessTypeChanges.push(this.accessTypes[appAccessType] + ' access for ' + this.permissions[id].name)
          }
          this.appAccessType[app.id] = appAccessType
        }
      } else if (this.plan.type === 'package') {
        const confirmedAccessType = this.confirmAccessType()
        if (this.user.access_type !== confirmedAccessType) {
          if (this.user.access_type === 'standard' && confirmedAccessType === 'basic') {
            this.accessTypeChanges.push('This user is being downgraded to a ' + this.accessTypes[confirmedAccessType])
          } else if ((this.user.access_type === 'basic' || confirmedAccessType === 'none' || confirmedAccessType === 'minimal') && confirmedAccessType === 'standard') {
            this.accessTypeChanges.push('This user is being upgraded to an ' + this.accessTypes[confirmedAccessType])
          } else if ((this.user.access_type === 'standard' || this.user.access_type === 'basic') && confirmedAccessType === 'minimal') {
            this.accessTypeChanges.push('This user is being downgrade to ' + this.accessTypes[confirmedAccessType])
          } else if (this.user.access_type !== 'none' && confirmedAccessType === 'none') {
            this.accessTypeChanges.push('This user is being disabled')
          } else if (this.user.access_type === 'none' && confirmedAccessType !== 'none') {
            this.accessTypeChanges.push('This user is being enabled to a ' + this.accessTypes[confirmedAccessType])
          } else if (this.user.access_type === 'minimal' && confirmedAccessType !== 'none') {
            this.accessTypeChanges.push('This user is being upgraded to a ' + this.accessTypes[confirmedAccessType])
          }
        }
      }
    },
    updateRoleOptions (app) {
      if (!(app.id in this.permissions) || !('categories' in this.permissions[app.id])) {
        return
      }
      const categories = JSON.parse(JSON.stringify(this.permissions[app.id].categories))
      const filteredCategories = []
      let standardUsers = 0
      let basicUsers = 0
      let minimalUsers = 0

      for (const category of categories) {
        const newCategory = category
        this.errors[app.id][category.id] = null
        const filteredRoles = []
        const availableRoleNames = []
        // Will simplify UI if only 1 of each
        for (const role of category.roles) {
          if (role.access_type === 'standard') {
            standardUsers++
          }
          if (role.access_type === 'basic') {
            basicUsers++
          }
          if (role.access_type === 'minimal') {
            minimalUsers++
          }
        }
        if (standardUsers <= 1 && basicUsers <= 1 && minimalUsers <= 1) {
          this.appAccessTypeFiltered[app.id] = 'standard'
          this.filteredPermissions[app.id].full = false
        } else {
          this.filteredPermissions[app.id].full = true
        }
        for (const role of category.roles) {
          if (this.appAccessTypeFiltered[app.id] === 'standard' ||
              (this.appAccessTypeFiltered[app.id] === 'basic' && (role.access_type === 'basic' || role.access_type === 'minimal' || role.access_type === 'none')) ||
              (this.appAccessTypeFiltered[app.id] === 'minimal' && (role.access_type === 'minimal' || role.access_type === 'none'))) {
            filteredRoles.push(role)
            availableRoleNames.push(role.value)
          }
        }
        if (!(availableRoleNames.includes(this.form.permission[app.id][category.id]))) {
          this.form.permission[app.id][category.id] = 'none'
          this.errors[app.id][category.id] = "This user's access type as been downgrade and role must be changed"
        }
        if (availableRoleNames.length === 2 && !this.initialLoad) {
          this.form.permission[app.id][category.id] = availableRoleNames[1]
        }
        newCategory.roles = filteredRoles
        if (filteredRoles.length > 1) {
          filteredCategories.push(newCategory)
        }
      }

      this.filteredPermissions[app.id].categories = filteredCategories
    },
    updateAllRoleOptions () {
      const apps = JSON.parse(JSON.stringify(this.permissions))
      const filteredApps = {}
      for (const app of Object.entries(apps)) {
        const newApp = app

        const filteredCategories = []
        if ('categories' in app) {
          for (const category of app.categories) {
            const newCategory = category
            this.errors[app.id][category.id] = null
            const filteredRoles = []
            const availableRoleNames = []
            for (const role of category.roles) {
              if (this.form.user.access_type === 'standard' ||
                  (this.form.user.access_type === 'basic' && (role.access_type === 'basic' || role.access_type === 'minimal' || role.access_type === 'none')) ||
                  (this.form.user.access_type === 'minimal' && (role.access_type === 'minimal' || role.access_type === 'none'))) {
                filteredRoles.push(role)
                availableRoleNames.push(role.value)
              }
            }

            // Remove role if it no longer exists after filtering roles
            if (!(availableRoleNames.includes(this.form.permission[app.id][category.id]))) {
              this.form.permission[app.id][category.id] = 'none'
              this.errors[app.id][category.id] = "This user's access type as been changed and role must be changed"
            }

            newCategory.roles = filteredRoles
            if (filteredRoles.length > 1) { // There will always be a 'none' role
              filteredCategories.push(newCategory)
            }
          }

          newApp.categories = filteredCategories
        }
        filteredApps[newApp.id] = newApp
      }
      this.filteredPermissions = filteredApps
    },
    confirmAccessType () {
      const appAccessTypes = []
      for (const app of Object.entries(this.permissions)) {
        Object.values(this.form.permission[app.id]).forEach((role) => {
          appAccessTypes.push(this.roleAccessTypes[app.id][role])
        })
      }

      if (appAccessTypes.includes('standard')) {
        return 'standard'
      } else if (appAccessTypes.includes('basic')) {
        return 'basic'
      } else if (appAccessTypes.includes('minimal')) {
        return 'minimal'
      } else {
        return 'none'
      }
    },
    showDescription (app) {
      this.appDescription = app
      this.showDescriptionModal = true
    },
    showAppPermissions (app) {
      this.appPermissions = app
      this.showAppPermissionsModal = true
    },
    resetPermissions () {
      this.filteredPermissions = this.permissions
      this.form.reset()
      this.form.user.access_type = this.currentAccessType
    }
  }
}
</script>

<style></style>
