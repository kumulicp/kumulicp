<script setup>
</script>

<template>
  <va-list class="mb-2">
    <va-list-item
        v-for="(requirement, index) in passwordRequirements"
        :key="index"
        class="my-0"
    >
      <va-list-item-section icon>
      <va-icon
          v-if="requirement.predicate"
          name="fa-check"
          color="success"
          />
      <va-icon
          v-else
          name="fa-cancel"
          color="danger" />
      </va-list-item-section>
      <va-list-item-section>
          {{ requirement.name }}
      </va-list-item-section>
  </va-list-item>
  </va-list>
</template>
<script>
export default {
  props: {
    password: String,
    passwordConfirmation: String
  },
  computed: {
    passwordRequirements () {
      return [
        {
          name: this.$t('components.passwordChecker.uppercase'),
          predicate: this.password.toLowerCase() !== this.password
        },
        {
          name: this.$t('components.passwordChecker.lowercase'),
          predicate: this.password.toUpperCase() !== this.password
        },
        {
          name: this.$t('components.passwordChecker.numbers'),
          predicate: /\d/.test(this.password)
        },
        {
          name: this.$t('components.passwordChecker.symbols'),
          predicate: /\W/.test(this.password)
        },
        {
          name: this.$t('components.passwordChecker.minLength'),
          predicate: this.password.length >= 8
        },
        {
          name: this.$t('components.passwordChecker.match'),
          predicate: this.password === this.passwordConfirmation
        }
      ]
    }
  }
}
</script>
