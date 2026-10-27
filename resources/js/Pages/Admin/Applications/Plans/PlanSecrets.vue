<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import PlanLayout from './PlanLayout.vue'
import { useForm } from '@inertiajs/vue3'
import { useToast } from 'vuestic-ui'
import axios from 'axios'

</script>
<template>
  <Head>
    <title>{{ $t('admin.plans.secrets') }} - Control Panel</title>
  </Head>
  <form @submit.prevent="submit">
    <h3 class="va-h3">{{ $t('admin.plans.secrets') }}</h3>
    <p class="mb-4">{{ $t('admin.plans.secretsDescription') }}</p>
    <p v-if="form.secrets.length === 0" class="mb-4">{{ $t('admin.plans.noSecrets') }}</p>
    <div v-for="(secret, index) in form.secrets" :key="secret.key" class="row align-center mb-2">
      <div class="flex xs12 md4">
        <va-input
          v-model="secret.name"
          :label="$t('admin.plans.secretName')"
          :readonly="secret.existing"
          :error="!! errors[`secrets.${index}.name`]"
          :error-messages="errors[`secrets.${index}.name`]"
          placeholder="MY_SECRET"
          />
      </div>
      <div class="flex xs12 md6">
        <va-input
          v-model="secret.value"
          :type="secret.revealed ? 'text' : 'password'"
          autocomplete="new-password"
          :label="$t('admin.plans.secretValue')"
          :placeholder="secret.existing ? $t('admin.plans.secretValueSet') : ''"
          :error="!! errors[`secrets.${index}.value`]"
          :error-messages="errors[`secrets.${index}.value`]"
          >
          <template #appendInner>
            <va-button
              preset="plain"
              size="small"
              :loading="secret.loading"
              :title="secret.revealed ? $t('admin.plans.hideSecret') : $t('admin.plans.showSecret')"
              @click="toggleSecret(secret)"
              >
              <va-icon :name="secret.revealed ? 'fa-eye-slash' : 'fa-eye'" />
            </va-button>
            <va-button
              preset="plain"
              size="small"
              :disabled="secret.loading"
              :title="$t('admin.plans.copySecret')"
              @click="copySecret(secret)"
              >
              <va-icon :name="secret.copied ? 'fa-check' : 'fa-copy'" :color="secret.copied ? 'success' : undefined" />
            </va-button>
          </template>
        </va-input>
      </div>
      <div class="flex xs12 md2">
        <va-button color="backgroundSecondary" @click="removeSecret(index)" :title="$t('admin.plans.removeSecret')">
          <va-icon name="fa-x" color="danger" />
        </va-button>
      </div>
    </div>
    <va-button class="mr-2 mb-2" @click="addSecret">{{ $t('admin.plans.addSecret') }}</va-button>
    <va-button type="submit" id="submit" class="mr-2 mb-2" :disabled="form.processing">{{ $t('common.update') }}</va-button>
  </form>
</template>

<script lang="ts">
export default {
  layout: (h, page) => {
    return h(AppLayout, () => h(PlanLayout, () => page))
  },
  props: {
    plan: Object,
    errors: Object,
    app: Object,
    secrets: Array
  },
  data () {
    return {
      nextKey: this.secrets.length,
      toast: useToast(),
      // The server only sends the names. A blank value on an existing secret keeps what is stored.
      // `fetched` is the saved value once it has been revealed, to tell whether it was then edited.
      form: useForm({
        secrets: this.secrets.map((name, index) => this.newRow(index, name, true))
      })
    }
  },
  methods: {
    newRow (key, name, existing) {
      return { key, name, value: '', existing, fetched: null, revealed: false, loading: false, copied: false }
    },
    addSecret () {
      this.form.secrets.push(this.newRow(this.nextKey++, '', false))
    },
    // A saved secret's value is only requested when it is shown or copied, and only once.
    // Returns false if it could not be retrieved.
    async loadValue (secret) {
      if (! secret.existing || secret.value || secret.fetched !== null) return true

      secret.loading = true
      try {
        const { data } = await axios.post('/admin/apps/' + this.app.slug + '/plans/' + this.plan.id + '/secrets/reveal', { name: secret.name })
        secret.value = data.value
        secret.fetched = data.value
        return true
      } catch {
        this.toast.init({ message: this.$t('admin.plans.secretRevealFailed'), color: 'danger' })
        return false
      } finally {
        secret.loading = false
      }
    },
    async toggleSecret (secret) {
      if (! secret.revealed && ! await this.loadValue(secret)) return

      secret.revealed = ! secret.revealed
    },
    async copySecret (secret) {
      if (! await this.loadValue(secret)) return

      try {
        await navigator.clipboard.writeText(secret.value)
      } catch {
        this.toast.init({ message: this.$t('admin.plans.secretCopyFailed'), color: 'danger' })
        return
      }

      secret.copied = true
      setTimeout(() => { secret.copied = false }, 2000)
    },
    removeSecret (index) {
      this.form.secrets.splice(index, 1)
    },
    submit () {
      this.form
        // A revealed value that was not edited is sent blank, so the stored secret is left as it is
        .transform(data => ({ secrets: data.secrets.map(({ name, value, fetched }) => ({ name, value: value === fetched ? '' : value })) }))
        .put('/admin/apps/' + this.app.slug + '/plans/' + this.plan.id + '/secrets')
    }
  }
}
</script>
