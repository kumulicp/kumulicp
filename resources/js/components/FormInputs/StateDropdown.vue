<script setup>
import axios from 'axios'

</script>

<template>
  <va-select
    v-model="selected_state"
    id="state"
    :label="label ? $t('organization.state') : false"
    :required-mark="required"
    searchable
    :options="states"
    :error="$page.props.errors.state"
    :error-messages="$page.props.errors.state"
    value-by="value"
    text-by="text"
    :disabled="loadingStates"
    :loading="loadingStates"
    />
</template>
<script>
export default {
  props: ['country', 'state', 'label', 'required'],
  emits: ['update:state'],
  data () {
    return {
      loadingStates: true,
      selected_state: '',
      states: [],
      initialLoad: true
    }
  },
  watch: {
    selected_state () {
      this.$emit('update:state', this.selected_state)
    },
    country: {
      immediate: true,
      handler () {
        this.loadStates()
      }
    }
  },
  methods: {
    loadStates () {
      const country = this.country?.value ?? this.country
      this.states = []
      if (!this.initialLoad) {
        this.selected_state = ''
      }

      if (!country || country.length !== 2) {
        this.loadingStates = false
        return
      }

      this.loadingStates = true
      axios.get('/locations/countries/' + country + '/states')
        .then((response) => {
          if (country !== (this.country?.value ?? this.country)) {
            return
          }
          this.states = response.data
          if (this.initialLoad && this.states.some((state) => state.value === this.state)) {
            this.selected_state = this.state
          }
        })
        .finally(() => {
          if (country === (this.country?.value ?? this.country)) {
            this.loadingStates = false
            this.initialLoad = false
          }
        })
    }
  }
}
</script>
