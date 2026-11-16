<script setup>
import AppLayout from '@/layouts/AppLayout.vue'
import { Link } from '@inertiajs/vue3'
import TicketHeader from '@/components/tickets/TicketHeader.vue'
</script>
<template>
  <Head>
    <title>{{ integration ? integration.name : driver.label }} - Control Panel</title>
  </Head>
  <TicketHeader :title="integration ? integration.name : $t('tickets.integrations.newOfType', { type: driver.label })">
    <template #meta>
      <va-chip size="small" color="info">{{ driver.label }}</va-chip>
    </template>
    <template #actions>
      <Link href="/admin/support/integrations"><va-button preset="secondary" border-color="primary" icon="arrow_back">{{ $t('tickets.integrations.title') }}</va-button></Link>
    </template>
  </TicketHeader>

  <div class="t-col t-col--narrow">
    <va-alert v-if="revealed && Object.keys(revealed).length" color="warning" id="revealed">
      <div><strong>{{ $t('tickets.integrations.secretShown') }}</strong></div>
      <div v-for="(value, key) in revealed" :key="key" class="integration-secret">
        <code>{{ value }}</code>
        <va-button size="small" preset="plain" @click="copy(value)">{{ $t('tickets.integrations.copy') }}</va-button>
      </div>
    </va-alert>

    <va-alert v-if="integration && integration.disabled_reason" color="danger">
      {{ integration.disabled_reason }}
    </va-alert>

    <va-card>
      <va-card-content>
        <form class="t-stack t-form-sections" @submit.prevent="save">
          <va-input
            v-model="form.name"
            id="name"
            :label="$t('tickets.integrations.name')"
            required-mark
            :error="!!form.errors.name"
            :error-messages="form.errors.name"
          />

          <!-- The driver's own settings: the form is built from its schema -->
          <template v-for="field in driver.schema" :key="field.key">
            <template v-if="field.type === 'secret' && field.generated">
              <div v-if="integration">
                <div class="va-input-label">{{ field.label }}</div>
                <div class="t-muted">{{ field.help }}</div>
                <div class="t-actions mt-2">
                  <va-button size="small" preset="secondary" border-color="primary" @click.prevent="reveal">{{ $t('tickets.integrations.reveal') }}</va-button>
                  <va-button v-if="integration.has_generated_secret" size="small" preset="secondary" border-color="warning" color="warning" @click.prevent="rotate">{{ $t('tickets.integrations.rotate') }}</va-button>
                </div>
              </div>
              <div v-else class="t-muted">{{ $t('tickets.integrations.secretWillBeGenerated') }}</div>
            </template>

            <va-input
              v-else-if="field.type === 'secret'"
              v-model="form.config[field.key]"
              type="password"
              autocomplete="new-password"
              :label="field.label"
              :placeholder="integration && integration.secrets_set[field.key] ? $t('tickets.integrations.secretSaved') : ''"
              :messages="field.help"
              :required-mark="field.required && !(integration && integration.secrets_set[field.key])"
              :error="!!form.errors['config.' + field.key]"
              :error-messages="form.errors['config.' + field.key]"
            />

            <va-switch v-else-if="field.type === 'toggle'" v-model="form.config[field.key]" :label="field.label" />

            <va-select
              v-else-if="field.type === 'select'"
              v-model="form.config[field.key]"
              :label="field.label"
              :options="field.options"
              value-by="value"
              text-by="text"
              :messages="field.help"
            />

            <va-input
              v-else
              v-model="form.config[field.key]"
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
          <fieldset v-if="driver.kind === 'webhook'" class="t-fieldset">
            <legend class="va-input-label">{{ $t('tickets.integrations.eventsLabel') }}</legend>
            <va-checkbox
              v-for="event in driver.events"
              :key="event"
              v-model="form.events"
              :array-value="event"
              :label="$t(`tickets.integrations.eventNames.${event.replace('.', '_')}`)"
              class="d-block"
            />
            <div v-if="form.errors.events" class="text-danger">{{ form.errors.events }}</div>
          </fieldset>

          <!-- How a tracker follows the ticket -->
          <fieldset v-if="driver.kind === 'tracker'" class="t-fieldset">
            <va-switch v-model="form.options.auto_create" class="d-block" :label="$t('tickets.integrations.autoCreate')" />
            <va-switch v-model="form.options.sync_state" class="d-block" :label="$t('tickets.integrations.syncState')" />
            <va-switch v-model="form.options.sync_time" class="d-block" :label="timeLabel" />
          </fieldset>

          <!-- What the tracker may send back -->
          <fieldset v-if="driver.inbound" class="t-fieldset">
            <legend class="va-input-label">{{ $t('tickets.integrations.inbound.title') }}</legend>
            <div class="t-muted">{{ $t('tickets.integrations.inbound.help') }}</div>
            <div v-if="integration && integration.inbound_url" id="inboundUrl">
              <div class="t-muted">{{ $t('tickets.integrations.inbound.url') }}</div>
              <div class="integration-secret">
                <code>{{ integration.inbound_url }}</code>
                <va-button size="small" preset="plain" @click.prevent="copy(integration.inbound_url)">{{ $t('tickets.integrations.copy') }}</va-button>
              </div>
            </div>
            <div v-else class="t-muted">{{ $t('tickets.integrations.inbound.urlAfterSave') }}</div>
            <va-switch v-model="form.options.inbound_comments" class="d-block" :label="$t('tickets.integrations.inbound.comments')" />
            <va-switch v-model="form.options.inbound_resolve" class="d-block" :label="$t('tickets.integrations.inbound.resolve')" />
          </fieldset>

          <!-- What is allowed to leave -->
          <fieldset class="t-fieldset">
            <va-switch v-model="form.options.include_requester" class="d-block" :label="$t('tickets.integrations.includeRequester')" />
            <div class="t-muted">{{ $t('tickets.integrations.privacyHelp') }}</div>
          </fieldset>

          <!-- Which tickets -->
          <fieldset class="t-fieldset">
            <legend class="va-input-label">{{ $t('tickets.integrations.filtersLabel') }}</legend>
            <div class="t-muted">{{ $t('tickets.integrations.filtersHelp') }}</div>
            <div class="t-form-grid t-form-grid--2">
              <va-select v-model="form.filters.types" :label="$t('tickets.type')" :options="choices.types" value-by="value" text-by="text" multiple clearable />
              <va-select v-model="form.filters.priorities" :label="$t('tickets.priorityLabel')" :options="choices.priorities" value-by="value" text-by="text" multiple clearable />
              <va-select v-model="form.filters.categories" :label="$t('tickets.category')" :options="choices.categories" value-by="value" text-by="text" multiple clearable />
            </div>
          </fieldset>

          <!-- Danger zone for private addresses -->
          <fieldset class="t-fieldset">
            <va-switch v-model="form.options.allow_internal" class="d-block" :label="$t('tickets.integrations.allowInternal')" />
            <div class="t-muted t-danger-text">{{ $t('tickets.integrations.allowInternalHelp') }}</div>
          </fieldset>

          <va-switch v-if="!integration" v-model="form.enabled" :label="$t('tickets.integrations.startOn')" />

          <div class="t-actions t-actions--stack">
            <va-button type="submit" id="save" :disabled="form.processing">{{ $t('common.save') }}</va-button>
            <Link href="/admin/support/integrations"><va-button preset="secondary" border-color="primary">{{ $t('common.cancel') }}</va-button></Link>
          </div>
        </form>
      </va-card-content>
    </va-card>

    <va-card v-if="integration">
      <va-card-title>{{ $t('tickets.integrations.manage') }}</va-card-title>
      <va-card-content class="t-stack">
        <div class="t-actions t-actions--stack">
          <va-button preset="secondary" border-color="primary" :disabled="testing" @click="test">{{ $t('tickets.integrations.test') }}</va-button>
          <span v-if="testResult" :class="testResult.ok ? 'text-success' : 'text-danger'">{{ testResult.message }}</span>
        </div>
        <div class="t-actions t-actions--stack">
          <Link :href="`/admin/support/integrations/${integration.id}/deliveries`"><va-button preset="secondary" border-color="primary">{{ $t('tickets.integrations.deliveries') }}</va-button></Link>
          <va-button preset="secondary" :border-color="integration.enabled ? 'warning' : 'success'" :color="integration.enabled ? 'warning' : 'success'" @click="toggleEnabled">
            {{ integration.enabled ? $t('tickets.integrations.switchOff') : $t('tickets.integrations.switchOn') }}
          </va-button>
          <va-button preset="secondary" border-color="danger" color="danger" @click="showDelete = true">{{ $t('common.delete') }}</va-button>
        </div>
      </va-card-content>
    </va-card>
  </div>

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
  .integration-secret {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.25rem 0.5rem;
    margin-top: 0.5rem;
    overflow-wrap: anywhere;

    code {
      min-width: 0;
      overflow-wrap: anywhere;
    }
  }

  .d-block {
    display: block;
  }

  .t-fieldset {
    display: flex;
    flex-direction: column;
    gap: 0.6rem;
    min-width: 0;
    margin: 0;
    padding: 0.9rem 1rem;
    border: 1px solid var(--va-background-border);
    border-radius: 0.5rem;

    legend {
      padding: 0 0.35rem;
    }
  }
</style>
