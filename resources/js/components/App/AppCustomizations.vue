<template>
    <va-list class="mb-4">
      <va-list-item v-for="(customization, index) in customizations" :key="index">
          <va-list-item-section icon>
          <va-checkbox v-model="customizations_values[customization.name]" :id="customization.name" @click="updateCustomizations"></va-checkbox>
          </va-list-item-section>

          <va-list-item-section>
            <va-list-item-label>
              <h5>{{ customization.label }} <b v-if="customization.price" class="price"> +{{ customization.price }}</b></h5>
            </va-list-item-label>
            <va-list-item-label lines="2" caption>
              <p>{{ customization.description }}</p>
            </va-list-item-label>
          </va-list-item-section>
      </va-list-item>
  </va-list>
</template>
<script lang="ts">
export default {
  props: {
    customizations: Object,
    customizations_form: Object
  },
  emits: ['update:customizations'],
  data () {
    return {
      customizations_values: this.customizations_form
    }
  },
  methods: {
    updateCustomizations () {
      this.$emit('update:customizations', this.customizations_values)
    }
  }
}
</script>
<style>
.price {
  color: var(--va-secondary);
}
</style>
