<script setup>
import axios from 'axios'

</script>

<template>
  <va-select
    v-model:model-value="selected_country"
    id="country"
    :label="label ? $t('organization.country') : false"
    searchable
    :options="countries"
    :error="$page.props.errors.country"
    :error-messages="$page.props.errors.country"
    @change="updateCountry"
    text-by="text"
    value-by="value"
    />
</template>
<script>
export default {
  props: ['country', 'label', 'required'],
  emits: ['update:country'],
  data () {
    return {
      selected_country: 'US',
      countries: [{
        value: 'US',
        text: 'United States'
      },
      {
        value: 'CA',
        text: 'Canada'
      }
      ],
      top_countries: ['US', 'CA']
    }
  },
  mounted () {
    this.loadCountries()
  },
  watch: {
    selected_country () {
      this.$emit('update:country', this.selected_country)
    }
  },
  methods: {
    loadCountries () {
      axios.get('/locations/countries')
        .then((response) => {
          const rest = response.data.filter((country) => !this.top_countries.includes(country.value))
          this.countries = [...this.countries, ...rest]
          if (this.country && this.countries.some((country) => country.value === this.country)) {
            this.selected_country = this.country
          }
        })
    }
  }
}
</script>
