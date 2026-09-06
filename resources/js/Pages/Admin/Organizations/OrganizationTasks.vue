<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import OrganizationLayout from './OrganizationLayout.vue'
import axios from 'axios'

</script>
<template>
  <Head>
    <title>{{ organization.name }} {{ $t('admin.tasks.tasks') }} - Control Panel</title>
  </Head>
  <div class="row">
    <div class="flex flex-col md4">
      <div class="item">
        <VaSelect
          v-model="filterApp"
          :label="$t('admin.tasks.apps')"
          :options="apps"
          immediateValidation
          @update:modelValue="updateTaskList"
          clearable
          value-by="id"
          text-by="name"
          :placeholder="$t('admin.tasks.all')"
        />
      </div>
    </div>
    <div class="flex flex-col md4">
      <div class="item">
        <VaSelect
          v-model="filterStatus"
          :label="$t('admin.tasks.status')"
          :options="statuses"
          immediateValidation
          @update:modelValue="updateTaskList"
          clearable
          :placeholder="$t('admin.tasks.all')"
        />
      </div>
    </div>
    <div class="flex flex-col" style="flex-grow:1">
      <div class="item va-text-right">
        <VaSwitch v-model="liveMode"
          :label="$t('admin.tasks.liveUpdates')"
          @update:modelValue="changeLiveMode"
          class="mt-3"
        />
      </div>
    </div>
    <div class="flex flex-col">
      <div class="item va-text-right">
        <VaButtonDropdown
          :label="$t('admin.tasks.actions')"
          class="mt-3"
        >
          <Link href="/admin/server/tasks/run_schedule"><div class="py-2">{{ $t('admin.tasks.runScheduledTasks') }}</div></Link>
          <Link href="/admin/server/tasks/restart_queue"><div class="py-2">{{ $t('admin.tasks.restartQueue') }}</div></Link>
          <Link href="/admin/server/tasks/dummy"><div class="py-2">{{ $t('admin.tasks.addDummyTask') }}</div></Link>
        </VaButtonDropdown>
      </div>
    </div>
  </div>
  <div class="row" v-if="!liveMode && selectedItems.length > 0">
    <div class="flex flex-col" style="flex-grow:1">
      <div class="item">
        {{ $t('admin.tasks.selectedCount', { count: selectedItems.length }) }}
      </div>
    </div>
    <div class="flex flex-col">
      <div class="item">
        <VaButton
          preset="secondary"
          icon="fa-trash-restore"
          color="primary"
          @click="bulkRestart"
        >
          {{ $t('admin.tasks.restartSelected') }}
        </VaButton>
        <VaButton
          preset="secondary"
          icon="delete"
          color="danger"
          class="ml-2"
          @click="bulkDelete"
        >
          {{ $t('admin.tasks.deleteSelected') }}
        </VaButton>
      </div>
    </div>
  </div>
  <VaDataTable
      v-model="selectedItems"
      :items="task_list"
      :columns="columns"
      selectable
      select-mode
      selected-color="primary"
      striped
      :row-bind="getRowBind"
      :current-page="meta.page"
  >
    <template #cell(actions)="{ row, rowIndex, isExpanded }">
      <VaButton
        preset="plain"
        icon="fa-trash-restore"
        color="primary"
        class="ml-3"
        :disabled="liveMode"
        :title="liveMode ? $t('admin.tasks.disableLiveMode') : ''"
        @click="restartTaskById(rowIndex)"
      />
      <VaButton
        preset="plain"
        icon="delete"
        color="danger"
        class="ml-3"
        :disabled="liveMode"
        :title="liveMode ? $t('admin.tasks.disableLiveMode') : ''"
        @click="deleteTaskById(rowIndex)"
      />
      <VaButton
        v-if="hasError(rowIndex)"
        preset="plain"
        :icon="isExpanded ? 'va-arrow-up': 'va-arrow-down'"
        color="primary"
        class="ml-3"
        :disabled="liveMode"
        :title="liveMode ? $t('admin.tasks.disableLiveMode') : ''"
        @click="row.toggleRowDetails()"
      />
    </template>
    <template #expandableRow="{ rowData }">
      <div class="flex gap-2">
        <div class="pl-2">
          <b>{{ $t('admin.tasks.errorMessage') }}:</b> {{ rowData.error_message }}
        </div>
      </div>
    </template>
    <template #bodyAppend>
      <tr>
        <td colspan="7">
          <div class="row justify-center mt-4">
            <div class="flex">
              <VaPagination
                v-model="meta.page"
                v-if="meta.pages > 1"
                :pages="meta.pages"
              />
            </div>
          </div>
        </td>
      </tr>
    </template>
  </VaDataTable>
