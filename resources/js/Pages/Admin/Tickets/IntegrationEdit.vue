<script setup>
import AppLayout from '@/layouts/AppLayout.vue'
import { Link } from '@inertiajs/vue3'
</script>
<template>
  <Head>
    <title>{{ integration ? integration.name : driver.label }} - Control Panel</title>
  </Head>

  <va-alert v-if="revealed && Object.keys(revealed).length" color="warning" class="mb-4" id="revealed">
    <div><strong>{{ $t('tickets.integrations.secretShown') }}</strong></div>
    <div v-for="(value, key) in revealed" :key="key" class="integration-secret">
      <code>{{ value }}</code>
      <va-button size="small" preset="plain" @click="copy(value)">{{ $t('tickets.integrations.copy') }}</va-button>
    </div>
  </va-alert>

  <va-alert v-if="integration && integration.disabled_reason" color="danger" class="mb-4">
    {{ integration.disabled_reason }}
  </va-alert>

  <va-card class="mb-4">
    <va-card-title>
      <div class="flex justify-between items-center w-full">
        <span>{{ integration ? integration.name : $t('tickets.integrations.newOfType', { type: driver.label }) }}</span>
        <va-chip size="small" color="secondary">{{ driver.label }}</va-chip>
      </div>
    </va-card-title>
    <va-card-content>
      <form @submit.prevent="save">
        <va-input
          v-model="form.name"
          id="name"
          class="mb-3"
          :label="$t('tickets.integrations.name')"
          required-mark
          :error="!!form.errors.name"
          :error-messages="form.errors.name"
        />

        <!-- The driver's own settings: the form is built from its schema -->
        <template v-for="field in driver.schema" :key="field.key">
          <template v-if="field.type === 'secret' && field.generated">
            <div v-if="integration" class="mb-3">
              <div class="va-input-label">{{ field.label }}</div>
              <div class="integration-help">{{ field.help }}</div>
              <va-button size="small" color="secondary" class="mr-2 mt-1" @click.prevent="reveal">{{ $t('tickets.integrations.reveal') }}</va-button>
              <va-button v-if="integration.has_generated_secret" size="small" color="warning" class="mt-1" @click.prevent="rotate">{{ $t('tickets.integrations.rotate') }}</va-button>
            </div>
            <div v-else class="mb-3 integration-help">{{ $t('tickets.integrations.secretWillBeGenerated') }}</div>
          </template>

          <va-input
            v-else-if="field.type === 'secret'"
            v-model="form.config[field.key]"
            type="password"
            autocomplete="new-password"
            class="mb-3"
            :label="field.label"
            :placeholder="integration && integration.secrets_set[field.key] ? $t('tickets.integrations.secretSaved') : ''"
            :messages="field.help"
            :required-mark="field.required && !(integration && integration.secrets_set[field.key])"
            :error="!!form.errors['config.' + field.key]"
            :error-messages="form.errors['config.' + field.key]"
          />

          <va-switch v-else-if="field.type === 'toggle'" v-model="form.config[field.key]" class="mb-3" :label="field.label" />

          <va-select
            v-else-if="field.type === 'select'"
            v-model="form.config[field.key]"
            class="mb-3"
            :label="field.label"
            :options="field.options"
            value-by="value"
            text-by="text"
            :messages="field.help"
          />

          <va-input
            v-else
            v-model="form.config[field.key]"
            class="mb-3"
            :label="field.label"
            :type="field.type === 'number' ? 'number' : 'text'"
            :placeholder="field.default || ''"
            :messages="field.help"
            :required-mark="field.required"
            :error="!!form.errors['config.' + field.key]"
            :error-messages="form.errors['config.' + field.key]"
          />
        </template>

        <!-- Which events a webhook wants -->
        <div v-if="driver.kind === 'webhook'" class="mb-3">
          <div class="va-input-label">{{ $t('tickets.integrations.eventsLabel') }}</div>
          <va-checkbox
            v-for="event in driver.events"
            :key="event"
            v-model="form.events"
            :array-value="event"
            :label="$t(`tickets.integrations.eventNames.${event.replace('.', '_')}`)"
            class="d-block"
          />
          <div v-if="form.errors.events" class="text-danger">{{ form.errors.events }}</div>
        </div>

        <!-- How a tracker follows the ticket -->
        <div v-if="driver.kind === 'tracker'" class="mb-3">
          <va-switch v-model="form.options.auto_create" class="d-block mb-2" :label="$t('tickets.integrations.autoCreate')" />
          <va-switch v-model="form.options.sync_state" class="d-block mb-2" :label="$t('tickets.integrations.syncState')" />
          <va-switch v-model="form.options.sync_time" class="d-block mb-2" :label="timeLabel" />
        </div>

        <!-- What the tracker may send back -->
        <div v-if="driver.inbound" class="mb-3">
          <div class="va-input-label">{{ $t('tickets.integrations.inbound.title') }}</div>
          <div class="integration-help mb-2">{{ $t('tickets.integrations.inbound.help') }}</div>
          <div v-if="integration && integration.inbound_url" class="mb-2" id="inboundUrl">
            <div class="integration-help">{{ $t('tickets.integrations.inbound.url') }}</div>
            <div class="integration-secret">
              <code>{{ integration.inbound_url }}</code>
              <va-button size="small" preset="plain" @click.prevent="copy(integration.inbound_url)">{{ $t('tickets.integrations.copy') }}</va-button>
            </div>
          </div>
          <div v-else class="integration-help mb-2">{{ $t('tickets.integrations.inbound.urlAfterSave') }}</div>
          <va-switch v-model="form.options.inbound_comments" class="d-block mb-2" :label="$t('tickets.integrations.inbound.comments')" />
          <va-switch v-model="form.options.inbound_resolve" class="d-block mb-2" :label="$t('tickets.integrations.inbound.resolve')" />
        </div>

        <!-- What is allowed to leave -->
        <div class="mb-3">
          <va-switch v-model="form.options.include_requester" class="d-block mb-1" :label="$t('tickets.integrations.includeRequester')" />
          <div class="integration-help mb-2">{{ $t('tickets.integrations.privacyHelp') }}</div>
        </div>

        <!-- Which tickets -->
        <div class="mb-3">
          <div class="va-input-label">{{ $t('tickets.integrations.filtersLabel') }}</div>
          <div class="integration-help mb-2">{{ $t('tickets.integrations.filtersHelp') }}</div>
          <div class="row">
            <div class="flex xs12 md4 pr-2 mb-2">
              <va-select v-model="form.filters.types" :label="$t('tickets.type')" :options="choices.types" value-by="value" text-by="text" multiple clearable />
            </div>
            <div class="flex xs12 md4 pr-2 mb-2">
              <va-select v-model="form.filters.priorities" :label="$t('tickets.priorityLabel')" :options="choices.priorities" value-by="value" text-by="text" multiple clearable />
            </div>
            <div class="flex xs12 md4 mb-2">
              <va-select v-model="form.filters.categories" :label="$t('tickets.category')" :options="choices.categories" value-by="value" text-by="text" multiple clearable />
            </div>
          </div>
        </div>

        <!-- Danger zone for private addresses -->
        <div class="mb-3">
          <va-switch v-model="form.options.allow_internal" class="d-block mb-1" :label="$t('tickets.integrations.allowInternal')" />
          <div class="integration-help text-danger">{{ $t('tickets.integrations.allowInternalHelp') }}</div>
        </div>

        <div v-if="!integration" class="mb-3">
          <va-switch v-model="form.enabled" :label="$t('tickets.integrations.startOn')" />
        </div>

        <div class="mt-4">
          <va-button type="submit" id="save" :disabled="form.processing">{{ $t('common.save') }}</va-button>
          <Link href="/admin/support/integrations"><va-button color="textInverted" class="ml-2">{{ $t('common.cancel') }}</va-button></Link>
        </div>
      </form>
    </va-card-content>
  </va-card>

  <va-card v-if="integration" class="mb-4">
    <va-card-title>{{ $t('tickets.integrations.manage') }}</va-card-title>
    <va-card-content>
      <div class="mb-3">
        <va-button color="secondary" :disabled="testing" @click="test">{{ $t('tickets.integrations.test') }}</va-button>
        <span v-if="testResult" class="ml-3" :class="testResult.ok ? 'text-success' : 'text-danger'">{{ testResult.message }}</span>
      </div>
      <div>
        <Link :href="`/admin/support/integrations/${integration.id}/deliveries`"><va-button color="secondary" class="mr-2">{{ $t('tickets.integrations.deliveries') }}</va-button></Link>
        <va-button :color="integration.enabled ? 'warning' : 'success'" class="mr-2" @click="toggleEnabled">
          {{ integration.enabled ? $t('tickets.integrations.switchOff') : $t('tickets.integrations.switchOn') }}
        </va-button>
        <va-button color="danger" @click="showDelete = true">{{ $t('common.delete') }}</va-button>
      </div>
    </va-card-content>
  </va-card>

  <va-modal v-model="showDelete" hide-default-actions :title="$t('tickets.integrations.deleteTitle')" :message="$t('tickets.integrations.deleteMessage')">
    <template #footer="{ cancel }">
      <va-button color="backgroundSecondary" @click="cancel">{{ $t('common.cancel') }}</va-button>
      <va-button color="danger" @click="destroy">{{ $t('common.delete') }}</va-button>
    </template>
  </va-modal>
