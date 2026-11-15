<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import TaskTree from '@/components/TaskTree.vue'
import { Link } from '@inertiajs/vue3'
import axios from 'axios'
import { useToast } from 'vuestic-ui'

</script>
<template>
  <Head>
    <title>{{ $t('admin.tasks.tasks') }} - Control Panel</title>
  </Head>
  <va-card class="mb-4">
    <va-card-title>{{ $t('admin.tasks.tasks') }}</va-card-title>
    <va-card-content>
      <div class="row">
        <div class="flex flex-col md2">
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
        <div class="flex flex-col md2">
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
        <div class="flex flex-col md2">
          <div class="item">
            <VaSelect
              v-model="perPage"
              :label="$t('admin.tasks.perPage')"
              :options="perPageOptions"
              @update:modelValue="changePerPage"
            />
          </div>
        </div>
        <div class="flex flex-col md3">
          <div class="item">
            <VaButtonToggle
              v-model="view"
              :options="viewOptions"
              preset="secondary"
              border-color="primary"
              class="mt-3"
              @update:modelValue="changeView"
            />
          </div>
        </div>
        <div class="flex flex-col" style="flex-grow:1; flex-shrink:0">
          <div class="item va-text-right whitespace-nowrap">
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
              <Link href="/admin/server/tasks/run_schedule" method="post" as="button" class="block w-full cursor-pointer text-left text-(--va-primary) hover:underline"><div class="py-2">{{ $t('admin.tasks.runScheduledTasks') }}</div></Link>
              <Link href="/admin/server/tasks/restart_queue" method="post" as="button" class="block w-full cursor-pointer text-left text-(--va-primary) hover:underline"><div class="py-2">{{ $t('admin.tasks.restartQueue') }}</div></Link>
              <Link href="/admin/server/tasks/dummy" method="post" as="button" class="block w-full cursor-pointer text-left text-(--va-primary) hover:underline"><div class="py-2">{{ $t('admin.tasks.addDummyTask') }}</div></Link>
            </VaButtonDropdown>
          </div>
        </div>
      </div>
      <div class="row" v-if="!liveMode && selectedIds.length > 0">
        <div class="flex flex-col" style="flex-grow:1">
          <div class="item">
            {{ $t('admin.tasks.selectedCount', { count: selectedIds.length }) }}
          </div>
        </div>
        <div class="flex flex-col">
          <div class="item">
            <VaButton
              preset="secondary"
              icon="restart_alt"
              color="primary"
              @click="bulk('retry')"
            >
              {{ $t('admin.tasks.retrySelected') }}
            </VaButton>
            <VaButton
              preset="secondary"
              icon="cancel"
              color="warning"
              class="ml-2"
              @click="bulk('cancel')"
            >
              {{ $t('admin.tasks.cancelSelected') }}
            </VaButton>
            <VaButton
              preset="secondary"
              icon="delete"
              color="danger"
              class="ml-2"
              @click="bulk('delete')"
            >
              {{ $t('admin.tasks.deleteSelected') }}
            </VaButton>
          </div>
        </div>
      </div>
      <div v-if="view === 'tree'">
        <TaskTree
          v-model:checked="checkedIds"
          v-model:expanded="expandedIds"
          :nodes="task_list"
          :live-mode="liveMode"
          @retry="retryTask"
          @cancel="cancelTask"
          @delete="deleteTask"
        />
        <div class="row justify-center mt-4" v-if="meta.pages > 1">
          <div class="flex">
            <VaPagination
              :model-value="meta.page"
              :pages="meta.pages"
              @update:modelValue="changePage"
            />
          </div>
        </div>
      </div>
      <va-scroll-container
        v-else
        color="primary"
        horizontal
      >
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
          <template #cell(status)="{ value }">
            <va-badge :color="statusColor(value)" :text="statusLabel(value)" />
          </template>
          <template #cell(actions)="{ row, rowIndex, isExpanded }">
            <Link :href="'/admin/server/tasks/' + task_list[rowIndex].id">
              <VaButton
                preset="plain"
                icon="visibility"
                color="primary"
                class="ml-3"
                :title="$t('admin.tasks.details')"
              />
            </Link>
            <VaButton
              preset="plain"
              icon="restart_alt"
              color="primary"
              class="ml-3"
              :disabled="liveMode || !task_list[rowIndex].can_retry"
              :title="liveMode ? $t('admin.tasks.disableLiveMode') : $t('admin.tasks.retry')"
              @click="retryTaskById(rowIndex)"
            />
            <VaButton
              preset="plain"
              icon="cancel"
              color="warning"
              class="ml-3"
              :disabled="liveMode || !task_list[rowIndex].can_cancel"
              :title="liveMode ? $t('admin.tasks.disableLiveMode') : $t('admin.tasks.cancel')"
              @click="cancelTaskById(rowIndex)"
            />
            <VaButton
              preset="plain"
              icon="delete"
              color="danger"
              class="ml-3"
              :disabled="liveMode || !task_list[rowIndex].can_delete"
              :title="liveMode ? $t('admin.tasks.disableLiveMode') : $t('admin.tasks.delete')"
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
      </va-scroll-container>
    </va-card-content>
  </va-card>
