<script setup lang="ts">
// import { useI18n } from 'vue-i18n'
import { Link, useForm } from '@inertiajs/vue3'
import { useColors } from 'vuestic-ui'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const { colors } = useColors()
</script>

<template>
  <div class="profile-dropdown-wrapper">
    <va-dropdown v-model="isShown" class="profile-dropdown" stick-to-edges placement="bottom" :offset="[13, 0]">
      <template #anchor>
        <span class="profile-dropdown__anchor">
          <slot />
          <va-icon class="px-2" :name="isShown ? 'angle_up' : 'angle_down'" :color="colors.primary" />
        </span>
      </template>
      <va-dropdown-content class="profile-dropdown__content">
        <va-list-item>
          <Link href="/profile" class="profile-dropdown__item">
            <div class="profile-item">
              {{ t('user.profile') }}
            </div>
          </Link>
        </va-list-item>
        <va-list-item v-if="$page.props.auth.can.admin && !isAdmin">
          <a href="/admin/organizations" class="profile-dropdown__item">
            <div class="profile-item">
              System Admin
            </div>
          </a>
        </va-list-item>
        <va-list-item v-if="$page.props.auth.can.admin && isAdmin">
          <a href="/" class="profile-dropdown__item">
            <div class="profile-item">
              Your Dashboard
            </div>
          </a>
        </va-list-item>
        <va-list-item>
          <Link href="" class="profile-dropdown__item" @click.prevent="logout.post('/logout')">
            <div class="profile-item">
              {{ t('user.logout') }}
            </div>
          </Link>
        </va-list-item>
      </va-dropdown-content>
    </va-dropdown>
  </div>
</template>

<script lang="ts">

export default {
  props: {
    auth: Object
  },
  data () {
    const path = (new URL(window.location.href)).pathname.split('/')
    const admin = path[1]
    return {
      isAdmin: admin === 'admin',
      isShown: false,
      logout: useForm({})
    }
  }
}
</script>

<style lang="scss" scoped>
  .profile-dropdown {
    cursor: pointer;

    &__anchor {
      display: inline-block;
    }

    &__item {
      display: block;
      color: var(--va-gray);

      &:hover,
      &:active {
        color: var(--va-primary);
      }
    }
  }

  div.profile-item {
    min-height: 30px;
    display: flex;
    justify-content: left;
    align-items: center;
  }
</style>
