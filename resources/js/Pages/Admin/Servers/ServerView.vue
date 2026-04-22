<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { Link } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <Head>
    <title>{{ t('admin.servers.server') }} - Control Panel</title>
  </Head>
  <va-card class="mb-4">
    <va-card-title>{{ t('admin.servers.serverTitle', { name: server.name }) }}</va-card-title>
      <va-card-content>
        <div class="row justify-center">
          <Link :href="'/admin/server/servers/'+server.id+'/set_default'" v-if="!server.default && server.status == 'active'"><va-button>{{ t('admin.servers.setDefaultServer') }}</va-button></Link>
          <Link :href="'/admin/server/servers/'+server.id+'/edit'"><va-button>{{ t('admin.servers.editServer') }}</va-button></Link>
        </div>
        <va-list>
          <va-list-item v-if="server.app_instance" class="py-3">
            <va-list-item-section label>
              <va-list-item-label>
                <h5>{{ t('admin.servers.appInstance') }}</h5>
              </va-list-item-label>
            </va-list-item-section>
            <va-list-item-section>
              <va-list-item-label>
                <Link :href="'/admin/organizations/'+server.app_instance.organization_id+'/apps/'+server.app_instance.id">{{ server.app_instance.label }}</Link>
              </va-list-item-label>
            </va-list-item-section>
          </va-list-item>

          <va-list-item class="py-3">
            <va-list-item-section label>
              <va-list-item-label>
                <h5>{{ t('admin.servers.serverName') }}</h5>
              </va-list-item-label>
            </va-list-item-section>
            <va-list-item-section>
              <va-list-item-label>
                {{ server.name }}
              </va-list-item-label>
            </va-list-item-section>
          </va-list-item>

          <va-list-separator class="my-1" fit />
          <va-list-item class="py-3">
            <va-list-item-section label>
              <va-list-item-label>
                <h5>{{ t('admin.servers.host') }}</h5>
              </va-list-item-label>
            </va-list-item-section>
            <va-list-item-section>
              {{ server.host }}
            </va-list-item-section>
          </va-list-item>

          <va-list-separator class="my-1" fit />
          <va-list-item class="py-3">
            <va-list-item-section label>
              <va-list-item-label>
                <h5>{{ t('admin.servers.address') }}</h5>
              </va-list-item-label>
            </va-list-item-section>
            <va-list-item-section>
              {{ server.address }}
            </va-list-item-section>
          </va-list-item>

          <va-list-separator class="my-1" fit />
          <va-list-item class="py-3">
            <va-list-item-section label>
              <va-list-item-label>
                <h5>{{ t('admin.servers.apiKey') }}</h5>
              </va-list-item-label>
            </va-list-item-section>
            <va-list-item-section>
              {{ server.api_key }}
            </va-list-item-section>
          </va-list-item>

          <va-list-separator class="my-1" fit />
          <va-list-item class="py-3">
            <va-list-item-section label>
              <va-list-item-label>
                <h5>{{ t('admin.servers.apiSecret') }}</h5>
              </va-list-item-label>
            </va-list-item-section>
            <va-list-item-section>
              ********************************************
            </va-list-item-section>
          </va-list-item>

          <va-list-separator class="my-1" fit />
          <va-list-item class="py-3">
            <va-list-item-section label>
              <va-list-item-label>
                <h5>{{ t('admin.servers.ip') }}</h5>
              </va-list-item-label>
            </va-list-item-section>
            <va-list-item-section>
              {{ server.ip }}
            </va-list-item-section>
          </va-list-item>

          <va-list-separator class="my-1" fit />
          <va-list-item class="py-3">
            <va-list-item-section label>
              <va-list-item-label>
                <h5>{{ t('admin.servers.internalAddress') }}</h5>
              </va-list-item-label>
            </va-list-item-section>
            <va-list-item-section>
              {{ server.internal_address }}
            </va-list-item-section>
          </va-list-item>

          <template v-if="server.default_web_server == 1">
            <va-list-separator class="my-1" fit />
            <va-list-item class="py-3">
              <va-list-item-section label>
                <va-list-item-label>
                  <h5>{{ t('admin.servers.defaultWebServer') }}</h5>
                </va-list-item-label>
              </va-list-item-section>
              <va-list-item-section>
                <va-icon name="va-check" color="success" />
              </va-list-item-section>
            </va-list-item>
          </template>

          <template v-if="server.default_database_server == 1">
            <va-list-separator class="my-1" fit />
            <va-list-item class="py-3">
              <va-list-item-section label>
                <va-list-item-label>
                  <h5>{{ t('admin.servers.defaultDatabaseServer') }}</h5>
                </va-list-item-label>
              </va-list-item-section>
              <va-list-item-section>
                <va-icon name="va-check" color="success" />
              </va-list-item-section>
            </va-list-item>
          </template>

          <template v-if="server.default_email_server == 1">
            <va-list-separator class="my-1" fit />
            <va-list-item class="py-3">
              <va-list-item-section label>
                <va-list-item-label>
                  <h5>{{ t('admin.servers.defaultEmailServer') }}</h5>
                </va-list-item-label>
              </va-list-item-section>
              <va-list-item-section>
                <va-icon name="va-check" color="success" />
              </va-list-item-section>
            </va-list-item>
          </template>

          <va-list-separator class="my-1" fit />
          <va-list-item class="py-3">
            <va-list-item-section label>
              <va-list-item-label>
                <h5>{{ t('admin.servers.type') }}</h5>
              </va-list-item-label>
            </va-list-item-section>
            <va-list-item-section>
              {{ server.type }}
            </va-list-item-section>
          </va-list-item>

          <va-list-separator class="my-1" fit />
          <va-list-item class="py-3">
            <va-list-item-section label>
              <va-list-item-label>
                <h5>{{ t('admin.servers.settings') }}</h5>
              </va-list-item-label>
            </va-list-item-section>
            <va-list-item-section>
              <div>
                <template v-for="(setting, name) in server.settings" :key="name">
                  <span class="va-text-bold mr-2">{{ name }}:</span> {{ setting }}<br />
                </template>
              </div>
            </va-list-item-section>
          </va-list-item>
        </va-list>
    </va-card-content>
  </va-card>
</template>

<script lang="ts">
export default {
  layout: (h, page) => h(AppLayout, [page]),
  props: {
    server: Object
  }
}
</script>

<style>
.full-width {
  width: 100%
}
</style>
