<script setup>
import AppLayout from '@/layouts/AppLayout.vue'
import { Link } from '@inertiajs/vue3'
import SlaPolicyForm from '@/components/tickets/SlaPolicyForm.vue'
</script>
<template>
  <Head>
    <title>{{ $t('tickets.settings.title') }} - Control Panel</title>
  </Head>

  <va-card class="mb-4">
    <va-card-title>{{ $t('tickets.settings.slaTitle') }}</va-card-title>
    <va-card-content>
      <p class="va-p mb-3">{{ $t('tickets.settings.slaIntro') }}</p>
      <form class="flex items-end" @submit.prevent="addPolicy">
        <va-input v-model="newPolicy.name" class="mr-2" :label="$t('tickets.settings.newPolicy')" :error="!!newPolicy.errors.name" :error-messages="newPolicy.errors.name" />
        <va-button type="submit" :disabled="newPolicy.processing">{{ $t('common.add') }}</va-button>
      </form>
    </va-card-content>
  </va-card>

  <SlaPolicyForm v-for="policy in policies" :key="policy.id + ':' + policy.name" :policy="policy" :priorities="priorities" />

  <va-card v-if="policies.length && categories.length" class="mb-4">
    <va-card-title>{{ $t('tickets.settings.categoryPolicies') }}</va-card-title>
    <va-card-content>
      <p class="va-p mb-3">{{ $t('tickets.settings.categoryPoliciesHelp') }}</p>
      <div v-for="category in categories" :key="category.id" class="row items-center mb-2">
        <div class="flex xs12 md4">{{ category.name }}</div>
        <div class="flex xs12 md4">
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
      </div>
    </va-card-content>
  </va-card>

  <va-card class="mb-4">
    <va-card-title>{{ $t('tickets.settings.automationTitle') }}</va-card-title>
    <va-card-content>
      <form @submit.prevent="saveAutomation">
        <va-select
          v-model="automationForm.auto_assign"
          class="mb-3"
          :label="$t('tickets.settings.autoAssign')"
          :options="assignOptions"
          value-by="value"
          text-by="text"
          :messages="$t('tickets.settings.autoAssignHelp')"
        />
        <va-select
          v-if="automationForm.auto_assign !== 'off'"
          v-model="automationForm.pool_ids"
          class="mb-3"
          :label="$t('tickets.settings.pool')"
          :options="staff"
          value-by="id"
          text-by="name"
          multiple
          :messages="$t('tickets.settings.poolHelp')"
        />

        <div class="row">
          <div class="flex xs12 md6 pr-2 mb-3">
            <va-input v-model="automationForm.reminder_days" type="number" min="0" :label="$t('tickets.settings.reminderDays')" :messages="$t('tickets.settings.zeroOff')" :error="!!automationForm.errors.reminder_days" />
          </div>
          <div class="flex xs12 md6 mb-3">
            <va-input v-model="automationForm.auto_resolve_days" type="number" min="0" :label="$t('tickets.settings.autoResolveDays')" :messages="$t('tickets.settings.zeroOff')" :error="!!automationForm.errors.auto_resolve_days" />
          </div>
          <div class="flex xs12 md6 pr-2 mb-3">
            <va-input v-model="automationForm.auto_close_days" type="number" min="0" :label="$t('tickets.settings.autoCloseDays')" :messages="$t('tickets.settings.zeroOff')" :error="!!automationForm.errors.auto_close_days" />
          </div>
          <div class="flex xs12 md6 mb-3">
            <va-input v-model="automationForm.retention_days" type="number" min="0" :label="$t('tickets.settings.retentionDays')" :messages="$t('tickets.settings.retentionHelp')" :error="!!automationForm.errors.retention_days" />
          </div>
        </div>

        <va-button type="submit" :disabled="automationForm.processing">{{ $t('common.save') }}</va-button>
      </form>
    </va-card-content>
  </va-card>

  <va-card class="mb-4">
    <va-card-title>{{ $t('tickets.settings.more') }}</va-card-title>
    <va-card-content>
      <Link href="/admin/support/canned"><va-button color="secondary" class="mr-2">{{ $t('tickets.canned.title') }}</va-button></Link>
      <Link href="/admin/support/reports"><va-button color="secondary" class="mr-2">{{ $t('tickets.reports.title') }}</va-button></Link>
      <Link href="/admin/support/integrations"><va-button color="secondary">{{ $t('tickets.integrations.title') }}</va-button></Link>
    </va-card-content>
  </va-card>
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