</template>
<script>
import { router, useForm } from '@inertiajs/vue3'
import { useToast } from 'vuestic-ui'
import axios from 'axios'

export default {
  layout: (h, page) => h(AppLayout, [page]),
  props: {
    integration: Object,
    driver: Object,
    choices: Object,
    revealed: Object,
  },
  data () {
    const integration = this.integration
    const defaults = {}
    for (const field of this.driver.schema) {
      defaults[field.key] = field.type === 'secret' ? '' : (field.default ?? '')
    }

    return {
      form: useForm({
        driver: this.driver.key,
        name: integration?.name ?? this.driver.label,
        enabled: true,
        config: { ...defaults, ...(integration?.config ?? {}) },
        events: integration?.events ?? [...this.driver.events],
        filters: integration?.filters ?? { types: [], priorities: [], categories: [] },
        options: {
          auto_create: false,
          sync_state: true,
          sync_time: true,
          include_requester: false,
          allow_internal: false,
          inbound_comments: true,
          inbound_resolve: false,
          ...(integration?.options ?? {}),
        },
      }),
      testing: false,
      testResult: null,
      showDelete: false,
      toast: useToast(),
    }
  },
  computed: {
    timeLabel () {
      return this.driver.capabilities.includes('time_tracking')
        ? this.$t('tickets.integrations.syncTimeNative')
        : this.$t('tickets.integrations.syncTimeComment')
    },
  },
  methods: {
    save () {
      if (this.integration) {
        this.form.put(`/admin/support/integrations/${this.integration.id}`, { preserveScroll: true })
      } else {
        this.form.post('/admin/support/integrations')
      }
    },
    async test () {
      this.testing = true
      this.testResult = null
      try {
        const { data } = await axios.post(`/admin/support/integrations/${this.integration.id}/test`)
        this.testResult = data
      } catch {
        this.testResult = { ok: false, message: this.$t('tickets.integrations.testFailed') }
      } finally {
        this.testing = false
      }
    },
    toggleEnabled () {
      router.put(`/admin/support/integrations/${this.integration.id}/enabled`, { enabled: !this.integration.enabled }, { preserveScroll: true })
    },
    rotate () {
      router.post(`/admin/support/integrations/${this.integration.id}/secret/rotate`, {}, { preserveScroll: true })
    },
    async reveal () {
      try {
        const { data } = await axios.post(`/admin/support/integrations/${this.integration.id}/secret/reveal`)
        const secrets = Object.values(data).filter(value => !!value)
        this.toast.init({ message: secrets.join('  ·  '), color: 'info', duration: 20000 })
      } catch {
        this.toast.init({ message: this.$t('tickets.integrations.testFailed'), color: 'danger' })
      }
    },
    copy (value) {
      try {
        navigator.clipboard.writeText(value)
      } catch {
        // Copying is a convenience; the secret is on screen
      }
    },
    destroy () {
      router.delete(`/admin/support/integrations/${this.integration.id}`)
    },
  },
}
</script>
<style lang="scss">
  .integration-help {
    color: var(--va-secondary);
    font-size: 0.85em;
  }

  .integration-secret {
    display: flex;
    align-items: center;
    gap: 0.5em;
    margin-top: 0.5em;
    overflow-wrap: anywhere;
  }

  .d-block {
    display: block;
  }
</style>
