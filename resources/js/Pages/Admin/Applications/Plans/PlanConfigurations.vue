<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import PlanLayout from './PlanLayout.vue'
import YamlEditor from '@/components/YamlEditor.vue'
import { useForm } from '@inertiajs/vue3'
import { buildRows, newArrayItem, parseArrayConfigName, removalMap } from '@/services/arrayConfigs'

</script>
<template>
  <Head>
    <title>{{ $t('admin.plans.editPlan') }} - Control Panel</title>
  </Head>
  <form @submit.prevent="submit">
    <va-list>
      <va-list-item class="py-3">
        <va-list-item-section label>
          <va-list-item-label>
            <h3 class="va-h3">{{ $t('admin.plans.configurations') }}</h3>
          </va-list-item-label>
        </va-list-item-section>
      </va-list-item>
      <template v-for="(row, rowIndex) in rows" :key="row.key">
        <va-list-item v-if="row.kind === 'groupStart'" class="pt-3">
          <va-list-item-section label>
            <va-list-item-label>
              <h4 class="va-h4">{{ row.title }}</h4>
            </va-list-item-label>
          </va-list-item-section>
        </va-list-item>
        <va-list-item v-else-if="row.kind === 'itemStart'" class="array-item array-item-start">
          <va-list-item-section v-if="row.removable" icon>
            <va-button
              color="backgroundSecondary"
              @click="removeArrayItem(row.prefix, row.index)"
              :title="$t('admin.plans.removeArrayItem', { name: row.title })"
              >
              <va-icon name="fa-x" color="danger" />
            </va-button>
          </va-list-item-section>
        </va-list-item>
        <va-list-item v-else-if="row.kind === 'groupEnd'" class="py-3">
          <va-button color="secondary" @click="addArrayItem(row.prefix)">{{ $t('admin.plans.addArrayItem', { name: row.title }) }}</va-button>
        </va-list-item>
        <va-list-item v-else class="py-3" :class="{ 'array-item': row.grouped }" :id="'config-' + row.config.name">
          <va-list-item-section label>
            <va-list-item-label>
              <h5>{{ row.label }}</h5>
            </va-list-item-label>
            <va-list-item-label caption :lines="2">
              <b>{{ $t('admin.plans.persistentSetting') }}:</b> {{ row.config.persistent }}
            </va-list-item-label>
          </va-list-item-section>
          <va-list-item-section>
            <va-list-item-label>
              <va-input
                v-if="row.config.type == 'string'"
                v-model="form['configurations'][row.config.name]"
                immediateValidation
                class="pb-1"
                >
                <template #appendInner>
                  <va-icon
                    v-if="canResetToDefault(row.config)"
                    name="fa-rotate-left"
                    size="small"
                    color="primary"
                    class="cursor-pointer"
                    role="button"
                    :title="$t('admin.plans.resetToDefault')"
                    @click="resetToDefault(row.config)"
                    />
                </template>
              </va-input>
              <va-input
                v-if="row.config.type == 'int'"
                v-model="form['configurations'][row.config.name]"
                immediateValidation
                type="number"
                min="0"
                class="pb-1"
                >
                <template #appendInner>
                  <va-icon
                    v-if="canResetToDefault(row.config)"
                    name="fa-rotate-left"
                    size="small"
                    color="primary"
                    class="cursor-pointer"
                    role="button"
                    :title="$t('admin.plans.resetToDefault')"
                    @click="resetToDefault(row.config)"
                    />
                </template>
              </va-input>
              <div v-if="row.config.type == 'bool'" class="flex items-center">
                <va-checkbox
                  v-model="form['configurations'][row.config.name]"
                  immediateValidation
                  class="pb-1"
                  />
                <va-icon
                  v-if="canResetToDefault(row.config)"
                  name="fa-rotate-left"
                  size="small"
                  color="primary"
                  class="cursor-pointer ml-2"
                  role="button"
                  :title="$t('admin.plans.resetToDefault')"
                  @click="resetToDefault(row.config)"
                  />
              </div>
              <YamlEditor
                v-if="row.config.type == 'yaml'"
                :ref="el => setYamlEditorRef(row.config.name, el)"
                v-model="form['configurations'][row.config.name]"
                class="pb-1"
                :error-messages="errors && errors['configurations.' + row.config.name]"
                />
              <va-input
                v-if="row.config.type == 'json'"
                v-model="form['configurations'][row.config.name]"
                immediateValidation
                class="pb-1"
                />
              <va-input
                v-if="row.config.type == 'password'"
                v-model="form['configurations'][row.config.name]"
                immediateValidation
                type="password"
                class="pb-1"
                :placeholder="$t('admin.plans.leavePasswordBlank')"
                />
              <va-select
                v-if="row.config.type == 'enum'"
                v-model="form['configurations'][row.config.name]"
                immediateValidation
                :options="row.config.options"
                value-by="value"
                text-by="text"
                searchable
                >
                <template #appendInner>
                  <va-icon
                    v-if="canResetToDefault(row.config)"
                    name="fa-rotate-left"
                    size="small"
                    color="primary"
                    class="cursor-pointer"
                    role="button"
                    :title="$t('admin.plans.resetToDefault')"
                    @click.stop="resetToDefault(row.config)"
                    />
                </template>
              </va-select>
              <va-textarea
                v-if="row.config.type == 'textarea'"
                v-model="form['configurations'][row.config.name]"
                immediateValidation
                class="full-width"
                max-rows="15"
                >
                <template #appendInner>
                  <va-icon
                    v-if="canResetToDefault(row.config)"
                    name="fa-rotate-left"
                    size="small"
                    color="primary"
                    class="cursor-pointer"
                    role="button"
                    :title="$t('admin.plans.resetToDefault')"
                    @click="resetToDefault(row.config)"
                    />
                </template>
              </va-textarea>
            </va-list-item-label>
          </va-list-item-section>
          <va-list-item-section v-if="row.removable" icon>
                <va-button color="backgroundSecondary" @click="removeConfig(row.config.name)" :title="$t('admin.plans.removeConfig')"><va-icon name="fa-x" color="danger" /></va-button>
          </va-list-item-section>
        </va-list-item>
        <va-list-separator class="my-1" fit v-if="hasSeparatorAfter(rowIndex)" />
      </template>
    </va-list>
    <div class="row mb-3 ml-1">{{ $t('admin.plans.persistentSettingsNotice') }}</div>
    <va-card v-if="showAddNewConfigOptions" stripe stripe-color="success" class="mb-2">
      <va-card-title>
        {{ $t('admin.plans.addConfig') }}
      </va-card-title>
      <va-card-content>
        <div class="row">
          <div class="flex flex-col lg3">
            <va-input
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
              v-model="newConfig.persistentSetting"
              :label="$t('admin.plans.persistentSetting')"
              :messages="$t('admin.plans.persistentSettingMessage')"
              immediateValidation
              />
          </div>
          <div class="flex flex-col lg3">
            <div>
              <va-button color="secondary" @click="addNewConfig()">{{ $t('admin.plans.addConfig') }}</va-button>
              <va-button class="ml-3" color="backgroundSecondary" @click="showAddNewConfigOptions = ! showAddNewConfigOptions">{{ $t('admin.plans.hide') }}</va-button>
            </div>
          </div>
        </div>
      </va-card-content>
    </va-card>
    <va-button v-if="! showAddNewConfigOptions" class="mr-2 mb-2" @click="showAddNewConfigOptions = ! showAddNewConfigOptions">{{ $t('admin.plans.addConfig') }}</va-button>
    <va-button type="submit" id="submit" class="mr-2 mb-2" :disabled="form.processing">{{ $t('common.update') }}</va-button>
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
      configList: { ...this.configs },
      // The configs defined by the app's profile, as opposed to the ones added to this plan
      profileConfigs: Object.fromEntries(Object.entries(this.configs).filter(([, config]) => config && ! config.additional)),
      feature_options: [
        { text: this.$t('status.disabled'), value: 'disabled' },
        { text: this.$t('status.enabled'), value: 'enabled' },
        { text: this.$t('status.optional'), value: 'optional' }
      ],
      featurePaymentTypes: [
        { text: this.$t('admin.plans.perUser'), value: 'user' },
        { text: this.$t('admin.plans.addToBill'), value: 'addon' }
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
      yamlEditorRefs: {},
      firstInvalidYamlConfig: null,
      configTypes: [
        { text: this.$t('admin.plans.configTypes.string'), value: 'string' },
        { text: this.$t('admin.plans.configTypes.int'), value: 'int' },
        { text: this.$t('admin.plans.configTypes.bool'), value: 'bool' },
        { text: this.$t('admin.plans.configTypes.password'), value: 'password' },
        { text: this.$t('admin.plans.configTypes.textarea'), value: 'textarea' }
      ]
    }
  },
  computed: {
    rows () {
      return buildRows(this.configList, this.profileConfigs)
    }
  },
  mounted () {
    this.scrollToFirstError()
  },
  methods: {
    hasSeparatorAfter (rowIndex) {
      const row = this.rows[rowIndex]

      if (rowIndex === this.rows.length - 1) return false

      return row.kind === 'groupEnd' || (row.kind === 'config' && ! row.grouped)
    },
    // Defaults are only applied when a config is first created, so a blank value is kept as
    // blank. These let the admin put the default back on any config that differs from it.
    hasDefault (config) {
      return ['string', 'int', 'bool', 'enum', 'textarea'].includes(config.type)
        && config.default !== undefined
        && config.default !== null
    },
    normalizeConfigValue (config, value) {
      if (config.type === 'bool') return !! value
      if (value === null || value === undefined) return ''

      return String(value)
    },
    canResetToDefault (config) {
      if (! this.hasDefault(config)) return false

      return this.normalizeConfigValue(config, this.form.configurations[config.name])
        !== this.normalizeConfigValue(config, config.default)
    },
    resetToDefault (config) {
      this.form.configurations[config.name] = config.default
    },
    setYamlEditorRef (name, el) {
      if (el) {
        this.yamlEditorRefs[name] = el
      } else {
        delete this.yamlEditorRefs[name]
      }
    },
    submit () {
      this.firstInvalidYamlConfig = null

      for (const [name, editor] of Object.entries(this.yamlEditorRefs)) {
        if (! editor.applyChanges() && ! this.firstInvalidYamlConfig) {
          this.firstInvalidYamlConfig = name
        }
      }

      if (this.firstInvalidYamlConfig) {
        const el = document.getElementById('config-' + this.firstInvalidYamlConfig)
        if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' })
        return
      }

      this.form.put('/admin/apps/' + this.app.slug + '/plans/' + this.plan.id + '/configurations')
    },
    scrollToFirstError () {
      if (! this.errors) return

      const name = Object.keys(this.errors).find(key => key.startsWith('configurations.'))
      if (! name) return

      const configName = name.replace('configurations.', '')
      const el = document.getElementById('config-' + configName)
      if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' })
    },
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
    removeConfig (name) {
      delete this.configList[name]
      delete this.form.configurations[name]
      delete this.form.additionalConfigs[name]
    },
    addArrayItem (prefix) {
      for (const { config, value } of newArrayItem(this.configList, this.profileConfigs, prefix)) {
        this.configList[config.name] = config
        this.form.configurations[config.name] = value
        this.form.additionalConfigs[config.name] = {
          name: config.name,
          type: config.type,
          persistent: config.persistent,
          ...(config.options !== undefined ? { options: config.options } : {})
        }
      }
    },
    removeArrayItem (prefix, index) {
      const map = removalMap(Object.keys(this.configList), prefix, index)
      const configList = {}
      const values = {}
      const additionalConfigs = {}

      for (const [name, config] of Object.entries(this.configList)) {
        const newName = name in map ? map[name] : name
        if (newName === null) continue

        if (newName === name) {
          configList[name] = config
          if (name in this.form.additionalConfigs) additionalConfigs[name] = this.form.additionalConfigs[name]
        } else if (this.profileConfigs[newName]) {
          // Shifted onto a name the profile defines, so it takes the profile's definition back
          configList[newName] = { ...this.profileConfigs[newName] }
        } else {
          configList[newName] = { ...config, name: newName, additional: true }
          additionalConfigs[newName] = {
            name: newName,
            type: config.type,
            persistent: config.persistent,
            ...(config.options !== undefined ? { options: config.options } : {})
          }
        }

        values[newName] = this.form.configurations[name]
      }

      // The profile's configs always exist for the plan, so restore any that the shift left behind
      for (const [name, config] of Object.entries(this.profileConfigs)) {
        const parsed = parseArrayConfigName(name)
        if (! parsed || parsed.prefix !== prefix || name in configList) continue

        configList[name] = { ...config }
        values[name] = config.default ?? (config.type === 'bool' ? false : null)
      }

      this.configList = configList
      this.form.configurations = values
      this.form.additionalConfigs = additionalConfigs
    }
  }
}
</script>

<style scoped>
.array-item {
  border-left: 3px solid var(--va-primary);
  padding-left: 1rem;
}

.array-item-start {
  margin-top: 1rem;
  justify-content: flex-end;
}
</style>
