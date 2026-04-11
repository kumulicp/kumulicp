<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import UserLayout from './UserLayout.vue'
import { Link } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <Head>
    <title>User Permissions - Control Panel</title>
  </Head>
  <div class="user-permissions">
    <div class="row">
      <div class="flex flex-col sm12">
        <h5 class="va-h5 mt-0 pt-0">{{ user.name }}</h5>
      </div>
    </div>
    <div class="row">
      <div class="flex flex-col sm12">
        <template v-if="groups.length === 0">
          <div class="row m-5">
            <div class="flex lg12 va-text-center mt-4">
              <va-icon name="fa-user-group" style="color: var(--va-list-item-label-caption-color)"  size="5rem" />
            </div>
          </div>
          <div class="row">
            <div class="flex lg12 va-text-center mb-1">
              <h2 class="va-h2 mb-3 sm12" style="color: var(--va-list-item-label-caption-color)">No Groups Available</h2>
            </div>
          </div>
          <div class="row">
            <div class="flex lg12 va-text-center mb-4">
              <Link href="/groups"><va-button color="primary">Add groups here</va-button></Link>
            </div>
          </div>
        </template>
        <va-scroll-container v-else
          color="warning"
          horizontal
        >
          <table class="va-table va-table--hoverable mt-3">
            <thead>
              <tr>
                <th>Group Name</th>
                <th style="width: 200px"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(group, index) in groups" :key="index">
                <td>
                  {{ group.name }}
                </td>
                <td class="va-text-right">
                  <Link v-if="user_groups.includes(group.slug)" :href="'/users/'+user.id+'/groups/'+group.slug+'/remove'"><va-button color="danger">Remove from group</va-button></Link>
                  <Link v-else :href="'/users/'+user.id+'/groups/'+group.slug+'/add'"><va-button color="primary">Add to group</va-button></Link>
                </td>
              </tr>
            </tbody>
          </table>
        </va-scroll-container>
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
    user: Object,
    groups: Object
  },
  data () {
    const groups = []
    for (const group of Object.entries(this.user.groups)) {
      groups.push(group.slug)
    }

    return {
      user_groups: groups
    }
  }
}
</script>

<style></style>
