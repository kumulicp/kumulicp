<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { Link, useForm } from '@inertiajs/vue3'
import AppsLayout from './AppsLayout.vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <Head>
    <title>Organization App - Control Panel</title>
  </Head>
    <div class="row justify-center">
        <VaButtonGroup>
        <va-button @click="showUpdateAppModal = true">{{ t('admin.apps.update') }}</va-button>
        <va-button @click="showUpgradeAppModal = true">{{ t('admin.apps.upgrade') }}</va-button>
        <va-button color="danger" @click="showDeleteAppModal = true">{{ t('admin.apps.delete') }}</va-button>
        </VaButtonGroup>
    </div>
    <va-scroll-container
        color="primary"
        horizontal
    >
        <table class="va-table va-table--hoverable mt-3">
        <thead>
            <tr>
            <th>Version</th>
            </tr>
        </thead>
        <tbody>
            <tr v-for="(version, index) in versions.slice((curPageValue - 1), (curPageValue - 1 + pageSize))" :key="index" style="min-height:300px;">
            <td><Link :href="'/admin/organizations/'+organization.id+'/apps/'+app.id+'/upgrade/'+version.id">{{ version.version }}</Link></td>
            </tr>
        </tbody>
        </table>
    </va-scroll-container>

    <va-pagination v-if="versions.length > pageSize" class="mt-3 mb-3 justify-center" v-model="curPageValue" :total="versions.length" :direction-links="false" :page-size="pageSize" />

    <va-list-separator class="my-1" fit />

    <va-list>
        <va-list-item class="py-3">
        <va-list-item-section label>
            <va-list-item-label>
            <h5>API Password</h5>
            </va-list-item-label>
        </va-list-item-section>
        <va-list-item-section>
            <va-list-item-label>
            <va-input
                v-model="app.password"
                :type="isPasswordVisible ? 'text' : 'password'"
                placeholder="#########"
                immediateValidation
                @click-append-inner="isPasswordVisible = !isPasswordVisible"
                readonly
            >
                <template #appendInner>
                <va-icon
                    :name="isPasswordVisible ? 'visibility_off' : 'visibility'"
                    size="small"
                    color="primary"
                />
                </template>
            </va-input>
            </va-list-item-label>
        </va-list-item-section>
        </va-list-item>
    </va-list>
    <form @submit.prevent="form.put('/admin/organizations/'+organization.id+'/apps/'+app.id)">
        <div class="row mt-3">
        <va-textarea
            v-model="form.settings"
            class="flex flex-col sm12"
            label="JSON Settings"
            rows="50"
            placeholder="{}"
            min-rows="30"
            max-rows="50"
            :error="$page.props.errors.settings"
            :error-messages="$page.props.errors.settings"
            />
        <div v-if="$page.props.errors.settings">
            {{ $page.props.errors.settings }}
        </div>
        </div>
        <va-button type="submit" id="submit" class="mr-2 mb-2" :disabled="form.processing">{{ t('form.update') }}</va-button>
    </form>
    <va-modal v-model="showUpdateAppModal"
    hide-default-actions
    no-padding
    class="p-0"
    >
      <template #content="{ ok }">
        <va-card-title class="m-0">{{ t('admin.apps.update') }}</va-card-title>
        <va-card-content class="m-0 p-0">
          {{ t('admin.apps.update_modal') }}
        </va-card-content>
        <va-card-actions align="right" class="">
          <va-button color="textInverted" :disabled="form.processing" @click="ok">{{ t('modal.cancel') }}</va-button>
          <Link :href="'/admin/organizations/'+organization.id+'/apps/'+app.id+'/update'"><va-button type="submit" id="submit" class="mr-2" :disabled="form.processing">{{ t('modal.yes') }}</va-button></Link>
        </va-card-actions>
      </template>
    </va-modal>
    <va-modal v-model="showUpgradeAppModal"
    hide-default-actions
    no-padding
    class="p-0"
    >
      <template #content="{ ok }">
        <va-card-title class="m-0">{{ t('admin.apps.upgrade') }}</va-card-title>
        <va-card-content class="m-0 p-0">
            {{ t('admin.apps.upgrade_modal') }}
        </va-card-content>
        <va-card-actions align="right" class="">
            <va-button color="textInverted" :disabled="form.processing" @click="ok">{{ t('modal.cancel') }}</va-button>
            <Link :href="'/admin/organizations/'+organization.id+'/apps/'+app.id+'/upgrade/'+app.version.id"><va-button type="submit" id="submit" class="mr-2" :disabled="form.processing">{{ t('modal.yes') }}</va-button></Link>
        </va-card-actions>
      </template>
    </va-modal>
    <va-modal v-model="showDeleteAppModal"
    hide-default-actions
    no-padding
    class="p-0"
    >
      <template #content="{ ok }">
        <va-card-title class="m-0">{{ t('admin.apps.delete') }}</va-card-title>
        <va-card-content class="m-0 p-0">
            {{ t('admin.apps.delete_modal') }}

            <div class="row mt-3">
              <div class="flex flex-col xs12">
                {{ t('admin.apps.deleteNowOrLater') }}
              </div>
            </div>
            <va-radio v-model="deleteApp.when" :options="deleteWhen" value-by="value" />
            <div v-if="deleteApp.when === 'later'" class="row mt-3">
              <div class="flex flex-col xs6 pr-2">
                <va-input v-model="deleteApp.start_time"
                  :label="t('admin.apps.deleteStart')"
                  type="time"
                  />
              </div>
              <div class="flex flex-col xs6 pl-2">
                <va-input v-model="deleteApp.end_time"
                  :label="t('admin.apps.deleteEnd')"
                  type="time"
                  />
              </div>
            </div>
        </va-card-content>
        <va-card-actions align="right" class="">
            <va-button color="textInverted" :disabled="form.processing" @click="ok">{{ t('modal.cancel') }}</va-button>
            <va-button color="danger" type="submit" id="submit" class="mr-2" @click="deleteApp.delete('/admin/organizations/'+organization.id+'/apps/'+app.id)" :disabled="form.processing">{{ t('modal.yes') }}</va-button>
        </va-card-actions>
      </template>
    </va-modal>
</template>

<script lang="ts">
export default {
  layout: (h, page) => {
    return h(AppLayout, () => h(AppsLayout, () => page))
  },
  props: {
    app: Object,
    organization: Object,
    versions: Object
  },
  data () {
    const settings = this.app.settings ? JSON.stringify(this.app.settings, '', 2) : '{}'

    return {
      curPageValue: 1,
      pageSize: 10,
      form: useForm({
        settings
      }),
      isPasswordVisible: false,
      showUpdateAppModal: false,
      showUpgradeAppModal: false,
      showDeleteAppModal: false,
      deleteApp: useForm({
        when: 'later',
        start_time: '',
        end_time: ''
      }),
      deleteWhen: [
        {
          text: 'Now',
          value: 'now'
        },
        {
          text: 'Later',
          value: 'later'
        }
      ]
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
