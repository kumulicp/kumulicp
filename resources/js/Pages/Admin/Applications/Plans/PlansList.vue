<script setup>
import AppLayout from '@/layouts/AppLayout.vue'
import AppsLayout from '../AppsLayout.vue'
import { Link, useForm, router } from '@inertiajs/vue3'
import draggable from 'vuedraggable'

</script>
<template>
  <Head>
    <title>{{ $t('admin.plans.plans') }} - Control Panel</title>
  </Head>
  <div class="row">
    <div class="flex flex-col xs12 va-text-center">
      <div>
        <va-button id="addPlan" @click="showAddPlan = !showAddPlan">{{ $t('admin.plans.addPlan') }}</va-button>
        <va-button
          v-if="selectedPlans.length > 0"
          id="bulkEditButton"
          class="ml-2"
          color="secondary"
          @click="bulkEdit"
        >{{ $t('admin.plans.bulkEdit') }} ({{ selectedPlans.length }})</va-button>
      </div>
      <va-modal v-model="showAddPlan" no-outside-dismiss no-padding size="small" class="p-0">
        <template #content="{ ok }">
          <form @submit.prevent="form.post('/admin/apps/'+app.slug+'/plans')">
            <va-card-title class="m-0">{{ $t('admin.plans.addPlan') }}</va-card-title>
            <va-card-content class="m-0">
              <va-input v-model="form.name"
                id="name"
                :label="$t('common.name')"
                class="mb-3"
                required-mark
                immediateValidation
                :error="$page.props.errors.name"
                :error-messages="$page.props.errors.name"
                />
              <va-input v-model="form.description"
                id="description"
                :label="$t('admin.plans.description')"
                class="mb-3"
                required-mark
                immediateValidation
                :error="$page.props.errors.description"
                :error-messages="$page.props.errors.description"
                />
              <template v-if="canChooseServerType">
                <va-select v-model="form.server_type"
                  id="serverType"
                  :label="$t('admin.plans.serverType')"
                  :messages="$t('admin.plans.serverTypeCaption')"
                  class="mb-3"
                  value-by="value"
                  text-by="text"
                  :options="serverTypes"
                  :error="!!$page.props.errors.server_type"
                  :error-messages="$page.props.errors.server_type"
                />
                <va-select v-if="form.server_type === 'shared'"
                  v-model="form.shared_app"
                  id="sharedApp"
                  :label="$t('admin.plans.sharedApp')"
                  class="mb-3"
                  required-mark
                  value-by="id"
                  text-by="name"
                  :options="shared_apps || []"
                  :error="!!$page.props.errors.shared_app"
                  :error-messages="$page.props.errors.shared_app"
                />
              </template>
            </va-card-content>
            <va-card-actions align="right" class="">
              <va-button color="textInverted" :disabled="form.processing" @click="ok">{{ $t('common.cancel') }}</va-button>
              <va-button type="submit" id="submit" :disabled="form.processing" class="mr-2 mb-2">{{ $t('common.submit') }}</va-button>
            </va-card-actions>
          </form>
        </template>
      </va-modal>
      <va-modal v-model="showClonePlan" no-outside-dismiss no-padding size="small" class="p-0">
        <template #content="{ ok }">
          <form v-if="cloneTarget" @submit.prevent="submitClone">
            <va-card-title class="m-0">{{ $t('admin.plans.clonePlanTitle', { name: cloneTarget.name }) }}</va-card-title>
            <va-card-content class="m-0">
              <p class="va-text-secondary mb-3">{{ $t('admin.plans.cloneHelp', { name: cloneTarget.name }) }}</p>
              <va-input v-model="cloneForm.name"
                id="cloneName"
                :label="$t('common.name')"
                class="mb-3"
                required-mark
                :error="!!cloneForm.errors.name"
                :error-messages="cloneForm.errors.name"
                />
            </va-card-content>
            <va-card-actions align="right" class="">
              <va-button color="textInverted" :disabled="cloneForm.processing" @click="ok">{{ $t('common.cancel') }}</va-button>
              <va-button type="submit" id="cloneSubmit" :disabled="cloneForm.processing" class="mr-2 mb-2">{{ $t('common.submit') }}</va-button>
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
            <th style="width: 40px"></th>
            <th style="width: 50px">{{ $t('admin.plans.default') }}</th>
            <th>{{ $t('admin.plans.name') }}</th>
            <th>{{ $t('admin.plans.description')}}</th>
            <th>{{ $t('admin.plans.activeSubscribers') }}</th>
            <th style="width: 100px"></th>
          </tr>
        </thead>
        <draggable v-model="order.plans" tag="tbody" item-key="id">
          <template #item="{ element }">
            <tr>
              <td>
                <va-checkbox
                  :id="'plan-checkbox-'+element.id"
                  :model-value="selectedPlans.includes(element.id)"
                  @update:model-value="togglePlan(element.id)"
                />
              </td>
              <td style="text-align: center"><va-icon name="fa-check" color="success" v-if="element.is_default" /></td>
              <td><Link :href="'/admin/apps/'+app.slug+'/plans/'+element.id">{{ element.name }}</Link></td>
              <td>{{ element.description }}</td>
              <td>{{ element.active_subscribers }}</td>
              <td>
                <va-button
                  :id="'clone-plan-'+element.id"
                  type="button"
                  size="small"
                  preset="secondary"
                  @click="openClone(element)"
                >{{ $t('admin.plans.clone') }}</va-button>
              </td>
            </tr>
          </template>
        </draggable>
      </table>
    </va-scroll-container>
    <div class="block mb-6">
      <p class="va-text-secondary mb-3">
        {{ $t('admin.plans.changeOrder') }}
      </p>
      <va-button type="submit" :disabled="order.processing">{{ $t('admin.plans.updateOrder') }}</va-button>
    </div>
  </form>
  <va-scroll-container
    horizontal
  >
    <table class="va-table va-table--hoverable my-3">
      <thead>
        <tr>
          <th>{{ $t('admin.plans.archivedPlan') }}</th>
          <th>{{ $t('admin.plans.description') }}</th>
          <th>{{ $t('admin.plans.activeSubscribers') }}</th>
          <th style="width: 100px"></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="(plan, index) in archived" :key="index">
          <td><Link :href="'/admin/apps/'+app.slug+'/plans/'+plan.id">{{ plan.name }}</Link></td>
          <td>{{ plan.description }}</td>
          <td>{{ plan.active_subscribers }}</td>
          <td>
            <va-button
              :id="'clone-plan-'+plan.id"
              type="button"
              size="small"
              preset="secondary"
              @click="openClone(plan)"
            >{{ $t('admin.plans.clone') }}</va-button>
          </td>
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
    shared_apps: Array,
    errors: Object
  },
  data() {
    return {
      serverTypes: [
        { text: this.$t('admin.plans.serverTypeSeparate'), value: 'separate' },
        { text: this.$t('admin.plans.serverTypeShared'), value: 'shared' }
      ],
      showAddPlan: false,
      showClonePlan: false,
      cloneTarget: null,
      cloneForm: useForm({
        name: ''
      }),
      curPageValue: 1,
      pageSize: 10,
      selectedPlans: [],
      form: useForm({
        name: '',
        description: '',
        server_type: 'separate',
        shared_app: null
      }),
      order: useForm({
        plans: this.plans
      })
    }
  },
  computed: {
    // Only an app that can be shared has a choice of server type
    canChooseServerType () {
      return this.app.can?.shareable && this.$page.props.flags?.sharedApps
    }
  },
  methods: {
    togglePlan (id) {
      const idx = this.selectedPlans.indexOf(id)
      if (idx === -1) {
        this.selectedPlans.push(id)
      } else {
        this.selectedPlans.splice(idx, 1)
      }
    },
    openClone (plan) {
      this.cloneTarget = plan
      this.cloneForm.reset()
      this.cloneForm.clearErrors()
      this.cloneForm.name = this.$t('admin.plans.cloneNameDefault', { name: plan.name })
      this.showClonePlan = true
    },
    submitClone () {
      this.cloneForm.post(`/admin/apps/${this.app.slug}/plans/${this.cloneTarget.id}/clone`, {
        onSuccess: () => {
          this.showClonePlan = false
        }
      })
    },
    bulkEdit () {
      if (this.selectedPlans.length === 0) return
      const params = this.selectedPlans.map(id => `plans[]=${id}`).join('&')
      router.visit(`/admin/apps/${this.app.slug}/plans/bulk-edit/edit?${params}`)
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
