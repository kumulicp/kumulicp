<script setup>
import axios from 'axios'
</script>

<template>
  <va-select
    v-model="selected_state"
    id="state"
    :label="label ? 'Province/State' : false"
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
      selected_state: ''
    }
  },
  watch: {
    selected_state () {
      this.$emit('update:state', this.selected_state)
    }
  },
  computed: {
    states: {
      get () {
        if (this.country && this.country.length > 1) {
          return this.loadStates(this.country)
        } else if (this.country.value) {
          return this.loadStates(this.country.value)
        }

        return []
      }
    }
  },
  mounted () {
    // this.loadStates(this.country)
  },
  methods: {
    loadStates (country) {
      const vueState = this
      this.selected_state = ''
      this.loadingStates = true
      const config = {
        headers: {
          'X-CSCAPI-KEY': 'TGNFdUdiSDVWck1PVnJVU3h5UG9aQVFZUXFJUmNlU0xEZ0VDdXliaQ=='
        }
      }

      const states = []
      axios.get('https://api.countrystatecity.in/v1/countries/' + country + '/states', config)
        .then(function (response) {
          response.data.forEach(function (state) {
            states.push({
              value: state.iso2,
              text: state.name
            })
            if (vueState.state === state.iso2) {
              vueState.selected_state = state.iso2
            }

            vueState.loadingStates = false
          })
        })

      return states
    }
  }
}
</script>
