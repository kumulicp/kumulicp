<script setup>
import AppLayout from '@/layouts/AppLayout.vue'
import AppsLayout from '../AppsLayout.vue'
import { Link, useForm } from '@inertiajs/vue3'
import draggable from 'vuedraggable'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <Head>
    <title>Plans - Control Panel</title>
  </Head>
  <div class="row">
    <div class="flex flex-col xs12 va-text-center">
      <div>
        <va-button id="addPlan" @click="showAddPlan = !showAddPlan">{{ t('admin.plans.addPlan') }}</va-button>
      </div>
      <va-modal v-model="showAddPlan" no-outside-dismiss no-padding size="small" class="p-0">
        <template #content="{ ok }">
          <form @submit.prevent="form.post('/admin/apps/'+app.slug+'/plans')">
            <va-card-title class="m-0">{{ t('admin.plans.addPlan') }}</va-card-title>
            <va-card-content class="m-0">
              <va-input v-model="form.name"
                id="name"
                :label="t('form.name')"
                class="mb-3"
                required-mark
                immediateValidation
                :error="$page.props.errors.name"
                :error-messages="$page.props.errors.name"
                />
              <va-input v-model="form.description"
                id="description"
                :label="t('admin.plans.description')"
                class="mb-3"
                required-mark
                immediateValidation
                :error="$page.props.errors.description"
                :error-messages="$page.props.errors.description"
                />
            </va-card-content>
            <va-card-actions align="right" class="">
              <va-button color="textInverted" :disabled="form.processing" @click="ok">{{ t('form.cancel') }}</va-button>
              <va-button type="submit" id="submit" :disabled="form.processing" class="mr-2 mb-2">{{ t('form.submit') }}</va-button>
            </va-card-actions>
          </form>
        </template>
      </va-modal>
    </div>
  </div>
  <form @submit.prevent="order.post('/admin/apps/'+app.slug+'/plans/update_order')">
    <va-scroll-container
      horizontal
    >
      <table class="va-table va-table--hoverable my-3">
        <thead>
          <tr>
            <th style="width: 50px">{{ t('admin.plans.default') }}</th>
            <th>{{ t('admin.plans.name') }}</th>
            <th>{{ t('admin.plans.description')}}</th>
            <th>{{ t('admin.plans.activeSubscribers') }}</th>
          </tr>
        </thead>
        <draggable v-model="order.plans" tag="tbody" item-key="id">
          <template  #item="{ element }">
            <tr style="min-height:300px;">
              <td style="text-align: center"><va-icon name="fa-check" color="success" v-if="element.is_default" /></td>
              <td><Link :href="'/admin/apps/'+app.slug+'/plans/'+element.id">{{ element.name }}</Link></td>
              <td>{{ element.description }}</td>
              <td>{{ element.active_subscribers }}</td>
            </tr>
          </template>
        </draggable>
      </table>
    </va-scroll-container>
    <div class="row">
      <div class="flex flex-col xs12">
        <p class="va-text-secondary">
          {{ t('admin.plans.changeOrder') }}
        </p>
      </div>
      <div class="flex flex-col xs12">
        <div>
          <va-button type="submit" class="mr-2 mb-2" :disabled="form.processing">{{ t('admin.plans.updateOrder') }}</va-button>
        </div>
      </div>
    </div>
  </form>
  <va-scroll-container
    horizontal
  >
    <table class="va-table va-table--hoverable my-3">
      <thead>
        <tr>
          <th>{{ t('admin.plans.archivedPlan') }}</th>
          <th>{{ t('admin.plans.description') }}</th>
          <th>{{ t('admin.plans.activeSubscribers') }}</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="(plan, index) in archived" style="min-height:300px;" :key="index">
          <td><Link :href="'/admin/apps/'+app.slug+'/plans/'+plan.id">{{ plan.name }}</Link></td>
          <td>{{ plan.description }}</td>
          <td>{{ plan.active_subscribers }}</td>
        </tr>
      </tbody>
    </table>
  </va-scroll-container>
</template>

<script>
export default {
  layout: (h, page) => {
    return h(AppLayout, () => h(AppsLayout, () => page))
  },
  props: {
    app: Object,
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
