<script setup>
import { Link } from '@inertiajs/vue3'

</script>

<template>
  <va-card class="mb-4">
    <va-card-title>{{ $t('organization.dashboard.nextcloudFolders') }}</va-card-title>
    <va-card-content>
      <va-scroll-container horizontal>
        <table class="va-table va-table--striped">
          <thead>
            <tr>
              <th>{{ $t('organization.dashboard.group') }}</th>
              <th>{{ $t('organization.dashboard.storage') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="folder in folders" :key="folder.groupId">
              <td><Link :href="'/groups/'+folder.label">{{ folder.label }}</Link></td>
              <td>{{ folder.size }} / {{ folder.quota }}</td>
            </tr>
          </tbody>
        </table>
      </va-scroll-container>
    </va-card-content>
  </va-card>
</template>

<script>
export default {
  props: {
    folders: Object
  }
}
</script>

<style>
  .va-table {
    width: 100%;
  }
</style>
