<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import BulkEditLayout from './BulkEditLayout.vue'
import YamlEditor from '@/components/YamlEditor.vue'
import { useForm } from '@inertiajs/vue3'

</script>
<template>
  <Head>
    <title>{{ $t('admin.plans.bulkEdit') }} - Control Panel</title>
  </Head>
  <form @submit.prevent="submit">
    <va-scroll-container horizontal>
      <table class="va-table bulk-edit-table">
        <thead>
          <tr>
            <th class="setting-col">
              <div>{{ $t('admin.plans.configurations') }}</div>
              <div class="va-text-secondary" style="font-size:0.8em;font-weight:normal">{{ $t('admin.plans.persistentSetting') }}</div>
            </th>
            <th v-for="plan in plans" :key="plan.id" class="plan-col">{{ plan.name }}</th>
          </tr>
        </thead>
        <tbody>
          <template v-for="(config, key) in configList" :key="key">
            <tr>
              <td class="setting-label">
                <div>{{ config.name }}</div>
                <div class="va-text-secondary" style="font-size:0.8em">{{ config.persistent }}</div>
              </td>
              <td v-for="plan in plans" :key="plan.id" :id="'config-' + plan.id + '-' + config.name">
                <va-input
                  v-if="config.type === 'string'"
                  v-model="form.plans[plan.id].configurations[config.name]"
                  immediateValidation
                />
                <va-input
                  v-else-if="config.type === 'int'"
                  v-model="form.plans[plan.id].configurations[config.name]"
                  type="number"
                  min="0"
                  immediateValidation
                />
                <va-checkbox
                  v-else-if="config.type === 'bool'"
                  v-model="form.plans[plan.id].configurations[config.name]"
                  immediateValidation
                />
                <YamlEditor
                  v-else-if="config.type === 'yaml'"
                  v-model="form.plans[plan.id].configurations[config.name]"
                  :error-messages="errors && errors['plans.' + plan.id + '.configurations.' + config.name]"
                />
                <va-input
                  v-else-if="config.type === 'json'"
                  v-model="form.plans[plan.id].configurations[config.name]"
                  immediateValidation
                />
                <va-input
                  v-else-if="config.type === 'password'"
                  v-model="form.plans[plan.id].configurations[config.name]"
                  type="password"
                  immediateValidation
                  :placeholder="$t('admin.plans.leavePasswordBlank')"
                />
                <va-select
                  v-else-if="config.type === 'enum'"
                  v-model="form.plans[plan.id].configurations[config.name]"
                  :options="config.options"
                  value-by="value"
                  text-by="text"
                  searchable
                  immediateValidation
                />
                <va-textarea
                  v-else-if="config.type === 'textarea'"
                  v-model="form.plans[plan.id].configurations[config.name]"
                  immediateValidation
                  class="full-width"
                  max-rows="6"
                />
                <va-button
                  v-if="config.additional"
                  color="backgroundSecondary"
                  size="small"
                  class="ml-1"
                  @click="removeConfig(key)"
                  :title="$t('admin.plans.removeConfig')"
                >
                  <va-icon name="fa-x" color="danger" />
                </va-button>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </va-scroll-container>

    <div class="row mb-3 ml-1 mt-2">{{ $t('admin.plans.persistentSettingsNotice') }}</div>

    <!-- Add new config card -->
    <va-card v-if="showAddNewConfigOptions" stripe stripe-color="success" class="mb-2">
      <va-card-title>{{ $t('admin.plans.addConfig') }}</va-card-title>
      <va-card-content>
        <div class="row">
          <div class="flex flex-col lg3">
            <va-input
              id="new-config-name"
              v-model="newConfig.name"
              :label="$t('admin.plans.name')"
              :messages="$t('admin.plans.newNameMessage')"
            />
          </div>
          <div class="flex flex-col lg3">
            <va-select
              v-model="newConfig.type"
              :options="configTypes"
              :label="$t('admin.plans.configType')"
              value-by="value"
              text-by="text"
              searchable
            />
          </div>
          <div class="flex flex-col lg3">
            <va-checkbox
              v-model="newConfig.persistent"
              :label="$t('admin.plans.persistentSetting')"
              :messages="$t('admin.plans.persistentSettingMessage')"
              immediateValidation
            />
          </div>
          <div class="flex flex-col lg3">
            <div>
              <va-button id="confirmAddConfig" color="secondary" @click="addNewConfig">{{ $t('admin.plans.addConfig') }}</va-button>
              <va-button class="ml-3" color="backgroundSecondary" @click="showAddNewConfigOptions = false">{{ $t('admin.plans.hide') }}</va-button>
            </div>
          </div>
        </div>
      </va-card-content>
    </va-card>

    <va-button v-if="!showAddNewConfigOptions" id="addNewConfigButton" class="mr-2 mb-2" @click="showAddNewConfigOptions = true">{{ $t('admin.plans.addConfig') }}</va-button>
    <va-button type="submit" class="mr-2 mb-2" :disabled="form.processing">{{ $t('common.update') }}</va-button>
  </form>
