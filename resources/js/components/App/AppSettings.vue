<template>
  <template v-for="(setting, index) in settings" :key="index">
    <va-list-item class="py-2">
      <va-list-item-section label>
        <va-list-item-label>
        <h5>{{ setting.label }}</h5>
        </va-list-item-label>
      </va-list-item-section>
      <va-list-item-section>
        <va-list-item-label>
        <va-input
          v-if="setting.type == 'string'"
          v-model="setting_values[setting.name]"
          class="pb-1"
          @update:v-model="updateSettings"
          />
        <va-input
          v-if="setting.type == 'int'"
          v-model="setting_values[setting.name]"
          type="number"
          min="0"
          class="pb-1"
          @update:v-model="updateSettings"
          />
        <va-checkbox
          v-if="setting.type == 'bool'"
          v-model="setting_values[setting.name]"
          class="pb-1"
          @update:v-model="updateSettings"
          />
        <va-input
          v-if="setting.type == 'json'"
          v-model="setting_values[setting.name]"
          class="pb-1"
          @update:v-model="updateSettings"
          />
        <va-input
          v-if="setting.type == 'password'"
          v-model="setting_values[setting.name]"
          type="password"
          class="pb-1"
          placeholder="Leave this blank to keep current password"
          @update:v-model="updateSettings"
          />
        <va-select
          v-if="setting.type == 'enum'"
          v-model="setting_values[setting.name]"
          :options="setting.options"
          value-by="value"
          text-by="text"
          searchable
          @update:v-model="updateSettings"
          />
        </va-list-item-label>
      </va-list-item-section>
    </va-list-item>
    <va-list-separator class="my-1" fit v-if="(index+1) != Object.entries(settings).length" />
  </template>
</template>
<script lang="ts">
export default {
  props: {
    settings: Object,
    settings_form: Object
  },
  emits: ['update:settings'],
  data () {
    return {
      setting_values: this.settings_form
    }
  },
  methods: {
    updateSettings () {
      this.$emit('update:settings', this.setting_values)
    }
  }
}
</script>