</template>

<script lang="ts">
export default {
  layout: (h, page) => {
    return h(AppLayout, () => h(OrganizationLayout, () => page))
  },
  props: {
    organization: Object,
    apps: Object
  },
  data () {
    return {
      meta: {},
      task_list: [],
      selectedItems: [],
      filterApp: '',
      filterStatus: '',
      liveMode: true,
      columns: [
        { key: 'id', sortable: true },
        { key: 'organization', sortable: true },
        { key: 'application', sortable: true },
        { key: 'description', sortable: true },
        { key: 'time', sortable: false },
        { key: 'status', sortable: true },
        { key: 'actions', sortable: false }
      ],
      statuses: [
        { text: this.$t('admin.tasks.all'), value: '' },
        { text: this.$t('admin.tasks.failed'), value: 'failed' },
        { text: this.$t('admin.tasks.pending'), value: 'pending' },
        { text: this.$t('admin.tasks.inProgress'), value: 'in_progress' },
        { text: this.$t('admin.tasks.completed'), value: 'complete' }
      ],
      interval: ''
    }
  },
  mounted () {
    // Request updated task json every 3s
    this.interval = setInterval(this.updateTaskList, 3000)
    this.updateTaskList()
  },
  unmounted () {
    clearInterval(this.interval)
  },
  methods: {
    updateTaskList () {
      const vueState = this

      axios.post('/admin/server/tasks/api?page=' + vueState.meta.page, {
        app: vueState.filterApp,
        status: vueState.filterStatus.value,
        organization: vueState.organization.id
      })
        .then(function (response) {
          vueState.task_list = response.data.tasks
          vueState.meta = response.data.meta
          vueState.$emit('expand')
        })
    },
    retryTaskById (id) {
      const vueState = this
      const task = this.task_list[id]
      axios.delete('/admin/server/tasks/' + task.id)
        .then(function () {
          vueState.updateTaskList()
        })
    },
    restartTaskById (id) {
      const vueState = this
      const task = this.task_list[id]
      axios.get('/admin/server/tasks/' + task.id + '/restart')
        .then(function () {
          vueState.updateTaskList()
        })
    },
    deleteTaskById (id) {
      const vueState = this
      const task = this.task_list[id]
      axios.delete('/admin/server/tasks/' + task.id)
        .then(function () {
          vueState.updateTaskList()
        })
    },
    bulkRestart () {
      const vueState = this
      const ids = this.selectedItems.map(task => task.id)
      if (ids.length === 0) {
        return
      }
      axios.post('/admin/server/tasks/bulk/restart', { tasks: ids })
        .then(function () {
          vueState.selectedItems = []
          vueState.updateTaskList()
        })
    },
    bulkDelete () {
      const vueState = this
      const ids = this.selectedItems.map(task => task.id)
      if (ids.length === 0) {
        return
      }
      if (!window.confirm(this.$t('admin.tasks.confirmBulkDelete', { count: ids.length }))) {
        return
      }
      axios.post('/admin/server/tasks/bulk/delete', { tasks: ids })
        .then(function () {
          vueState.selectedItems = []
          vueState.updateTaskList()
        })
    },
    hasError (id) {
      const task = this.task_list[id]

      return task.error_message !== null && task.error_message !== ''
    },
    getRowBind (row) {
      if (row.error_message !== null && row.error_message !== '') {
        return {
          class: ['error-alert']
        }
      }
    },
    changeLiveMode () {
      if (this.liveMode) {
        this.selectedItems = []
        this.interval = setInterval(this.updateTaskList, 3000)
      } else {
        clearInterval(this.interval)
      }
    }
  }
}
</script>

<style lang="scss">
.error-alert {
  background-color: var(--va-warning)
}
</style>
