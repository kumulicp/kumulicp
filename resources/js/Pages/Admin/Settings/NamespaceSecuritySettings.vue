<script setup>
import AppLayout from '@/layouts/AppLayout.vue'
import SettingsLayout from './SettingsLayout.vue'
import AdminSettings from '@/components/AdminSettings.vue'
import { useForm } from '@inertiajs/vue3'
</script>

<template>
  <Head>
    <title>{{ $t('settings.namespaceSecurity') }} - Control Panel</title>
  </Head>
  <form @submit.prevent="form.put('/admin/settings/namespace-security')">
    <AdminSettings>
      <template #name>{{ $t('admin.namespaceSecurity.title') }}</template>
      <template #description>{{ $t('admin.namespaceSecurity.description') }}</template>
      <template #settings>
        <va-alert color="warning" icon="warning" outline class="mb-3">
          {{ $t('admin.namespaceSecurity.warning') }}
        </va-alert>

        <h3 class="va-h6 mb-2">{{ $t('admin.namespaceSecurity.presetTiers') }}</h3>
        <div v-for="preset in presets" :key="preset.key" class="mb-2 preset-tier">
          <strong>{{ $t('admin.namespaceSecurity.tierNames.' + preset.key) }}</strong>
          <span class="ml-2">{{ $t('admin.namespaceSecurity.tierDescriptions.' + preset.key) }}</span>
        </div>

        <va-list-separator class="my-3" fit />

        <h3 class="va-h6 mb-2">{{ $t('admin.namespaceSecurity.customTiers') }}</h3>
        <va-alert
          v-if="$page.props.errors.tiers"
          color="danger"
          class="mb-3"
        >
          {{ $page.props.errors.tiers }}
        </va-alert>
        <p v-if="form.tiers.length === 0" class="mb-3">{{ $t('admin.namespaceSecurity.noCustomTiers') }}</p>

        <va-card
          v-for="(tier, index) in form.tiers"
          :key="index"
          class="mb-3"
          outlined
        >
          <va-card-content>
            <div class="row">
              <div class="flex flex-col lg4">
                <va-input
                  v-model="tier.key"
                  :label="$t('admin.namespaceSecurity.tierKey')"
                  :messages="[$t('admin.namespaceSecurity.tierKeyHint')]"
                  :disabled="tier.existing"
                  :error="!!$page.props.errors[`tiers.${index}.key`]"
                  :error-messages="$page.props.errors[`tiers.${index}.key`]"
                />
              </div>
              <div class="flex flex-col lg7">
                <va-input
                  v-model="tier.label"
                  :label="$t('admin.namespaceSecurity.tierLabel')"
                  :error="!!$page.props.errors[`tiers.${index}.label`]"
                  :error-messages="$page.props.errors[`tiers.${index}.label`]"
                />
              </div>
              <div class="flex lg1">
                <div class="content-center align-center" :title="$t('admin.namespaceSecurity.removeTier')" @click="removeTier(index)">
                  <va-icon name="fa-x" color="danger" />
                </div>
              </div>
            </div>
            <div class="row mt-2">
              <div v-for="mode in modes" :key="mode" class="flex flex-col lg4">
                <va-select
                  v-model="tier[mode]"
                  :label="$t('admin.namespaceSecurity.' + mode)"
                  :options="levelOptions"
                  value-by="value"
                  text-by="text"
                  :error="!!$page.props.errors[`tiers.${index}.${mode}`]"
                  :error-messages="$page.props.errors[`tiers.${index}.${mode}`]"
                />
                <va-input
                  v-model="tier[mode + '_version']"
                  class="mt-1"
                  :label="$t('admin.namespaceSecurity.version')"
                  :messages="[$t('admin.namespaceSecurity.versionHint')]"
                  :error="!!$page.props.errors[`tiers.${index}.${mode}_version`]"
                  :error-messages="$page.props.errors[`tiers.${index}.${mode}_version`]"
                />
              </div>
            </div>
          </va-card-content>
        </va-card>

        <va-checkbox
          v-if="$page.props.errors.tiers"
          v-model="form.override_preflight"
          :label="$t('admin.namespaceSecurity.overridePreflight')"
          :messages="$t('admin.namespaceSecurity.overridePreflightHint')"
          class="my-3"
        />

        <va-button type="button" preset="secondary" @click="addTier()">
          {{ $t('admin.namespaceSecurity.addTier') }}
        </va-button>
      </template>
    </AdminSettings>
    <va-button type="submit" :disabled="form.processing" class="mr-2 my-2">
      {{ $t('common.update') }}
    </va-button>
  </form>
</template>

<script>
export default {
  layout: (h, page) => h(AppLayout, () => h(SettingsLayout, () => page)),
  props: {
    presets: Array,
    tiers: Array,
    levels: Array,
    errors: Object,
  },
  data () {
    return {
      modes: ['enforce', 'warn', 'audit'],
      form: useForm({
        override_preflight: false,
        tiers: this.tiers.map((tier) => ({ ...tier, existing: true })),
      }),
    }
  },
  computed: {
    levelOptions () {
      return [
        { value: null, text: this.$t('admin.namespaceSecurity.levelOff') },
        ...this.levels.map((level) => ({ value: level, text: this.$t('admin.namespaceSecurity.levels.' + level) })),
      ]
    },
  },
  methods: {
    addTier () {
      this.form.tiers.push({
        key: '',
        label: '',
        enforce: null,
        warn: null,
        audit: null,
        enforce_version: 'latest',
        warn_version: 'latest',
        audit_version: 'latest',
        existing: false,
      })
    },
    removeTier (index) {
      this.form.tiers.splice(index, 1)
    },
  },
}
</script>
