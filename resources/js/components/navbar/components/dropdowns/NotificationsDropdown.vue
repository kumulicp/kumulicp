<script setup>
import { useForm } from '@inertiajs/vue3'
import axios from 'axios'
</script>
<template>
  <va-dropdown
    :placement="getPlacement"
    :close-on-content-click="false"
    :close-on-anchor-click="true"
    >
    <template #anchor>
      <div><va-icon :name="getBellIcon()" class="clickable-icon" :color="getBellColor" /></div>
    </template>

    <va-dropdown-content>
      <va-list v-if="countNotifications > 0" style="width: 400px">
        <va-list-item
          v-for="(notification, index) in notifications"
          :key="index"
        >
          <va-list-item-section icon>
            <va-icon
              :name="notification.icon"
              :color="getStatusColor(notification)"
              :title="notification.status"
            />
          </va-list-item-section>

          <va-list-item-section>
            <va-list-item-label>
              <div v-if="notification.title">{{ notification.title }}</div>
              <div v-else>{{ notification.description }}</div>
            </va-list-item-label>

            <va-list-item-label caption lines=3>
              <div v-if="notification.title">{{ notification.description }}</div>
              <div v-else>{{ notification.status }}</div>

            </va-list-item-label>
          </va-list-item-section>

          <va-list-item-section icon @click="deleteNotification(notification.id, index)">
            <va-icon
              v-if="notification.status == 'Complete'"
              name="fa-close"
              color="danger"
              class="clickable-icon"
              />
          </va-list-item-section>
        </va-list-item>
        <va-list-separator class="mt-2 fit" />
        <va-list-item>
          <va-list-item-section>
            <div class="row justify-center mt-2 px-4">
              <va-button
                class="w-full"
                color="backgroundSecondary"
                :round="false"
                @click="clearAll()"
              >
                Clear all
              </va-button>
            </div>
          </va-list-item-section>
        </va-list-item>
      </va-list>
      <p v-else-if="countNotifications == 0" class="va-text-center pv-3">
        No notifications
      </p>
    </va-dropdown-content>
  </va-dropdown>
</template>
<script>
export default {
  data () {
    return {
      notifications: {},
      warnings: false,
      unread: false,
      form: useForm({}),
      windowWidth: window.innerWidth,
      interval: '',
      num: 1
    }
  },
  computed: {
    countNotifications () {
      return Object.keys(this.notifications).length
    },
    getPlacement () {
      const placement = (this.windowWidth <= 640) ? 'bottom-center' : 'bottom-right'
      return placement
    },
    getBellColor () {
      if (this.warnings) {
        return 'danger'
      }
      if (this.countNotifications > 0) {
        return 'secondary'
      }

      return ''
    }
  },
  mounted () {
    window.addEventListener('resize', () => {
      this.windowWidth = window.innerWidth
    })
    // Request updated notifications json every 15s
    this.interval = setInterval(this.updateNotifications, 15000)
    this.updateNotifications()
  },
  unmounted () {
    clearInterval(this.interval)
  },
  methods: {
    getStatusIcon (notification) {
      switch (notification.status) {
        case 'Complete':
          return 'fa-check'
        case 'In Progress':
          return 'fa-arrow-right'
        case 'Failed':
          return 'fa-times-circle'
        default:
          return 'fa-question'
      }
    },
    getStatusColor (notification) {
      switch (notification.status) {
        case 'Complete':
          return 'success'
        case 'In Progress':
          return 'primary'
        case 'Failed':
          return 'danger'
        default:
          return 'primary'
      }
    },
    getBellIcon () {
      if (this.unread) {
        return 'notifications_active'
      }

      return 'notifications'
    },
    updateNotifications () {
      const vueState = this
      axios.get('/notifications', {
        headers: {
          'Content-Type': 'application/json'
        },
        data: {}
      })
        .then((response) => {
          vueState.parseNotifications(response.data)
        }).catch(() => {
          vueState.warning = true
        })
    },
    parseNotifications (notifications) {
      const parsedNotifications = []
      this.warnings = false
      this.unread = false
      for (const [key, notification] of Object.entries(notifications)) {
        notification.icon = this.getStatusIcon(notification)
        parsedNotifications[key] = notification
        if (notification.status === 'Failed') {
          this.warnings = true
        }
        if (notification.unread === true) {
          this.unread = true
        }
      }

      this.notifications = parsedNotifications
    },
    deleteNotification (id, index) {
      const vueState = this
      axios.delete('/notifications', {
        data: {
          notifications: [id]
        }
      })
        .then(() => {
          vueState.notifications.splice(index, 1)
        }).catch(() => {
        })
    },
    clearAll () {
      const vueState = this

      const notifications = []
      for (const notification of Object.entries(this.notifications)) {
        notifications.push(notification.id)
      }

      axios.delete('/notifications', {
        data: {
          notifications
        }
      })
        .then(() => {
          vueState.updateNotifications()
        }).catch(() => {
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
    color: var(--va-primary);
  }
}
</style>
