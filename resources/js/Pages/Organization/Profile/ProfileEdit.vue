<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import PasswordChecker from '@/components/FormInputs/PasswordChecker.vue'
import { useForm } from '@inertiajs/vue3'
import { ref } from 'vue'
import { useInputMask, createRegexMask } from 'vuestic-ui'


const phoneNumber = ref()
useInputMask(createRegexMask(/(\+\d \(\d{3}\)|\d{3}) (\d){3}-(\d){4}/), phoneNumber)
</script>
<template>
  <Head>
    <title>{{ $t('organization.profile.profile') }} - Control Panel</title>
  </Head>
  <va-card class="mb-4">
    <va-card-title>{{ $t('organization.profile.editProfile') }}</va-card-title>
    <va-card-content>
      <form @submit.prevent="form.post('/profile')">
        <va-list>
          <va-list-item class="pb-3">
            <va-list-item-section label>
              <va-list-item-label>
                <h5>{{ $t('auth.username') }}:</h5>
              </va-list-item-label>
            </va-list-item-section>
            <va-list-item-section>
              <va-list-item-label>
                {{ form.id }}
              </va-list-item-label>
            </va-list-item-section>
          </va-list-item>

          <va-list-separator class="my-1" fit />
          <va-list-item class="pb-3">
            <va-list-item-section label>
              <va-list-item-label>
                <h5>{{ $t('organization.users.firstName') }}:</h5>
              </va-list-item-label>
            </va-list-item-section>
            <va-list-item-section>
              <va-list-item-label>
                <va-input v-model="form.first_name"
                  id="firstName"
                  immediateValidation
                  required-mark
                  :error="$page.props.errors.first_name"
                  :error-messages="$page.props.errors.first_name"
                  />
              </va-list-item-label>
            </va-list-item-section>
          </va-list-item>

          <va-list-separator class="my-1" fit />
          <va-list-item class="pb-3">
            <va-list-item-section label>
              <va-list-item-label>
                <h5>{{ $t('organization.users.lastName') }}:</h5>
              </va-list-item-label>
            </va-list-item-section>
            <va-list-item-section>
              <va-list-item-label>
                <va-input v-model="form.last_name"
                  id="lastName"
                  immediateValidation
                  required-mark
                  :error="$page.props.errors.last_name"
                  :error-messages="$page.props.errors.last_name"
                  />
              </va-list-item-label>
            </va-list-item-section>
          </va-list-item>

          <va-list-separator class="my-1" fit />
          <va-list-item class="pb-3">
            <va-list-item-section label>
              <va-list-item-label>
                <h5>{{ $t('organization.users.phoneNumber') }}:</h5>
              </va-list-item-label>
            </va-list-item-section>
            <va-list-item-section>
              <va-list-item-label>
                <va-input v-model="form.phone_number"
                  id="phoneNumber"
                  immediateValidation
                  placeholder="### ### ####"
                  ref="phoneNumber"
                  :error="$page.props.errors.phone_number"
                  :error-messages="$page.props.errors.phone_number"
                  />
              </va-list-item-label>
            </va-list-item-section>
          </va-list-item>

          <va-list-separator class="my-1" fit />
          <va-list-item class="pb-3">
            <va-list-item-section label>
              <va-list-item-label>
                <h5>{{ $t('organization.users.personalEmail') }}:</h5>
              </va-list-item-label>
            </va-list-item-section>
            <va-list-item-section>
              <va-list-item-label>
                <va-input v-model="form.personal_email"
                  id="personalEmail"
                  immediateValidation
                  required-mark
                  :error="$page.props.errors.personal_email"
                  :error-messages="$page.props.errors.personal_email"
                  />
              </va-list-item-label>
            </va-list-item-section>
          </va-list-item>

          <template v-if="profile.org_emails">
            <va-list-separator class="my-1" fit />
            <va-list-item class="pb-3">
              <va-list-item-section label>
                <va-list-item-label>
                  <h5>{{ $t('organization.users.organizationalEmail') }}:</h5>
                </va-list-item-label>
              </va-list-item-section>
              <va-list-item-section>
                <template v-for="(org_email, index) in user.org_emails" :key="index">
                  {{ org_email }}
                  <va-list-separator class="my-1" fit />
                </template>
              </va-list-item-section>
            </va-list-item>
          </template>
          <va-list-separator class="my-1" fit />
          <va-list-item class="pb-3">
            <va-list-item-section label>
              <va-list-item-label>
                <h5>{{ $t('auth.password') }}:</h5>
              </va-list-item-label>
            </va-list-item-section>
            <va-list-item-section>
              <va-button color="danger" immediateValidation id="changePassword" @click="showChangePasswordModal = !showChangePasswordModal">
                {{ $t('auth.changePassword') }}
              </va-button>
              <va-modal
                v-model="showChangePasswordModal"
                no-outside-dismiss
                size="small"
              >
                <template #content="{ ok }">
                  <form @submit.prevent="password_form.post('/profile/update/passwd', {
                    onFinish: () => password_form.reset(),
                    onSuccess: () => { password_form.reset(); showChangePasswordModal = !showChangePasswordModal }
                  })">
                    <va-card-title>{{ $t('auth.changePassword') }}</va-card-title>
                    <va-card-content>
                      <va-input v-model="password_form.current_password"
                        id="currentPassword"
                        type="password"
                        required-mark
                        immediateValidation
                        :label="$t('auth.currentPassword')"
                        class="mb-3"
                        :error="$page.props.errors.current_password"
                        :error-messages="$page.props.errors.current_password"
                      />
                      <va-input v-model="password_form.password"
                        id="password"
                        type="password"
                        required-mark
                        immediateValidation
                        :label="$t('auth.newPassword')"
                        class="mb-3"
                        :error="$page.props.errors.password"
                        :error-messages="$page.props.errors.password"
                      />
                      <va-input v-model="password_form.password_confirmation"
                        id="passwordConfirmation"
                        type="password"
                        required-mark
                        immediateValidation
                        :label="$t('auth.confirmNewPassword')"
                        class="mb-3"
                        :error="$page.props.errors.password_confirmation"
                        :error-messages="$page.props.errors.password_confirmation"
                      />
                      <password-checker :password="password_form.password" :passwordConfirmation="password_form.password_confirmation" />
                    </va-card-content>
                    <va-card-actions align="right">
                      <va-button color="textInverted" @click="ok">
                        {{ $t('modal.cancel') }}
                      </va-button>
                      <va-button type="submit" id="updatePassword" :disabled="password_form.processing" class="mr-2 mb-2">
                        {{ $t('auth.changePassword') }}
                      </va-button>
                    </va-card-actions>
                  </form>
                </template>
              </va-modal>
            </va-list-item-section>
          </va-list-item>
        </va-list>

        <va-button type="submit" id="submit" class="mr-2 mb-2" :disabled="form.processing">{{ $t('form.submit') }}</va-button>
      </form>
    </va-card-content>
  </va-card>
</template>

<script lang="ts">
export default {
  layout: (h, page) => h(AppLayout, [page]),
  props: {
    profile: Object,
    errors: Object
  },
  data () {
    return {
      showChangePasswordModal: false,
      password_form: useForm({
        current_password: '',
        password: '',
        password_confirmation: ''
      }),
      form: useForm({
        id: this.profile.id,
        first_name: this.profile.first_name,
        last_name: this.profile.last_name,
        phone_number: this.profile.phone_number,
        personal_email: this.profile.personal_email
      })
    }
  }
}
</script>

<style></style>
