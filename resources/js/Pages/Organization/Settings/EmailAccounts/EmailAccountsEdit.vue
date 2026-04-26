<script setup lang="ts">

</script>
<template>
  <Head>
    <title>{{ $t('organization.email.editEmailAccounts') }} - Control Panel</title>
  </Head>
  <va-card class="mb-4">
    <va-card-title>{{ $t('auth.changePassword') }}</va-card-title>

    <va-card-content>
      <div class="row mt-3">
        <div class="flex xs12 md8 lg6">
          <va-input v-model="name"
            immediateValidation
            class="mb-3"
            :label="$t('form.name')" />

          <va-input v-model="password"
            class="mb-3"
            type="password"
            :label="$t('auth.password')"
            immediateValidation />

          <va-input v-model="confirmPassword"
            class="mb-3"
            type="password"
            immediateValidation
            :label="$t('auth.confirmPassword')" />
        </div>
      </div>

      <div class="row justify-end">
        <va-button class="mb-3">{{ $t('auth.changePassword') }}</va-button>
      </div>
    </va-card-content>
  </va-card>
</template>

<script lang="ts">
import { ref } from 'vue'

const name = ref('')
const password = ref('')
const confirmPassword = ref('')

export default {
  data: () => ({
    name,
    password,
    confirmPassword
  })
}
</script>

<style lang="scss"></style>
