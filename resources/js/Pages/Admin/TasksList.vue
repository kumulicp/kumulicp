<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { Link } from '@inertiajs/vue3'
import axios from 'axios'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <Head>
    <title>Tasks - Control Panel</title>
  </Head>
  <va-card class="mb-4">
    <va-card-title>Tasks</va-card-title>
    <va-card-content>
      <div class="row">
        <div class="flex flex-col md4">
          <div class="item">
            <VaSelect
              v-model="filterApp"
              label="Apps"
              :options="apps"
              immediateValidation
              @update:modelValue="updateTaskList"
              clearable
              value-by="id"
              text-by="name"
              placeholder="All"
            />
          </div>
        </div>
        <div class="flex flex-col md4">
          <div class="item">
            <VaSelect
              v-model="filterStatus"
              label="Status"
              :options="statuses"
              immediateValidation
              @update:modelValue="updateTaskList"
              clearable
              placeholder="All"
            />
          </div>
        </div>
        <div class="flex flex-col" style="flex-grow:1">
          <div class="item va-text-right">
            <VaSwitch v-model="liveMode"
              label="Live Updates"
              @update:modelValue="changeLiveMode"
              class="mt-3"
            />
          </div>
        </div>
        <div class="flex flex-col">
          <div class="item va-text-right">
            <VaButtonDropdown
              label="Actions"
              class="mt-3"
            >
              <Link href="/admin/server/tasks/run_schedule"><div class="py-2">Run Scheduled Tasks</div></Link>
              <Link href="/admin/server/tasks/restart_queue"><div class="py-2">Restart Queue</div></Link>
              <Link href="/admin/server/tasks/dummy"><div class="py-2">Add Dummy Task</div></Link>
            </VaButtonDropdown>
          </div>
        </div>
      </div>
      <va-scroll-container
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
          <template #cell(actions)="{ row, rowIndex, isExpanded }">
            <VaButton
              preset="plain"
              icon="fa-trash-restore"
              color="primary"
              class="ml-3"
              :disabled="liveMode"
              :title="liveMode ? 'Disable live mode to use actions' : ''"
              @click="restartTaskById(rowIndex)"
            />
            <VaButton
              preset="plain"
              icon="delete"
              color="danger"
              class="ml-3"
              :disabled="liveMode"
              :title="liveMode ? 'Disable live mode to use actions' : ''"
              @click="deleteTaskById(rowIndex)"
            />
            <VaButton
              v-if="hasError(rowIndex)"
              preset="plain"
              :icon="isExpanded ? 'va-arrow-up': 'va-arrow-down'"
              color="primary"
              class="ml-3"
              :disabled="liveMode"
              :title="liveMode ? 'Disable live mode to use actions' : ''"
              @click="row.toggleRowDetails()"
            />
          </template>
          <template #expandableRow="{ rowData }">
            <div class="flex gap-2">
              <div class="pl-2">
                <b>Error message:</b> {{ rowData.error_message }}
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
        { text: 'All', value: '' },
        { text: 'Failed', value: 'failed' },
        { text: 'Pending', value: 'pending' },
        { text: 'In Progress', value: 'in_progress' },
        { text: 'Completed', value: 'complete' }
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
        status: vueState.filterStatus.value
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
