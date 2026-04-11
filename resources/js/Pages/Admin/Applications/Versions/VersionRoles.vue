<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import VersionLayout from './VersionLayout.vue'
import { useForm } from '@inertiajs/vue3'
import draggable from 'vuedraggable'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <Head>
    <title>Version Roles - Control Panel</title>
  </Head>
  <div class="app-profile">
    <div class="row">
      <div class="flex xs12 lg8">
      <form @submit.prevent="form.post('/admin/apps/'+app.slug+'/versions/'+version.version+'/roles')">
        <va-list>
          <va-list-item>
            <va-list-item-section>
              <va-list-item-label>
                <h5>{{ t('admin.versions.selectedRoles') }}</h5>
              </va-list-item-label>
            </va-list-item-section>
          </va-list-item>
          <draggable
            v-model="select_roles"
            group="roles"
            item-key="name"
            @change="updateOrder"
          >
          <template #item="{ element }">
            <div>
              <va-list-item class="py-1">
                <va-list-item-section icon>
                  <va-list-item-label>
                    {{ element.id }}
                  </va-list-item-label>
                </va-list-item-section>
                <va-list-item-section>
                  <va-list-item-label label>
                    {{ element.name }}
                  </va-list-item-label>
                </va-list-item-section>
              </va-list-item>
              <va-list-separator class="my-1" fit />
            </div>
          </template>
          </draggable>
        </va-list>
        <va-button type="submit" class="mr-2 mb-2" :disabled="form.processing">Update</va-button>
      </form>
      </div>
      <div class="flex xs12 lg4">
        <va-list>
          <va-list-item>
            <va-list-item-section>
              <va-list-item-label>
                <h5>{{ t('admin.versions.availableRoles') }}</h5>
              </va-list-item-label>
            </va-list-item-section>
          </va-list-item>
          <draggable
            v-model="available_roles"
            item-key="name"
            group="roles"
          >
          <template #item="{ element }">
            <div>
              <va-list-item class="py-1">
                <va-list-item-section icon>
                  <va-list-item-label>
                    {{ element.id }}
                  </va-list-item-label>
                </va-list-item-section>
                <va-list-item-section>
                  <va-list-item-label label>
                    {{ element.name }}
                  </va-list-item-label>
                </va-list-item-section>
              </va-list-item>
              <va-list-separator class="my-1" fit />
            </div>
          </template>
          </draggable>
        </va-list>
      </div>
    </div>
  </div>
</template>

<script lang="ts">
export default {
  layout: (h, page) => {
    return h(AppLayout, () => h(VersionLayout, () => page))
  },
  props: {
    version: Object,
    errors: Object,
    roles: Object,
    app: Object
  },
  data () {
    const order = {}
    for (const [key, role] of Object.entries(this.version.roles)) {
      if (role) {
        order[key] = role.id
      }
    }

    return {
      select_roles: this.version.roles.selected,
      available_roles: this.version.roles.available,
      form: useForm({
        order
      }),
      dragging: false
    }
  },
  methods: {
    updateOrder () {
      const order = {}
      for (const [key, role] of Object.entries(this.select_roles)) {
        if (role) {
          order[key] = role.id
        }
      }
      this.form.order = order
    }
  }
}
</script>

<style>
</style>
