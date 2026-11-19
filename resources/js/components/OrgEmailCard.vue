<script setup lang="ts">
import { computed, ref } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'

interface Plan { changes_email: boolean, current: string, recovery: 'keep' | 'move' | 'required' }
interface Mailbox { address: string, status: 'pending' | 'active', can_undo: boolean, previous_mail: string | null, undo_until: string | null, plan: Plan | null, domain_ready: boolean, domain_name: string | null }
interface Domain { id: number, name: string }
interface App { name: string, identity: 'stable' | 'email' | 'unknown' }
interface OrgEmail { mailbox: Mailbox | null, can_get: boolean, domains: Domain[], default_name: string, current_hosted: boolean, apps: App[] }
interface Recovery { email: string | null, usable: boolean, pending: string | null }

const props = defineProps<{
  orgEmail: OrgEmail
  currentEmail: string
  recovery: Recovery
}>()

const page = usePage()
const show_modal = ref(false)
const show_undo = ref(false)

const form = useForm({
  domain: props.orgEmail.domains[0]?.id ?? null,
  local_part: props.orgEmail.default_name,
  recovery_email: '',
  current_password: '',
  acknowledge: false
})
const undo_form = useForm({ current_password: '' })

const pending = computed(() => props.orgEmail.mailbox?.status === 'pending' ? props.orgEmail.mailbox : null)
const active = computed(() => props.orgEmail.mailbox?.status === 'active' ? props.orgEmail.mailbox : null)

const chosen_domain = computed(() => props.orgEmail.domains.find((domain) => domain.id === form.domain))
const address = computed(() => pending.value
  ? pending.value.address
  : `${form.local_part}@${chosen_domain.value?.name ?? ''}`.toLowerCase())
const current = computed(() => props.currentEmail.toLowerCase())

// What accepting does to the person's email, worked out the way the server will
const plan = computed<Plan>(() => {
  if (pending.value?.plan) {
    return pending.value.plan
  }
  if (address.value === current.value) {
    return { changes_email: false, current: current.value, recovery: 'keep' }
  }
  const recovery = props.recovery.usable ? 'keep' : (props.orgEmail.current_hosted ? 'required' : 'move')
  return { changes_email: true, current: current.value, recovery }
})

const submit_url = computed(() => pending.value ? '/profile/org-email/accept' : '/profile/org-email')

function submit () {
  form.post(submit_url.value, {
    onSuccess: () => {
      form.reset('current_password', 'recovery_email', 'acknowledge')
      show_modal.value = false
    }
  })
}

