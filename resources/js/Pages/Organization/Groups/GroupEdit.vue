<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <Head>
    <title>Edit {{ group.name }} Group - Control Panel</title>
  </Head>
  <va-card class="mb-4">
    <va-card-title>Edit {{ group.name }} Group</va-card-title>
    <form @submit.prevent="form.put('/groups/'+group.slug)">
      <va-card-content>
        <div class="row">
          <div class="flex flex-col xs12 lg6 mb-2">
            <va-input v-model="form.name"
              label="Group Name"
              id="name"
              immediateValidation
              :error="$page.props.errors.name"
              :error-messages="$page.props.errors.name"
            ></va-input>
          </div>
          <div class="flex flex-col xs12 lg6 mb-2">
            <va-select v-model="form.category"
              label="Category"
              id="category"
              immediateValidation
              :options="groupTypes"
              text-by="text"
              value-by="value"
              :error="$page.props.errors.category"
              :error-messages="$page.props.errors.category"
            />
          </div>
        </div>
        <div class="row">
          <div class="flex flex-col xs12 lg6 mb-2">
            <va-select
              v-model="form.managers"
              label="Managers"
              id="managers"
              immediateValidation
              value-by="value"
              text-by="text"
              multiple
              searchable
              :options="users"
              :error="$page.props.errors.managers"
              :error-messages="$page.props.errors.managers"
            />
          </div>
          <div class="flex flex-col xs12 lg6 mb-2">
            <va-select
              v-model="form.members"
              label="Members"
              id="members"
              immediateValidation
              value-by="value"
              text-by="text"
              multiple
              searchable
              :options="users"
              :error="$page.props.errors.members"
              :error-messages="$page.props.errors.members"
            />
          </div>
        </div>
        <va-list>
          <template v-for="(extension, index) in extensions" :key="index">
            <template v-if="!extension.conditional || (extension.conditional && form['extensions'][extension.conditional])">
              <va-list-separator class="my-1" fit />
              <va-list-item class="py-3">
                <va-list-item-section label>
                  <va-list-item-label>
                    <h5>{{ extension.label }}</h5>
                  </va-list-item-label>
                </va-list-item-section>
                <va-list-item-section>
                  <template v-if="extension.input == 'va-checkbox'">
                    <component :is="extension.input"
                      v-model="form['extensions'][extension.id]"
                    />
                    <span v-if="extensions[extension.id] == true && form['extensions'][extension.id] == false">
                      error
                    </span>
                  </template>
                  <component v-else-if="extension.input == 'va-select'"
                    :is="extension.input"
                    v-model="form['extensions'][extension.id]"
                    :id="extension.id"
                    :options="extension.options"
                    text-by="text"
                    value-by="value"
                  />
                  <template v-else>
                    {{ extension.input }}
                  </template>
                  <div v-if="extension.warning && form['extensions'][extension.id] != extension.value" class="subscription_warning"><b>**Warning**</b> {{ extension.warning }}</div>
                </va-list-item-section>
              </va-list-item>
            </template>
          </template>
        </va-list>
        <va-modal
          v-model="showNextcloudAlertModal"
          hide-default-actions
          no-dismiss
        >
          <template #header>
            <h4 class="va-h4"><va-icon color="danger" name="fa-solid fa-triangle-exclamation" /> Deleting Nextcloud Team Folder</h4>
          </template>
          <p><span class="va-text-bold">Warning:</span> This will destroy any data you have this in folder. Are you absolutely certain you want to remove this Nextcloud Team Folder?</p>
          <p><span class="va-text-bold">Note:</span>This action won't take affect until you click "Update Group"</p>
          <template #footer>
            <va-button @click="cancelRemoveGroupFolder">No! I want to keep it</va-button>
            <va-button color="danger" @click="showNextcloudAlertModal = !showNextcloudAlertModal">Yes, I'm removing the folder</va-button>
          </template>
        </va-modal>
        <va-button type="submit"
          id="submit"
          :disabled="form.processing"
          class="mr-2 mb-2"
        >
          Update Group
        </va-button>
      </va-card-content>
    </form>
  </va-card>
</template>

<script lang="ts">
export default {
  layout: (h, page) => h(AppLayout, [page]),
  props: {
    group: Object,
    managers: Object,
    members: Object,
    users: Object,
    extensions: Object,
    errors: Object,
    category: Object
  },
  data () {
    const appExtensions = []
    Object.values(this.extensions).forEach((value) => {
      appExtensions[value.id] = value.value
    })

    return {
      memberData: [this.users[0], this.users],
      managerData: this.managers,
      groupTypes: [
        {
          text: 'Department',
          value: 'departments'
        },
        {
          text: 'Team',
          value: 'teams'
        },
        {
          text: 'Project',
          value: 'projects'
        },
        {
          text: 'Ministry',
          value: 'ministries'
        },
        {
          text: 'Other',
          value: 'others'
        }
      ],
      appExtensions,
      showNextcloudAlertModal: false,
      form: useForm({
        original_name: this.group.name,
        name: this.group.name,
        category: this.category,
        members: this.members,
        managers: this.managers,
        extensions: Object.assign({}, appExtensions)
      })
    }
  },
  watch: {
    'form.extensions.nextcloud_group_folder' (value) {
      if (this.appExtensions.nextcloud_group_folder === true && value === false) {
        this.showNextcloudAlertModal = true
      }
    }
  },
  methods: {
    cancelRemoveGroupFolder () {
      this.form.extensions.nextcloud_group_folder = true
      this.showNextcloudAlertModal = false
    }
  }
}
</script>

<style lang="scss">
.subscription_warning {
 color: var(--va-red-800)
}
</style>
