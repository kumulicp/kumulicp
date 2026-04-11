<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { Link, useForm } from '@inertiajs/vue3'
import draggable from 'vuedraggable'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <Head>
    <title>Plans - Control Panel</title>
  </Head>
  <va-card class="mb-4">
    <va-card-title>Plans</va-card-title>
    <va-card-content>
      <div class="row justify-center">
        <va-button class="" @click="showAddPlan = !showAddPlan">Add Plan</va-button>
        <va-modal v-model="showAddPlan" no-outside-dismiss no-padding size="small" class="p-0">
          <template #content="{ ok }">
            <form @submit.prevent="form.post('/admin/service/plans')">
              <va-card-title class="m-0"> Add Plan </va-card-title>
              <va-card-content class="m-0">
                <va-input v-model="form.name"
                  label="Name"
                  class="mb-3"
                  required-mark
                  immediateValidation
                  :error="$page.props.errors.name"
                  :error-messages="$page.props.errors.name"
                  />
                <va-input v-model="form.description"
                  label="Description"
                  class="mb-3"
                  required-mark
                  immediateValidation
                  :error="$page.props.errors.description"
                  :error-messages="$page.props.errors.description"
                  />
              </va-card-content>
              <va-card-actions align="right" class="">
                <va-button color="textInverted" :disabled="form.processing" @click="ok">Cancel</va-button>
                <va-button type="submit" :disabled="form.processing" class="mr-2 mb-2">Submit</va-button>
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
                  <th style="width: 50px">Default</th>
                  <th>Name</th>
                  <th>Description</th>
                  <th>Type</th>
                  <th>Active Subscribers</th>
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
              Change the order of how the plans will be displayed to users by dragging them
            </p>
            <va-button type="submit" class="mr-2 mb-2" :disabled="form.processing">Update Order</va-button>
            </form>
            <table class="va-table va-table--hoverable my-3">
              <thead>
                <tr>
                  <th>Archived Plan</th>
                  <th>Description</th>
                  <th>Type</th>
                  <th>Active Subscribers</th>
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
      form: useForm({
        name: '',
        description: ''
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
