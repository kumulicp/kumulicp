<script setup>
import { Link } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>

<template>
  <va-card class="mb-4">
    <va-card-title>Nextcloud Team Folders</va-card-title>
    <va-card-content>
      <table class="va-table va-table--striped">
        <thead>
          <tr>
            <th>Group</th>
            <th>Storage</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="folder in folders" :key="folder.groupId">
            <td><Link :href="'/groups/'+folder.label">{{ folder.label }}</Link></td>
            <td>{{ folder.size }} / {{ folder.quota }}</td>
          </tr>
        </tbody>
      </table>
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
