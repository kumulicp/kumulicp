<script setup>
import axios from 'axios'
</script>

<template>
  <va-select
    v-model:model-value="selected_country"
    id="country"
    :label="label ? 'Country' : false"
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
      const vueState = this
      const config = {
        headers: {
          'X-CSCAPI-KEY': 'TGNFdUdiSDVWck1PVnJVU3h5UG9aQVFZUXFJUmNlU0xEZ0VDdXliaQ=='
        }
      }

      axios.get('https://api.countrystatecity.in/v1/countries', config)
        .then(function (response) {
          response.data.forEach(function (country) {
            if (!vueState.top_countries.includes(country.iso2)) {
              vueState.countries.push({
                value: country.iso2,
                text: country.name
              })
            }
            if (country.iso2 === vueState.country) {
              vueState.selected_country = country.iso2
            }
          })
        })
    }
  }
}
</script>
