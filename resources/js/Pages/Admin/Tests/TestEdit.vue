<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import AdminSettings from '@/components/AdminSettings.vue'
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <Head>
    <title>Edit Test - Control Panel</title>
  </Head>
  <va-card class="mb-4">
    <va-card-title>Edit Test: {{ test.description }}</va-card-title>
    <va-card-content>
      <div class="row justify-center">
        <va-button class="mb-3" @click="showRunTest = !showRunTest">Run Test</va-button>
        <va-modal v-model="showRunTest" no-outside-dismiss no-padding>
          <template #content="{ ok }">
            <form @submit.prevent="run.get('/admin/server/tests/'+test.id+'/run')">
              <va-card-title>Run Test</va-card-title>
              <va-card-content>
                Are you sure you want to run this test? You will not be able to change your settings after this.
              </va-card-content>
              <va-card-actions align="right">
                <va-button color="textInverted" :disabled="run.processing" @click="ok">Cancel</va-button>
                <va-button type="submit" class="mr-2 mb-2" :disabled="run.processing">Yes, run!</va-button>
              </va-card-actions>
            </form>
          </template>
        </va-modal>
      </div>
      <form @submit.prevent="form.put('/admin/server/tests/'+test.id)">
        <AdminSettings>
          <template #name>{{ t('admin.tests.settings') }}</template>
          <template #settings>
            <va-input v-model="form.description"
              :label="t('admin.tests.description')"
              class="mb-2"
              immediateValidation
              :error="$page.props.errors.description"
              :error-messages="$page.props.errors.description"
            />
            <va-input v-model="form.test_number"
              :label="t('admin.tests.number')"
              class="mb-2"
              type="number"
              immediateValidation
              min="0"
              :error="$page.props.errors.test_number"
              :error-messages="$page.props.errors.test_number"
            />
            <va-select v-model="form.base_plan"
              :label="t('admin.plans.basePlan')"
              class="mb-2"
              :options="base_plans"
              immediateValidation
              value-by="id"
              text-by="name"
              :error="$page.props.errors.base_plan"
              :error-messages="$page.props.errors.base_plan"
            />
          </template>
        </AdminSettings>
        <va-list-separator class="my-1" fit />
        <h3 class="va-h3">App Settings</h3>
        <AdminSettings v-for="(app, index) in apps" :key="index">
          <template #name>{{ app.name }}</template>
          <template #settings>
            <va-select v-model="form['apps'][app.slug]['plan']"
              :label="t('admin.plans.plan')"
              class="mb-2"
              :options="app.plans"
              immediateValidation
              value-by="id"
              text-by="name"
            />
            <va-select v-model="form['apps'][app.slug]['version']"
              :label="t('admin.versions.version')"
              class="mb-2"
              :options="app.versions"
              immediateValidation
              value-by="id"
              text-by="version"
            />
          </template>
        </AdminSettings>
        <va-button type="submit" :disabled="form.processing" class="mr-2 mb-2">Update</va-button>
      </form>
    </va-card-content>
  </va-card>
</template>

<script lang="ts">
export default {
  layout: (h, page) => h(AppLayout, [page]),
  props: {
    test: Object,
    base_plans: Object,
    apps: Object,
    errors: Object
  },
  data () {
    const apps = {}
    Object.values(this.apps).forEach((app) => {
      let plan = this.test.settings.apps[app.slug]?.plan
      let version = this.test.settings.apps[app.slug]?.version
      apps[app.slug] = {
        id: app.id,
        plan,
        version
      }
    })

    return {
      showRunTest: false,
      run: useForm({}),
      app_plans: apps,
      form: useForm({
        description: this.test.description,
        test_number: this.test.test_number,
        base_plan: this.test.settings.base_plan,
        apps
      })
    }
  }
}
</script>

<style></style>
