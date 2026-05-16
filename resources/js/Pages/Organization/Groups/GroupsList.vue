<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { Link, useForm } from '@inertiajs/vue3'

</script>

<template>
  <Head>
    <title>{{ $t('organization.groups.groups') }} - Control Panel</title>
  </Head>
  <va-card class="mb-4">
    <va-card-title>{{ $t('organization.groups.groups') }} </va-card-title>
    <va-card-content>
      <div v-if="$page.props.auth.status !== 'deactivated'" class="row justify-center">
        <va-button id="addGroup" @click="showAddGroup = !showAddGroup">{{ $t('organization.groups.createGroup') }}</va-button>
      </div>
      <va-modal v-model="showAddGroup" no-outside-dismiss no-padding>
        <template #content="{ ok }">
          <form @submit.prevent="save()">
            <va-card-title>{{ $t('organization.groups.addGroup') }}</va-card-title>
            <va-card-content>
              <va-input v-model="form.name"
                id="name"
                immediateValidation
                required-mark :label="$t('organization.groups.groupName')"
                class="mb-3"
                :error="$page.props.errors.name"
                :error-messages="$page.props.errors.name" />
              <va-select v-model="form.category"
                id="category"
                required-mark
                immediateValidation
                :label="$t('organization.groups.groupType')"
                :options="categoryOptions"
                text-by="text"
                value-by="value"
                :placeholder="form.category.text"
                :error="$page.props.errors.category"
                :error-messages="$page.props.errors.category" />
            </va-card-content>
            <va-card-actions align="right">
              <va-button color="textInverted" :disabled="form.processing" @click="ok">{{ $t('common.cancel') }}</va-button>
              <va-button type="submit" id="submit" class="mr-2 mb-2" :disabled="form.processing">{{ $t('common.submit') }}</va-button>
            </va-card-actions>
          </form>
        </template>
      </va-modal>
      <template v-if="groups.length === 0">
        <div class="row m-5">
          <div class="flex lg12 va-text-center mt-4">
            <va-icon name="fa-user-group" style="color: var(--va-list-item-label-caption-color)"  size="5rem" />
          </div>
        </div>
        <div class="row">
          <div class="flex lg12 va-text-center mb-1">
            <h2 class="va-h2 mb-3 sm12" style="color: var(--va-list-item-label-caption-color)">{{ $t('organization.groups.noGroups') }}</h2>
          </div>
        </div>
      </template>
      <va-scroll-container
        v-else
        color="warning"
        horizontal
      >
        <table class="va-table va-table--hoverable mt-3">
          <thead>
            <tr>
              <th style="width:20rem">{{ $t('common.name') }}</th>
              <th>{{ $t('organization.groups.category') }}</th>
              <th style="width:6rem"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="group in groups.slice(initialListValue, (initialListValue + pageSize))" :key="'group' + group.slug" class="table-row">
              <td>
                <Link :href="'/groups/'+group.slug">{{ group.name }}</Link>
              </td>
              <td>
                {{ group.category }}
              </td>
              <td class="va-text-right">
                <va-button
                  color="danger"
                  :id="'delete'+group"
                  @click="showRemoveGroupModal(group)">
                  {{ $t('common.delete') }}
                </va-button>
              </td>
            </tr>
          </tbody>
        </table>
      </va-scroll-container>

      <va-pagination v-if="numberOfGroups > pageSize" class="mt-3 mb-3 justify-center" v-model="curPageValue" :pages="pages" input />
    </va-card-content>
  </va-card>
  <va-modal v-model="showRemoveGroup" hide-default-actions :title="$t('organization.groups.removeTitle', { name: removeGroup.name })"
    :message="$t('organization.groups.removeMessage', { name: removeGroup.name })">
    <template #footer="{ cancel }">
      <va-button color="backgroundSecondary" @click="cancel">
        {{ $t('common.cancel') }}
      </va-button>
      <va-button color="danger"
        id="delete"
        @click="remove.delete('/groups/'+removeGroup.name); showRemoveGroup = !showRemoveGroup">{{ $t('common.delete') }}</va-button>
    </template>
  </va-modal>
</template>

<script lang="ts">
export default {
  layout: (h, page) => h(AppLayout, [page]),
  props: {
    categories: Object,
    errors: Object
  },
  data () {
    return {
      curPageValue: 1,
      pageSize: 10,
      showAddGroup: false,
      showRemoveGroup: false,
      removeGroup: '',
      categoryOptions: [
        {
          text: this.$t('organization.groups.department'),
          value: 'departments'
        },
        {
          text: this.$t('organization.groups.team'),
          value: 'teams'
        },
        {
          text: this.$t('organization.groups.project'),
          value: 'projects'
        },
        {
          text: this.$t('organization.groups.ministry'),
          value: 'ministries'
        },
        {
          text: this.$t('organization.groups.other'),
          value: 'others'
        }
      ],
      form: useForm({
        name: '',
        category: ''
      }),
      remove: useForm({})
    }
  },
  computed: {
    groups () {
      const groups = []
      for (const category of Object.values(this.categories)) {
        for (const group of Object.values(category.groups)) {
          groups.push({
            slug: group.slug,
            name: group.name,
            category: category.name
          })
        }
      }
      return groups
    },
    numberOfGroups () {
      return this.groups.length
    },
    pages () {
      return Math.ceil(this.numberOfGroups / this.pageSize)
    },
    initialListValue () {
      return (this.curPageValue - 1) * this.pageSize
    }
  },
  methods: {
    save () {
      this.form.post('/groups')
    },
    showRemoveGroupModal (group) {
      this.removeGroup = group
      this.showRemoveGroup = !this.showRemoveGroup
    }
  }
}
</script>

<style lang="scss">
.row-equal .flex {
  .va-card {
    height: 100%;
  }
}

.groups-list {
  .va-card {
    margin-bottom: 0 !important;

    &__title {
      display: flex;
      justify-content: space-between;
    }
  }
}
</style>
