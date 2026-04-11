<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import AdminSettings from '@/components/AdminSettings.vue'
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <Head>
    <title>{{ t('admin.roles.editRole') }} - Control Panel</title>
  </Head>
  <va-card class="mb-4">
    <va-card-title>{{ t('form.edit') }} {{ role.name }}</va-card-title>
      <va-card-content>
        <form @submit.prevent="form.post('/admin/apps/'+app.slug+'/roles/'+role.id)">
          <AdminSettings>
            <template #name>{{ t('admin.roles.details') }}</template>
            <template #description>{{ t('admin.roles.detailsDescription') }}</template>
            <template #settings>
              <va-input v-model="form.sub_name"
                :label="t('admin.roles.name')"
                :messages="''"
                class="mb-2"
                required-mark
                immediateValidation
                :error="$page.props.errors.sub_name"
                :error-messages="$page.props.errors.sub_name"
                />
              <va-textarea v-model="form.description"
                :label="t('admin.roles.description')"
                :messages="t('admin.roles.descriptionCaption')"
                :maxLength="500"
                class="full-width mb-2"
                immediateValidation
                :error="$page.props.errors.description"
                :error-messages="$page.props.errors.description"
                />
              <va-input v-model="form.category"
                :label="t('admin.roles.category')"
                :messages="[t('admin.roles.categoryCaption1'), t('admin.roles.categoryCaption2')]"
                class="mb-2"
                immediateValidation
                :error="$page.props.errors.category"
                :error-messages="$page.props.errors.category"
                />
            </template>
        </AdminSettings>
        <va-list-separator class="my-1" fit />
        <AdminSettings>
          <template #settings>
            <va-input v-model="form.label"
              :label="t('admin.roles.label')"
              :messages="t('admin.roles.labelCaption')"
              class="mb-2"
              immediateValidation
              :error="$page.props.errors.label"
              :error-messages="$page.props.errors.label"
              />
            <va-input v-model="form.slug"
              :label="t('admin.roles.slug')"
              :messages="''"
              class="mb-2"
              required-mark
              immediateValidation
              :error="$page.props.errors.slug"
              :error-messages="$page.props.errors.slug"
              />
            <va-select v-model="form.access_type"
              :label="t('admin.roles.accessType')"
              :messages="t('admin.roles.accessTypeCaption')"
              :options="access_types"
              class="mb-2"
              value-by="value"
              text-by="text"
              immediateValidation
              :error="$page.props.errors.access_type"
              :error-messages="$page.props.errors.access_type"
            />
            <va-switch v-model="form.ignore_role"
              :label="t('admin.roles.ignoreRole')"
              :messages="t('admin.roles.ignoreRoleCaption')"
              class="mb-2"
              />
            <va-select v-model="form.required_features"
              :label="t('admin.roles.requiresFeature')"
              :messages="t('admin.roles.requiresFeatureCaption')"
              :options="features"
              class="mb-2"
              value-by="value"
              text-by="text"
              multiple
              clearable
              immediateValidation
              :error="$page.props.errors.required_features"
              :error-messages="$page.props.errors.required_features"
            />
            <va-select v-model="form.implied_roles"
              :label="t('admin.roles.impliedRoles')"
              :messages="t('admin.roles.impliedRolesCaption')"
              :options="roles"
              class="mb-2"
              value-by="id"
              text-by="label"
              multiple
              clearable
              immediateValidation
              :error="$page.props.errors.implied_roles"
              :error-messages="$page.props.errors.implied_roles"
            />
          </template>
        </AdminSettings>
        <va-button type="submit" class="mr-2 mb-2" :disabled="form.processing">{{ t('form.update') }}</va-button>
      </form>
    </va-card-content>
  </va-card>
</template>

<script lang="ts">
export default {
  layout: (h, page) => h(AppLayout, [page]),
  props: {
    role: Object,
    errors: Object,
    features: Object,
    app: Object,
    roles: Object
  },
  data () {
    return {
      access_types: [
        { value: 'minimal', text: useI18n().t('admin.roles.minimalUsers') },
        { value: 'basic', text: useI18n().t('admin.roles.basicUsers') },
        { value: 'standard', text: useI18n().t('admin.roles.standardUsers') }
      ],
      form: useForm({
        label: this.role.label,
        sub_name: this.role.name,
        slug: this.role.slug,
        description: this.role.description,
        category: this.role.category,
        access_type: this.role.access_type,
        ignore_role: this.role.ignore_role,
        required_features: this.role.required_features,
        implied_roles: this.role.implied_roles
      })
    }
  }
}
</script>
<style>
.full-width {
  width: 100%
}
</style>
