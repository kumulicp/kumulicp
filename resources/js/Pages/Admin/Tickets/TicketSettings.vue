<script setup>
import AppLayout from '@/layouts/AppLayout.vue'
import { Link } from '@inertiajs/vue3'
import TicketHeader from '@/components/tickets/TicketHeader.vue'
import SlaPolicyForm from '@/components/tickets/SlaPolicyForm.vue'
</script>
<template>
  <Head>
    <title>{{ $t('tickets.settings.title') }} - Control Panel</title>
  </Head>
  <TicketHeader :title="$t('tickets.settings.title')">
    <template #actions>
      <Link href="/admin/support/tickets"><va-button preset="secondary" border-color="primary" icon="arrow_back">{{ $t('tickets.admin.title') }}</va-button></Link>
    </template>
  </TicketHeader>

  <div class="t-col t-col--narrow">
    <va-card>
      <va-card-title>{{ $t('tickets.settings.slaTitle') }}</va-card-title>
      <va-card-content>
        <p class="t-muted mb-3">{{ $t('tickets.settings.slaIntro') }}</p>
        <form class="t-inline-form" @submit.prevent="addPolicy">
          <va-input v-model="newPolicy.name" :label="$t('tickets.settings.newPolicy')" :error="!!newPolicy.errors.name" :error-messages="newPolicy.errors.name" />
          <va-button type="submit" :disabled="newPolicy.processing">{{ $t('common.add') }}</va-button>
        </form>
      </va-card-content>
    </va-card>

    <SlaPolicyForm v-for="policy in policies" :key="policy.id + ':' + policy.name" :policy="policy" :priorities="priorities" />

    <va-card v-if="policies.length && categories.length">
      <va-card-title>{{ $t('tickets.settings.categoryPolicies') }}</va-card-title>
      <va-card-content>
        <p class="t-muted mb-3">{{ $t('tickets.settings.categoryPoliciesHelp') }}</p>
        <div v-for="category in categories" :key="category.id" class="t-category-row">
          <div class="t-category-row__name">{{ category.name }}</div>
          <va-select
            :model-value="category.sla_policy_id"
            :options="policyOptions"
            value-by="value"
            text-by="text"
            clearable
            :placeholder="$t('tickets.settings.useDefault')"
            @update:model-value="setCategoryPolicy(category, $event)"
          />
        </div>
      </va-card-content>
    </va-card>

    <va-card>
      <va-card-title>{{ $t('tickets.settings.automationTitle') }}</va-card-title>
      <va-card-content>
        <form class="t-stack" @submit.prevent="saveAutomation">
          <va-select
            v-model="automationForm.auto_assign"
            :label="$t('tickets.settings.autoAssign')"
            :options="assignOptions"
            value-by="value"
            text-by="text"
            :messages="$t('tickets.settings.autoAssignHelp')"
          />
          <va-select
            v-if="automationForm.auto_assign !== 'off'"
            v-model="automationForm.pool_ids"
            :label="$t('tickets.settings.pool')"
            :options="staff"
            value-by="id"
            text-by="name"
            multiple
            :messages="$t('tickets.settings.poolHelp')"
          />

          <!-- Who takes turns on tickets about a particular app -->
          <div v-if="automationForm.auto_assign !== 'off'" class="t-fieldset" id="appRules">
            <div class="va-input-label">{{ $t('tickets.settings.appRules.title') }}</div>
            <div class="t-muted">{{ $t('tickets.settings.appRules.help') }}</div>

            <div v-for="(rule, index) in automationForm.app_rules" :key="index" class="t-rule">
              <va-select
                v-model="rule.application_id"
                :label="$t('tickets.settings.appRules.app')"
                :options="applicationOptions(rule)"
                value-by="value"
                text-by="text"
                searchable
                :error="!!automationForm.errors[`app_rules.${index}.application_id`]"
                :error-messages="automationForm.errors[`app_rules.${index}.application_id`]"
              />
              <va-select
                v-model="rule.pool_ids"
                :label="$t('tickets.settings.appRules.people')"
                :options="staff"
                value-by="id"
                text-by="name"
                multiple
                :error="!!automationForm.errors[`app_rules.${index}.pool_ids`]"
                :error-messages="automationForm.errors[`app_rules.${index}.pool_ids`]"
              />
              <va-button preset="plain" color="danger" size="small" class="t-rule__remove" @click.prevent="removeRule(index)">{{ $t('common.remove') }}</va-button>
            </div>

            <div class="t-actions">
              <va-button preset="secondary" border-color="primary" size="small" icon="add" :disabled="!unusedApplications.length" @click.prevent="addRule">{{ $t('tickets.settings.appRules.add') }}</va-button>
            </div>
          </div>

          <div class="t-form-grid t-form-grid--2">
            <va-input v-model="automationForm.reminder_days" type="number" min="0" :label="$t('tickets.settings.reminderDays')" :messages="$t('tickets.settings.zeroOff')" :error="!!automationForm.errors.reminder_days" />
            <va-input v-model="automationForm.auto_resolve_days" type="number" min="0" :label="$t('tickets.settings.autoResolveDays')" :messages="$t('tickets.settings.zeroOff')" :error="!!automationForm.errors.auto_resolve_days" />
            <va-input v-model="automationForm.auto_close_days" type="number" min="0" :label="$t('tickets.settings.autoCloseDays')" :messages="$t('tickets.settings.zeroOff')" :error="!!automationForm.errors.auto_close_days" />
            <va-input v-model="automationForm.retention_days" type="number" min="0" :label="$t('tickets.settings.retentionDays')" :messages="$t('tickets.settings.retentionHelp')" :error="!!automationForm.errors.retention_days" />
          </div>

          <div class="t-actions t-actions--stack">
            <va-button type="submit" :disabled="automationForm.processing">{{ $t('common.save') }}</va-button>
          </div>
        </form>
      </va-card-content>
    </va-card>
  </div>