</template>

<script lang="ts">
export default {
  layout: (h, page) => h(AppLayout, [page]),
  emits: ['expand'],
  props: {
    apps: Object
  },
  data () {
    return {
      meta: {},
      task_list: [],
      selectedItems: [],
      checkedIds: [],
      expandedIds: [],
      view: this.savedView(),
      viewOptions: [
        { label: this.$t('admin.tasks.tableView'), value: 'table', icon: 'table_rows' },
        { label: this.$t('admin.tasks.treeView'), value: 'tree', icon: 'account_tree' }
      ],
      filterApp: '',
      filterStatus: '',
      perPage: 20,
      perPageOptions: [10, 20, 50, 100],
      liveMode: true,
      toast: useToast(),
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
        { text: this.$t('admin.tasks.blocked'), value: 'blocked' },
        { text: this.$t('admin.tasks.pending'), value: 'pending' },
        { text: this.$t('admin.tasks.queued'), value: 'queued' },
        { text: this.$t('admin.tasks.ready'), value: 'ready' },
        { text: this.$t('admin.tasks.inProgress'), value: 'in_progress' },
        { text: this.$t('admin.tasks.completed'), value: 'complete' },
        { text: this.$t('admin.tasks.cancelled'), value: 'cancelled' }
      ],
      interval: ''
    }
  },
  computed: {
    // Ids of the checked tasks, whichever view they were checked in
    selectedIds () {
      return this.view === 'tree' ? this.checkedIds : this.selectedItems.map(task => task.id)
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
      const view = vueState.view

      axios.post('/admin/server/tasks/api?page=' + vueState.meta.page, {
        app: vueState.filterApp,
        status: vueState.filterStatus.value,
        per_page: vueState.perPage,
        view
      })
        .then(function (response) {
          // The view changed while this was on its way: it is the wrong shape now
          if (view !== vueState.view) {
            return
          }
          vueState.task_list = response.data.tasks
          vueState.meta = response.data.meta
          vueState.$emit('expand')
        })
    },
    operate (method, url) {
      return axios({ method, url })
        .then(() => this.updateTaskList())
        .catch((error) => this.showError(error))
    },
    retryTaskById (index) {
      this.retryTask(this.task_list[index].id)
    },
    cancelTaskById (index) {
      this.cancelTask(this.task_list[index].id)
    },
    deleteTaskById (index) {
      this.deleteTask(this.task_list[index].id)
    },
    retryTask (id) {
      this.operate('post', '/admin/server/tasks/' + id + '/retry')
    },
    cancelTask (id) {
      if (!window.confirm(this.$t('admin.tasks.confirmCancel'))) {
        return
      }
      this.operate('post', '/admin/server/tasks/' + id + '/cancel')
    },
    deleteTask (id) {
      if (!window.confirm(this.$t('admin.tasks.confirmDelete'))) {
        return
      }
      this.operate('delete', '/admin/server/tasks/' + id)
    },
    bulk (operation) {
      const ids = this.selectedIds
      if (ids.length === 0) {
        return
      }
      if (operation !== 'retry' && !window.confirm(this.$t(operation === 'cancel' ? 'admin.tasks.confirmBulkCancel' : 'admin.tasks.confirmBulkDelete', { count: ids.length }))) {
        return
      }
      axios.post('/admin/server/tasks/bulk/' + operation, { tasks: ids })
        .then((response) => {
          this.clearSelection()
          // Some may not have been allowed: say so
          if (response.data.message) {
            this.toast.init({ message: response.data.message, color: 'warning', duration: 10000 })
          }
          this.updateTaskList()
        })
        .catch((error) => this.showError(error))
    },
    showError (error) {
      this.toast.init({
        message: error.response?.data?.message || error.message,
        color: 'danger',
        duration: 10000
      })
    },
    hasError (id) {
      const task = this.task_list[id]

      return task.error_message !== null && task.error_message !== ''
    },
    statusLabel (status) {
      return this.statuses.find((option) => option.value === status)?.text || status
    },
    statusColor (status) {
      return {
        complete: 'success',
        failed: 'danger',
        blocked: 'danger',
        in_progress: 'info',
        ready: 'info',
        pending: 'warning',
        queued: 'warning'
      }[status] || 'secondary'
    },
    getRowBind (row) {
      if (row.error_message !== null && row.error_message !== '') {
        return {
          class: ['error-alert']
        }
      }
    },
    clearSelection () {
      this.selectedItems = []
      this.checkedIds = []
    },
    savedView () {
      try {
        return window.localStorage.getItem('admin.tasks.view') === 'tree' ? 'tree' : 'table'
      } catch {
        return 'table'
      }
    },
    changeView () {
      try {
        window.localStorage.setItem('admin.tasks.view', this.view)
      } catch {
        // Remembering the choice is a convenience only
      }
      this.clearSelection()
      this.task_list = []
      this.meta.page = 1
      this.updateTaskList()
    },
    changePage (page) {
      this.meta.page = page
      this.updateTaskList()
    },
    changePerPage () {
      this.meta.page = 1
      this.updateTaskList()
    },
    changeLiveMode () {
      if (this.liveMode) {
        this.clearSelection()
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
