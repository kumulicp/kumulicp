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
          name: 'Must contain uppercase letters',
          predicate: this.password.toLowerCase() !== this.password
        },
        {
          name: 'Must contain lowercase letters',
          predicate: this.password.toUpperCase() !== this.password
        },
        {
          name: 'Must contain numbers',
          predicate: /\d/.test(this.password)
        },
        {
          name: 'Must contain symbols',
          predicate: /\W/.test(this.password)
        },
        {
          name: 'Must be at least 8 characters long',
          predicate: this.password.length >= 8
        },
        {
          name: 'Must match',
          predicate: this.password === this.passwordConfirmation
        }
      ]
    }
  }
}
</script>