function undo () {
  undo_form.post('/profile/org-email/undo', {
    onSuccess: () => {
      undo_form.reset()
      show_undo.value = false
    }
  })
}
</script>
<template>
  <va-card class="mb-4" id="orgEmailCard">
    <va-card-title>{{ $t('orgEmailFlow.title') }}</va-card-title>
    <va-card-content>
      <template v-if="active">
        <p id="orgEmailActive" class="mb-2">{{ $t('orgEmailFlow.active', { address: active.address }) }}</p>
        <template v-if="active.can_undo">
          <p class="mb-2">{{ $t('orgEmailFlow.undoUntil', { email: active.previous_mail, date: active.undo_until }) }}</p>
          <va-button preset="secondary" id="undoOrgEmail" @click="show_undo = true">{{ $t('orgEmailFlow.undo') }}</va-button>
        </template>
      </template>

      <template v-else-if="pending">
        <p id="orgEmailOffered" class="mb-2">{{ $t('orgEmailFlow.offered', { address: pending.address }) }}</p>
        <va-alert v-if="!pending.domain_ready" id="orgEmailDomainNotReady" color="info" outline class="mb-2">
          {{ $t('orgEmailFlow.domainNotReady', { domain: pending.domain_name }) }}
        </va-alert>
        <va-button id="reviewOrgEmail" :disabled="!pending.domain_ready" @click="show_modal = true">{{ $t('orgEmailFlow.review') }}</va-button>
      </template>

      <template v-else-if="orgEmail.can_get">
        <p class="mb-2">{{ $t('orgEmailFlow.none', { email: currentEmail }) }}</p>
        <va-button id="getOrgEmail" @click="show_modal = true">{{ $t('orgEmailFlow.get') }}</va-button>
      </template>

      <p v-else>{{ $t('orgEmailFlow.none', { email: currentEmail }) }}</p>
    </va-card-content>

    <va-modal v-model="show_modal" no-outside-dismiss size="medium">
      <template #content="{ ok }">
        <form @submit.prevent="submit">
          <va-card-title>{{ $t('orgEmailFlow.title') }}</va-card-title>
          <va-card-content>
            <template v-if="!pending">
              <div class="row">
                <div class="flex flex-col xs12 md5 mb-2">
                  <va-input v-model="form.local_part"
                    id="orgEmailName"
                    :label="$t('orgEmailFlow.name')"
                    immediateValidation
                    :error="!!$page.props.errors.local_part"
                    :error-messages="$page.props.errors.local_part"
                  />
                </div>
                <div class="flex flex-col xs12 md7 mb-2">
                  <va-select v-model="form.domain"
                    id="orgEmailDomain"
                    :label="$t('orgEmailFlow.domain')"
                    :options="orgEmail.domains"
                    value-by="id"
                    text-by="name"
                    :error="!!$page.props.errors.domain"
                    :error-messages="$page.props.errors.domain"
                  />
                </div>
              </div>
            </template>
            <p id="orgEmailAddress" class="mb-3"><b>{{ $t('orgEmailFlow.address', { address }) }}</b></p>

            <template v-if="!plan.changes_email">
              <va-alert id="orgEmailSame" color="info" class="mb-3">{{ $t('orgEmailFlow.sameAddress') }}</va-alert>
            </template>
            <template v-else>
              <va-alert id="orgEmailWillChange" color="warning" class="mb-3">
                <p>{{ $t('orgEmailFlow.willChange', { address, current: plan.current }) }}</p>
                <p v-if="plan.recovery === 'move'">{{ $t('orgEmailFlow.becomesRecovery', { current: plan.current }) }}</p>
                <p v-else-if="plan.recovery === 'keep'">{{ $t('orgEmailFlow.keepsRecovery', { current: plan.current, recovery: recovery.email }) }}</p>
              </va-alert>

              <template v-if="plan.recovery === 'required'">
                <p class="mb-2">{{ $t('orgEmailFlow.needsRecovery', { current: plan.current }) }}</p>
                <va-input v-model="form.recovery_email"
                  id="orgEmailRecovery"
                  type="email"
                  :label="$t('orgEmailFlow.recoveryEmail')"
                  class="mb-3"
                  :error="!!$page.props.errors.recovery_email"
                  :error-messages="$page.props.errors.recovery_email"
                />
              </template>

              <h6 class="va-h6 mb-1">{{ $t('orgEmailFlow.appsTitle') }}</h6>
              <p class="mb-2">{{ $t('orgEmailFlow.appsIntro') }}</p>
              <ul v-if="orgEmail.apps.length > 0" id="orgEmailApps" class="mb-3">
                <li v-for="app in orgEmail.apps" :key="app.name">
                  <b>{{ app.name }}</b>: {{ $t('orgEmailFlow.identity.' + app.identity) }}
                </li>
              </ul>
              <p v-else class="mb-3">{{ $t('orgEmailFlow.noApps') }}</p>

              <va-checkbox v-model="form.acknowledge"
                id="orgEmailAcknowledge"
                :label="$t('orgEmailFlow.acknowledge')"
                class="mb-3"
                :error="!!$page.props.errors.acknowledge"
                :error-messages="$page.props.errors.acknowledge"
              />
            </template>

            <va-input v-model="form.current_password"
              id="orgEmailPassword"
              type="password"
              required-mark
              :label="$t('auth.currentPassword')"
              :error="!!$page.props.errors.current_password"
              :error-messages="$page.props.errors.current_password"
            />
          </va-card-content>
          <va-card-actions align="right">
            <va-button color="textInverted" @click="ok">{{ $t('common.cancel') }}</va-button>
            <va-button type="submit" id="confirmOrgEmail" :disabled="form.processing" class="mr-2 mb-2">
              {{ plan.changes_email ? $t('orgEmailFlow.confirm') : $t('orgEmailFlow.confirmSame') }}
            </va-button>
          </va-card-actions>
        </form>
      </template>
    </va-modal>

    <va-modal v-model="show_undo" no-outside-dismiss size="small">
      <template #content="{ ok }">
        <form @submit.prevent="undo">
          <va-card-title>{{ $t('orgEmailFlow.undo') }}</va-card-title>
          <va-card-content>
            <p class="mb-3">{{ $t('orgEmailFlow.undoWarning') }}</p>
            <va-input v-model="undo_form.current_password"
              id="undoPassword"
              type="password"
              required-mark
              :label="$t('auth.currentPassword')"
              :error="!!page.props.errors.current_password"
              :error-messages="page.props.errors.current_password"
            />
          </va-card-content>
          <va-card-actions align="right">
            <va-button color="textInverted" @click="ok">{{ $t('common.cancel') }}</va-button>
            <va-button type="submit" id="confirmUndo" :disabled="undo_form.processing" class="mr-2 mb-2">{{ $t('orgEmailFlow.undo') }}</va-button>
          </va-card-actions>
        </form>
      </template>
    </va-modal>
  </va-card>
</template>
