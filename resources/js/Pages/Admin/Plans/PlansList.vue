<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { Link, useForm } from '@inertiajs/vue3'
import draggable from 'vuedraggable'

</script>
<template>
  <Head>
    <title>{{ $t('admin.plans.plans') }} - Control Panel</title>
  </Head>
  <va-card class="mb-4">
    <va-card-title>{{ $t('admin.plans.plans') }}</va-card-title>
    <va-card-content>
      <div class="row justify-center">
        <va-button id="addPlan" class="" @click="showAddPlan = !showAddPlan">{{ $t('admin.plans.addPlan') }}</va-button>
        <va-modal v-model="showAddPlan" no-outside-dismiss no-padding size="small" class="p-0">
          <template #content="{ ok }">
            <form @submit.prevent="form.post('/admin/service/plans')">
              <va-card-title class="m-0"> {{ $t('admin.plans.addPlan') }} </va-card-title>
              <va-card-content class="m-0">
                <va-input id="planName" v-model="form.name"
                  :label="$t('admin.plans.name')"
                  class="mb-3"
                  required-mark
                  immediateValidation
                  :error="$page.props.errors.name"
                  :error-messages="$page.props.errors.name"
                  />
                <va-input id="planDescription" v-model="form.description"
                  :label="$t('admin.plans.description')"
                  class="mb-3"
                  required-mark
                  immediateValidation
                  :error="$page.props.errors.description"
                  :error-messages="$page.props.errors.description"
                  />
                <va-select
                  id="planType"
                  v-model="form.type"
                  :label="$t('admin.plans.planType')"
                  class="mb-3"
                  value-by="value"
                  text-by="text"
                  required-mark
                  immediateValidation
                  :options="planTypes"
                  :error="$page.props.errors.type"
                  :error-messages="$page.props.errors.type"
                />
                <p v-if="form.type" class="va-text-secondary mb-2" style="font-size:0.85rem;">
                  {{ form.type === 'package' ? $t('admin.plans.packageDescription') : $t('admin.plans.payPerAppDescription') }}
                </p>
              </va-card-content>
              <va-card-actions align="right" class="">
                <va-button color="textInverted" :disabled="form.processing" @click="ok">{{ $t('common.cancel') }}</va-button>
                <va-button id="addPlanSubmit" type="submit" :disabled="form.processing" class="mr-2 mb-2">{{ $t('common.submit') }}</va-button>
              </va-card-actions>
            </form>
          </template>
        </va-modal>
      </div>
      <div class="plans-list">
        <div class="row">
          <div class="flex flex-col xs12 lg12">
            <form @submit.prevent="order.post('/admin/service/plans/update_order')">
            <table class="va-table va-table--hoverable my-3">
              <thead>
                <tr>
                  <th style="width: 50px">{{ $t('admin.plans.default') }}</th>
                  <th>{{ $t('admin.plans.name') }}</th>
                  <th>{{ $t('admin.plans.description') }}</th>
                  <th>{{ $t('admin.plans.planType') }}</th>
                  <th>{{ $t('admin.plans.activeSubscribers') }}</th>
                </tr>
              </thead>
              <draggable v-model="order.plans" tag="tbody" item-key="id">
                <template  #item="{ element }">
                  <tr style="min-height:300px;">
                    <td style="text-align: center"><va-icon name="fa-check" color="success" v-if="element.is_default" /></td>
                    <td><Link :href="'/admin/service/plans/'+element.id">{{ element.name }}</Link></td>
                    <td>{{ element.description }}</td>
                    <td>{{ element.org_type }}</td>
                    <td>{{ element.active_subscribers }}</td>
                  </tr>
                </template>
              </draggable>
            </table>
            <p class="va-text-secondary mb-3">
              {{ $t('admin.plans.changeOrder') }}
            </p>
            <va-button type="submit" class="mr-2 mb-2" :disabled="form.processing">{{ $t('admin.plans.updateOrder') }}</va-button>
            </form>
            <table class="va-table va-table--hoverable my-3">
              <thead>
                <tr>
                  <th>{{ $t('admin.plans.archivedPlan') }}</th>
                  <th>{{ $t('admin.plans.description') }}</th>
                  <th>{{ $t('admin.plans.planType') }}</th>
                  <th>{{ $t('admin.plans.activeSubscribers') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(plan, index) in archived" style="min-height:300px;" :key="index">
                  <td><Link :href="'/admin/service/plans/'+plan.id">{{ plan.name }}</Link></td>
                  <td>{{ plan.description }}</td>
                  <td>{{ plan.org_type }}</td>
                  <td>{{ plan.active_subscribers }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </va-card-content>
  </va-card>
</template>

<script lang="ts">
export default {
  layout: (h, page) => h(AppLayout, [page]),
  props: {
    plans: Object,
    archived: Object,
    errors: Object
  },
  data () {
    return {
      showAddPlan: false,
      curPageValue: 1,
      pageSize: 10,
      planTypes: [
        { text: this.$t('admin.plans.package'), value: 'package' },
        { text: this.$t('admin.plans.payPerApp'), value: 'app' }
      ],
      form: useForm({
        name: '',
        description: '',
        type: 'package'
      }),
      order: useForm({
        plans: this.plans
      })
    }
  }
}
</script>

<style lang="scss">
.clickable-icon {
  transition: 0.3s;

  &:hover {
    opacity: 0.25;
    cursor: pointer;
  }
}
</style>
