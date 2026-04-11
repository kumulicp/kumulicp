<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import UserLayout from './UserLayout.vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>

<template>
  <Head>
    <title>User Profile - Control Panel</title>
  </Head>
  <div class="user-view">
    <va-badge
      class="mt-0"
      color="primary"
      offset="20px"
      :text="user.type"
    >
      <h5 class="va-h5 mt-0 pt-0 mr-2">{{ user.name }}</h5>
    </va-badge>
    <div class="row">
      <div class="flex xs12 lg12">
        <va-list>
          <va-list-item class="py-3">
            <va-list-item-section label>
              <va-list-item-label>
                <h5>Username:</h5>
              </va-list-item-label>
            </va-list-item-section>
            <va-list-item-section>
              <va-list-item-label>
                {{ user.id }}
              </va-list-item-label>
            </va-list-item-section>
          </va-list-item>

          <va-list-separator class="my-1" fit />

          <va-list-item class="py-3">
            <va-list-item-section label>
              <va-list-item-label>
                <h5>Personal Email:</h5>
              </va-list-item-label>
            </va-list-item-section>
            <va-list-item-section>
              <va-list-item-label>
                <a :href="'mailto:'+user.personal_email">{{ user.personal_email }}</a>
              </va-list-item-label>
            </va-list-item-section>
          </va-list-item>

          <template v-if="user.org_email && user.org_email.length > 0">
            <va-list-separator class="my-1" fit />

            <va-list-item class="py-3">
              <va-list-item-section label>
                <va-list-item-label>
                  <h5>Organizational Email:</h5>
                </va-list-item-label>
              </va-list-item-section>
              <va-list-item-section>
                <div class="my-1"><a :href="'mailto:' + org_email">{{ user.org_email }}</a></div>
              </va-list-item-section>
            </va-list-item>
          </template>

          <va-list-separator v-if="user.phone_number" class="my-1" fit />

          <va-list-item v-if="user.phone_number" class="py-3">
            <va-list-item-section label>
              <va-list-item-label>
                <h5>Phone Number:</h5>
              </va-list-item-label>
            </va-list-item-section>
            <va-list-item-section>
              <va-list-item-label>
                <a :href="'tel:'+user.phone_number">{{ user.phone_number }}</a>
              </va-list-item-label>
            </va-list-item-section>
          </va-list-item>

          <template v-if="user_groups.length > 0">
            <va-list-separator class="my-1" fit />
            <va-list-item class="py-3">
              <va-list-item-section label>
                <va-list-item-label>
                  <h5>Groups:</h5>
                </va-list-item-label>
              </va-list-item-section>
              <va-list-item-section>
                <va-list-item-label>
                  <template v-for="(group, index) in user_groups" :key="index">
                    <va-chip class="mr-2" outline>{{ group }}</va-chip>
                  </template>
                </va-list-item-label>
              </va-list-item-section>
            </va-list-item>
          </template>

          <template v-for="(permission, index) in user.permissions" :key="index">
            <va-list-separator class="my-1" fit />
            <va-list-item class="py-3">
              <va-list-item-section label>
                <va-list-item-label>
                  <h5>{{ permission.name }}</h5>
                </va-list-item-label>
              </va-list-item-section>
              <va-list-item-section>
                <div>
                  <template v-for="(category, index) in permission.categories" :key="index">
                    <va-chip outline>{{ category.name }} {{ category.access }}</va-chip>
                  </template>
                </div>
              </va-list-item-section>
            </va-list-item>
          </template>

          <template v-for="(storage, index) in user.storage" :key="index">
            <va-list-separator class="my-1" fit />
            <va-list-item class="py-3">
              <va-list-item-section label>
                <va-list-item-label>
                  <h5>{{ storage.app }} Storage:</h5>
                </va-list-item-label>
              </va-list-item-section>
              <va-list-item-section>
                <va-list-item-label>
                  <template v-if="storage.error">
                    Error: could not retrieve storage info
                  </template>
                  <template v-else>
                    {{ storage.quota_used }} / {{ storage.quota_total }} {{ storage.unit }}
                  </template>
                </va-list-item-label>
              </va-list-item-section>
            </va-list-item>
          </template>
        </va-list>
      </div>
    </div>
  </div>
</template>

<script lang="ts">
export default {
  layout: (h, page) => {
    return h(AppLayout, () => h(UserLayout, () => page))
  },
  props: {
    user: Object
  },
  data () {
    const groups = []
    for (const group of Object.entries(this.user.groups)) {
      groups.push(group.name)
    }
    return {
      user_groups: groups
    }
  }
}
</script>

<style>
  .user-view {
    width: 100%;
  }
</style>