</template>
<script>
import { router, useForm } from '@inertiajs/vue3'

export default {
  layout: (h, page) => h(AppLayout, [page]),
  props: {
    policies: Array,
    categories: Array,
    automation: Object,
    staff: Array,
    priorities: Array,
    applications: { type: Array, default: () => [] },
  },
  data () {
    return {
      newPolicy: useForm({ name: '' }),
      automationForm: useForm({
        auto_assign: this.automation.auto_assign,
        pool_ids: [...this.automation.pool_ids],
        app_rules: this.automation.app_rules.map(rule => ({ application_id: rule.application_id, pool_ids: [...rule.pool_ids] })),
        reminder_days: this.automation.reminder_days,
        auto_resolve_days: this.automation.auto_resolve_days,
        auto_close_days: this.automation.auto_close_days,
        retention_days: this.automation.retention_days,
      }),
    }
  },
  computed: {
    policyOptions () {
      return this.policies.map(policy => ({ value: policy.id, text: policy.name }))
    },
    // Applications that do not have a rule yet
    unusedApplications () {
      const used = this.automationForm.app_rules.map(rule => rule.application_id)
      return this.applications.filter(application => !used.includes(application.value))
    },
    assignOptions () {
      return ['off', 'round_robin', 'least_loaded'].map(value => ({ value, text: this.$t(`tickets.settings.assignModes.${value}`) }))
    },
  },
  methods: {
    // Each rule may keep its own application and offer the ones nobody else uses
    applicationOptions (rule) {
      const used = this.automationForm.app_rules.map(other => other.application_id)
      return this.applications.filter(application => application.value === rule.application_id || !used.includes(application.value))
    },
    addRule () {
      this.automationForm.app_rules.push({ application_id: this.unusedApplications[0]?.value ?? null, pool_ids: [] })
    },
    removeRule (index) {
      this.automationForm.app_rules.splice(index, 1)
    },
    addPolicy () {
      this.newPolicy.post('/admin/support/settings/policies', { preserveScroll: true, onSuccess: () => this.newPolicy.reset() })
    },
    setCategoryPolicy (category, policyId) {
      router.put(`/admin/support/settings/categories/${category.id}`, { sla_policy_id: policyId ?? null }, { preserveScroll: true })
    },
    saveAutomation () {
      this.automationForm.put('/admin/support/settings/automation', { preserveScroll: true })
    },
  },
}
</script>
