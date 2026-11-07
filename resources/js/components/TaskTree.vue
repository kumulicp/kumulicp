<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
</script>
<template>
  <div class="task-tree">
    <div v-if="parentIds.length > 0" class="task-tree__toolbar">
      <VaButton preset="plain" size="small" icon="unfold_more" @click="expandAll">
        {{ $t('admin.tasks.expandAll') }}
      </VaButton>
      <VaButton preset="plain" size="small" icon="unfold_less" class="ml-3" @click="collapseAll">
        {{ $t('admin.tasks.collapseAll') }}
      </VaButton>
      <span class="task-tree__help">{{ $t('admin.tasks.treeHelp') }}</span>
    </div>
    <VaTreeView
      v-if="nodes.length > 0"
      v-model:checked="checkedModel"
      v-model:expanded="expandedModel"
      :nodes="nodes"
      text-by="description"
      selectable
      selection-type="independent"
    >
      <template #content="node">
        <div class="task-tree__row">
          <div class="task-tree__main">
            <div class="task-tree__title">
              <span class="task-tree__id">#{{ node.id }}</span>
              {{ node.description }}
            </div>
            <div class="task-tree__meta">{{ meta(node) }}</div>
            <div v-if="node.error_message" class="task-tree__error" :title="node.error_message">
              {{ node.error_message }}
            </div>
          </div>
          <div class="task-tree__side">
            <VaBadge :color="statusColor(node.status)" :text="statusLabel(node.status)" />
            <Link :href="'/admin/server/tasks/' + node.id">
              <VaButton
                preset="plain"
                icon="visibility"
                color="primary"
                class="ml-2"
                :title="$t('admin.tasks.details')"
              />
            </Link>
            <VaButton
              preset="plain"
              icon="fa-trash-restore"
              color="primary"
              class="ml-2"
              :disabled="liveMode || !node.can_retry"
              :title="liveMode ? $t('admin.tasks.disableLiveMode') : $t('admin.tasks.retry')"
              @click="$emit('retry', node.id)"
            />
            <VaButton
              preset="plain"
              icon="cancel"
              color="warning"
              class="ml-2"
              :disabled="liveMode || !node.can_cancel"
              :title="liveMode ? $t('admin.tasks.disableLiveMode') : $t('admin.tasks.cancel')"
              @click="$emit('cancel', node.id)"
            />
            <VaButton
              preset="plain"
              icon="delete"
              color="danger"
              class="ml-2"
              :disabled="liveMode || !node.can_delete"
              :title="liveMode ? $t('admin.tasks.disableLiveMode') : $t('admin.tasks.delete')"
              @click="$emit('delete', node.id)"
            />
          </div>
        </div>
      </template>
    </VaTreeView>
    <div v-else class="task-tree__empty">{{ $t('admin.tasks.noTasks') }}</div>
  </div>
</template>

<script lang="ts">
// Task statuses and the translation key of each one's label
const STATUS_KEYS = {
  pending: 'pending',
  ready: 'ready',
  queued: 'queued',
  in_progress: 'inProgress',
  complete: 'completed',
  failed: 'failed',
  blocked: 'blocked',
  cancelled: 'cancelled'
}

export default {
  props: {
    // Tasks, each with the tasks it waits for in `children`
    nodes: { type: Array, default: () => [] },
    // Ids of the checked tasks
    checked: { type: Array, default: () => [] },
    // Ids of the tasks whose children are showing
    expanded: { type: Array, default: () => [] },
    liveMode: { type: Boolean, default: false },
    // Left out where every task is known to belong to the same organization
    showOrganization: { type: Boolean, default: true }
  },
  emits: ['update:checked', 'update:expanded', 'retry', 'cancel', 'delete'],
  computed: {
    checkedModel: {
      get () {
        return this.checked
      },
      set (value) {
        this.$emit('update:checked', value)
      }
    },
    expandedModel: {
      get () {
        return this.expanded
      },
      set (value) {
        this.$emit('update:expanded', value)
      }
    },
    // Ids of the tasks that have others beneath them
    parentIds () {
      const ids = []
      const visit = (nodes) => nodes.forEach((node) => {
        if (node.children && node.children.length > 0) {
          ids.push(node.id)
          visit(node.children)
        }
      })
      visit(this.nodes)

      return ids
    }
  },
  methods: {
    expandAll () {
      this.$emit('update:expanded', this.parentIds)
    },
    collapseAll () {
      this.$emit('update:expanded', [])
    },
    meta (node) {
      return [
        this.showOrganization ? node.organization : '',
        node.application,
        node.time
      ].filter((part) => part).join(' · ')
    },
    statusLabel (status) {
      return STATUS_KEYS[status] ? this.$t('admin.tasks.' + STATUS_KEYS[status]) : status
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
    }
  }
}
</script>

<style lang="scss">
.task-tree {
  &__toolbar {
    display: flex;
    align-items: center;
    padding: 0 6px 8px;
  }

  &__help {
    margin-left: auto;
    font-size: 0.8rem;
    opacity: 0.6;
  }

  // Vuestic sizes a node's body and its children at 100% beside an arrow,
  // checkbox or indent, which pushes the right edge further out at every level
  .va-tree-node-content__body {
    width: auto;
    min-width: 0;
  }

  .va-tree-node-children {
    box-sizing: border-box;
  }

  &__row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 4px 12px;
    width: 100%;
  }

  &__main {
    flex: 1 1 18rem;
    min-width: 0;
  }

  &__side {
    display: flex;
    align-items: center;
    flex: 0 0 auto;
  }

  &__id {
    opacity: 0.6;
    margin-right: 4px;
  }

  &__meta {
    font-size: 0.8rem;
    opacity: 0.6;
  }

  &__error {
    font-size: 0.8rem;
    color: var(--va-danger);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  &__empty {
    padding: 16px;
    text-align: center;
    opacity: 0.6;
  }
}
</style>
