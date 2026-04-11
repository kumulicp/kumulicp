<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import AppsLayout from '../AppsLayout.vue'
import { Link, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <Head>
    <title>Roles - Control Panel</title>
  </Head>
  <div class="row justify-center">
    <va-button class="" @click="showAddRole = !showAddRole">{{ t('admin.roles.addRole') }}</va-button>
    <va-modal v-model="showAddRole" no-outside-dismiss no-padding size="small" class="p-0">
      <template #content="{ ok }">
        <form @submit.prevent="form.post('/admin/apps/'+app.slug+'/roles')">
          <va-card-title class="m-0">{{ t('admin.roles.addRole') }}</va-card-title>
          <va-card-content class="m-0">
            <va-input v-model="form.sub_name"
              :label="t('admin.roles.name')"
              class="mb-3"
              required-mark
              immediateValidation
              :error="$page.props.errors.sub_name"
              :error-messages="$page.props.errors.sub_name"
              />
            <va-input v-model="form.category"
              :label="t('admin.roles.category')"
              class="mb-3"
              required-mark
              immediateValidation
              :error="$page.props.errors.category"
              :error-messages="$page.props.errors.category"
              />
            <va-input v-model="form.slug"
              :label="t('admin.roles.slug')"
              class="mb-3"
              required-mark
              immediateValidation
              :error="$page.props.errors.slug"
              :error-messages="$page.props.errors.slug"
              />
            <va-input v-model="form.description"
              :label="t('admin.roles.description')"
              :messages="t('admin.roles.descriptionCaption')"
              class="mb-3"
              required-mark
              immediateValidation
              :error="$page.props.errors.description"
              :error-messages="$page.props.errors.description"
              />
            <va-input v-model="form.label"
              :label="t('admin.roles.label')"
              class="mb-3"
              required-mark
              immediateValidation
              :error="$page.props.errors.label"
              :error-messages="$page.props.errors.label"
              />
            <va-select v-model="form.access_type"
              :label="t('admin.roles.accessType')"
              class="mb-3"
              required-mark
              :options="access_types"
              value-by="value"
              immediateValidation
              text-by="text"
              :error="$page.props.errors.access_type"
              :error-messages="$page.props.errors.access_type"
            />
          </va-card-content>
          <va-card-actions align="right">
            <va-button color="textInverted" :disabled="form.processing" @click="ok">Cancel</va-button>
            <va-button type="submit" :disabled="form.processing" class="mr-2 mb-2">Submit</va-button>
          </va-card-actions>
        </form>
      </template>
    </va-modal>
  </div>
  <div class="apps-list">
    <div class="row">
      <div class="flex flex-col xs12 lg12">
        <table class="va-table va-table--hoverable mt-3">
          <thead>
            <tr>
              <th>Name</th>
              <th>Access Type</th>
              <th style="width: 50px">Status</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="role in roles.slice((curPageValue - 1), (curPageValue - 1 + pageSize))" :key="role.name" style="min-height:300px;">
              <td><Link :href="'/admin/apps/'+app.slug+'/roles/'+role.id+'/edit'">{{ role.name }}</Link></td>
              <td>{{ role.access_type }}</td>
              <td>{{ role.status }}</td>
            </tr>
          </tbody>
        </table>

        <va-pagination v-if="roles.length > pageSize" class="mt-3 mb-3 justify-center" v-model="curPageValue" :total="roles.length" :direction-links="false" :page-size="pageSize" />
      </div>
    </div>
  </div>
</template>

<script lang="ts">
export default {
  layout: (h, page) => {
    return h(AppLayout, () => h(AppsLayout, () => page))
  },
  props: {
    app: Object,
    roles: Object,
    errors: Object
  },
  data () {
    return {
      access_types: [
        { value: 'minimal', text: useI18n().t('admin.roles.minimalUsers') },
        { value: 'basic', text: useI18n().t('admin.roles.basicUsers') },
        { value: 'standard', text: useI18n().t('admin.roles.standardUsers') }
      ],
      showAddRole: false,
      curPageValue: 1,
      pageSize: 10,
      form: useForm({
        label: '',
        sub_name: '',
        slug: '',
        description: '',
        category: '',
        access_type: 'minimal'
      })
    }
  }
}
</script>

<style lang="scss">
.clickable-icon {
  transition: 0.3s;

  &:hover {
    opacity: 0.25;
    cursor: pointer;
  }
}
</style>
