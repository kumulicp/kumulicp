<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import AppsLayout from '../AppsLayout.vue'
import { Link, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <Head>
    <title>{{ app.name }} - {{ t('admin.versions.versions') }} - Control Panel</title>
  </Head>
  <div class="row justify-center">
    <va-button id="addVersion" @click="showAddVersion = !showAddVersion">Add Version</va-button>
    <va-modal v-model="showAddVersion" no-outside-dismiss no-padding size="small" class="p-0">
      <template #content="{ ok }">
        <form @submit.prevent="form.post('/admin/apps/'+app.slug+'/versions')">
          <va-card-title class="m-0">{{ t('admin.versions.addVersion') }}</va-card-title>
          <va-card-content class="m-0">
            <va-input v-model="form.version"
              id="name"
              :label="t('admin.versions.name')"
              class="mb-3"
              required-mark
              immediateValidation
              :error="$page.props.errors.version"
              :error-messages="$page.props.errors.version"
              />

            <div class="mt-2 va-input-label va-input-wrapper__label va-input-wrapper__label--outer" style="color: var(--va-primary);">
              Where do you want to copy values from?
            </div>
            <va-radio v-model="form.copy_from"
              :options="copyFrom"
              value-by="value"
              />
            <va-select v-model="form.copy_version"
              v-if="form.copy_from === 'previous_version'"
              id="copyVersion"
              :options="versions"
              :label="t('admin.versions.copyVersion')"
              class="my-2"
              value-by="id"
              text-by="version"
              required-mark
              immediateValidation
              :error="$page.props.errors.copy_version"
              :error-messages="$page.props.errors.copy_version"
              />
          </va-card-content>
          <va-card-actions align="right" class="">
            <va-button color="textInverted" :disabled="form.processing" @click="ok">{{ t('form.cancel') }}</va-button>
            <va-button type="submit" id="submit" :disabled="form.processing" class="mr-2 mb-2">{{ t('form.submit') }}</va-button>
          </va-card-actions>
        </form>
      </template>
    </va-modal>
  </div>
  <div class="apps-list">
    <div class="row">
      <div class="flex flex-col xs12 lg12">
        <VaScrollContainer
          color="primary"
          horizontal
        >
          <table class="va-table va-table--hoverable mt-3">
            <thead>
              <tr>
                <th style="width: 50px">{{ t('status.enabled') }}</th>
                <th width="70%">{{ t('admin.versions.name') }}</th>
                <th>{{ t('status.updatedAt') }}</th>
                <th>{{ t('status.createdAt') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="version in versions.slice((curPageValue - 1), (curPageValue - 1 + pageSize))" :key="version.name" style="min-height:300px;">
                <td style="text-align: center"><va-icon name="fa-circle" :color="version.status == 'active' ? 'success' : 'backgroundElement'" /></td>
                <td><Link :href="'/admin/apps/'+app.slug+'/versions/'+version.version">{{ version.version }}</Link></td>
                <td>{{ version.updated_at }}</td>
                <td>{{ version.created_at }}</td>
              </tr>
            </tbody>
          </table>
        </VaScrollContainer>

        <va-pagination v-if="versions.length > pageSize" class="mt-3 mb-3 justify-center" v-model="curPageValue" :total="versions.length" :direction-links="false" :page-size="pageSize" />
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
    versions: Object,
    errors: Object
  },
  data () {
    return {
      copyFrom: [
        {
          text: useI18n().t('admin.versions.recommendations'),
          value: 'recommendations'
        },
        {
          text: useI18n().t('admin.versions.previousVersion'),
          value: 'previous_version'
        },
        {
          text: useI18n().t('admin.versions.none'),
          value: 'none'
        }
      ],
      showAddVersion: false,
      curPageValue: 1,
      pageSize: 10,
      form: useForm({
        version: '',
        copy_from: 'previous_version',
        copy_version: ''
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