</template>

<script lang="ts">
export default {
  layout: (h, page) => {
    return h(AppLayout, () => h(BulkEditLayout, () => page))
  },
  props: {
    app: Object,
    plans: Array,
    plan_ids: Array,
    config_schema: Object,
    errors: Object
  },
  data () {
    // Build per-plan configuration values keyed by plan id
    const plansMap: Record<number, any> = {}
    for (const plan of this.plans) {
      const configs: Record<string, any> = {}
      for (const [key, config] of Object.entries(plan.configs ?? {})) {
        if (config) {
          configs[key] = (config as any).value
        }
      }
      plansMap[plan.id] = {
        configurations: configs,
        additionalConfigs: {}
      }
    }

    return {
      configList: { ...this.config_schema },
      showAddNewConfigOptions: false,
      newConfig: {
        name: '',
        type: 'string',
        persistent: false
      },
      configTypes: [
        { text: this.$t('admin.plans.configTypes.string'), value: 'string' },
        { text: this.$t('admin.plans.configTypes.int'), value: 'int' },
        { text: this.$t('admin.plans.configTypes.bool'), value: 'bool' },
        { text: this.$t('admin.plans.configTypes.password'), value: 'password' },
        { text: this.$t('admin.plans.configTypes.textarea'), value: 'textarea' }
      ],
      form: useForm({
        plan_ids: this.plan_ids,
        plans: plansMap
      })
    }
  },
  mounted () {
    this.scrollToFirstError()
  },
  methods: {
    submit () {
      this.form.put('/admin/apps/' + this.app.slug + '/plans/bulk-edit/configurations')
    },
    scrollToFirstError () {
      if (! this.errors) return

      const match = Object.keys(this.errors)
        .find(key => /^plans\.\d+\.configurations\./.test(key))
      if (! match) return

      const [, planId, configName] = match.match(/^plans\.(\d+)\.configurations\.(.+)$/)
      const el = document.getElementById('config-' + planId + '-' + configName)
      if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' })
    },
    addNewConfig () {
      if (!this.newConfig.name) return

      // Add to the config schema so a new row appears in the table
      this.configList[this.newConfig.name] = {
        name: this.newConfig.name,
        type: this.newConfig.type,
        persistent: this.newConfig.persistent,
        additional: true
      }

      // For each plan, add an entry in additionalConfigs and a null configurations value
      for (const plan of this.plans) {
        this.form.plans[plan.id].additionalConfigs[this.newConfig.name] = {
          name: this.newConfig.name,
          type: this.newConfig.type,
          persistent: this.newConfig.persistent
        }
        this.form.plans[plan.id].configurations[this.newConfig.name] = null
      }

      this.newConfig.name = ''
      this.showAddNewConfigOptions = false
    },
    removeConfig (key: string) {
      delete this.configList[key]
      for (const plan of this.plans) {
        delete this.form.plans[plan.id].configurations[key]
        delete this.form.plans[plan.id].additionalConfigs[key]
      }
    }
  }
}
</script>

<style lang="scss">
.bulk-edit-table {
  width: 100%;
  border-collapse: collapse;

  th, td {
    padding: 8px 12px;
    vertical-align: middle;
  }

  .setting-col {
    min-width: 220px;
    position: sticky;
    left: 0;
    background: var(--va-background-primary);
    z-index: 1;
  }

  .plan-col {
    min-width: 220px;
  }

  .setting-label {
    min-width: 220px;
    position: sticky;
    left: 0;
    background: var(--va-background-primary);
    z-index: 1;
    font-weight: 500;
  }

  .section-header td {
    background: var(--va-background-secondary);
    padding: 6px 12px;
  }
}
</style>
