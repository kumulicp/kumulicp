<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import PlanLayout from './PlanLayout.vue'
import YamlEditor from '@/components/YamlEditor.vue'
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <Head>
    <title>{{ t('admin.plans.editPlan') }} - Control Panel</title>
  </Head>
  <form @submit.prevent="form.put('/admin/apps/'+app.slug+'/plans/'+plan.id+'/configurations')">
    <va-list>
      <va-list-item class="py-3">
        <va-list-item-section label>
          <va-list-item-label>
            <h3 class="va-h3">{{ t('admin.plans.configurations') }}</h3>
          </va-list-item-label>
        </va-list-item-section>
      </va-list-item>
      <template v-for="(config, index) in configList" :key="index">
        <va-list-item class="py-3">
          <va-list-item-section label>
            <va-list-item-label>
              <h5>{{ config.name }}</h5>
            </va-list-item-label>
            <va-list-item-label caption :lines="2">
              <b>{{ t('admin.plans.persistentSetting') }}:</b> {{ config.persistent }}
            </va-list-item-label>
          </va-list-item-section>
          <va-list-item-section>
            <va-list-item-label>
              <va-input
                v-if="config.type == 'string'"
                v-model="form['configurations'][config.name]"
                immediateValidation
                class="pb-1"
                />
              <va-input
                v-if="config.type == 'int'"
                v-model="form['configurations'][config.name]"
                immediateValidation
                type="number"
                min="0"
                class="pb-1"
                />
              <va-checkbox
                v-if="config.type == 'bool'"
                v-model="form['configurations'][config.name]"
                immediateValidation
                class="pb-1"
                />
              <YamlEditor
                v-if="config.type == 'yaml'"
                v-model="form['configurations'][config.name]"
                class="pb-1"
                debounce="1000"
                />
              <va-input
                v-if="config.type == 'json'"
                v-model="form['configurations'][config.name]"
                immediateValidation
                class="pb-1"
                />
              <va-input
                v-if="config.type == 'password'"
                v-model="form['configurations'][config.name]"
                immediateValidation
                type="password"
                class="pb-1"
                :placeholder="t('admin.plans.leavePasswordBlank')"
                />
              <va-select
                v-if="config.type == 'enum'"
                v-model="form['configurations'][config.name]"
                immediateValidation
                :options="config.options"
                value-by="value"
                text-by="text"
                searchable
                />
              <va-textarea
                v-if="config.type == 'textarea'"
                v-model="form['configurations'][config.name]"
                immediateValidation
                class="full-width"
                max-rows="15"
                />
            </va-list-item-label>
          </va-list-item-section>
          <va-list-item-section v-if="config.additional" icon>
                <va-button color="backgroundSecondary" @click="removeConfig(index)" :title="'Remove Config'"><va-icon name="fa-x" color="danger" /></va-button>
          </va-list-item-section>
        </va-list-item>
      <va-list-separator class="my-1" fit v-if="(index+1) != configs.length" />
      </template>
    </va-list>
    <div class="row mb-3 ml-1">{{ t('admin.plans.persistentSettingsNotice') }}</div>
    <va-card v-if="showAddNewConfigOptions" stripe stripe-color="success" class="mb-2">
      <va-card-title>
        {{ t()}}
      </va-card-title>
      <va-card-content>
        <div class="row">
          <div class="flex flex-col lg3">
            <va-input
              v-model="newConfig.name"
              :label="t('admin.plans.name')"
              :messages="t('admin.plans.newNameMessage')"
              />
          </div>
          <div class="flex flex-col lg3">
            <va-select
              v-model="newConfig.type"
              :options="configTypes"
              :label="t('admin.plans.configType')"
              value-by="value"
              text-by="text"
              searchable
              />
          </div>
          <div class="flex flex-col lg3">
            <va-checkbox
              v-model="newConfig.persistentSetting"
              :label="t('admin.plans.persistentSetting')"
              :messages="t('admin.plans.persistentSettingMessage')"
              immediateValidation
              />
          </div>
          <div class="flex flex-col lg3">
            <div>
              <va-button color="secondary" @click="addNewConfig()">{{ t('admin.plans.addConfig') }}</va-button>
              <va-button class="ml-3" color="backgroundSecondary" @click="showAddNewConfigOptions = ! showAddNewConfigOptions">{{ t('admin.plans.hide') }}</va-button>
            </div>
          </div>
        </div>
      </va-card-content>
    </va-card>
    <va-button v-if="! showAddNewConfigOptions" class="mr-2 mb-2" @click="showAddNewConfigOptions = ! showAddNewConfigOptions">{{ t('admin.plans.addConfig') }}</va-button>
    <va-button type="submit" id="submit" class="mr-2 mb-2" :disabled="form.processing">{{ t('form.update') }}</va-button>
  </form>
</template>

<script lang="ts">
export default {
  layout: (h, page) => {
    return h(AppLayout, () => h(PlanLayout, () => page))
  },
  props: {
    plan: Object,
    errors: Object,
    app: Object,
    configs: Object
  },
  data () {
    const configs = {}
    for (const [key, config] of Object.entries(this.configs)) {
      if (config) {
        configs[key] = config.value
      }
    }

    return {
      configList: this.configs,
      feature_options: [
        { text: useI18n().t('status.disabled'), value: 'disabled' },
        { text: useI18n().t('status.enabled'), value: 'enabled' },
        { text: useI18n().t('status.optional'), value: 'optional' }
      ],
      featurePaymentTypes: [
        { text: useI18n().t('admin.plans.perUser'), value: 'user' },
        { text: useI18n().t('admin.plans.addToBill'), value: 'addon' }
      ],
      form: useForm({
        configurations: configs,
        additionalConfigs: {}
      }),
      newConfig: {
        name: '',
        type: 'string',
        persistent: false
      },
      showAddNewConfigOptions: false,
      configTypes: [
        { text: useI18n().t('admin.plans.configTypes.string'), value: 'string' },
        { text: useI18n().t('admin.plans.configTypes.int'), value: 'int' },
        { text: useI18n().t('admin.plans.configTypes.bool'), value: 'bool' },
        { text: useI18n().t('admin.plans.configTypes.password'), value: 'password' },
        { text: useI18n().t('admin.plans.configTypes.textarea'), value: 'textarea' }
      ]
    }
  },
  methods: {
    addNewConfig () {
      this.configList[this.newConfig.name] = {
        name: this.newConfig.name,
        type: this.newConfig.type,
        persistent: this.newConfig.persistent,
        additional: true
      }

      this.form.additionalConfigs[this.newConfig.name] = {
        name: this.newConfig.name,
        type: this.newConfig.type,
        persistent: this.newConfig.persistent
      }

      this.form.configurations[this.newConfig.name] = null

      this.newConfig.name = ''
    },
    removeConfig (index) {
      delete this.configList[index]
      delete this.form.configurations[index]
    }
  }
}
</script>
