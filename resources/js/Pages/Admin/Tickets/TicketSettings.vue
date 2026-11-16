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
  },
  data () {
    return {
      newPolicy: useForm({ name: '' }),
      automationForm: useForm({
        auto_assign: this.automation.auto_assign,
        pool_ids: [...this.automation.pool_ids],
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
    assignOptions () {
      return ['off', 'round_robin', 'least_loaded'].map(value => ({ value, text: this.$t(`tickets.settings.assignModes.${value}`) }))
    },
  },
  methods: {
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
