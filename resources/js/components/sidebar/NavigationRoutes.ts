
export interface INavigationRoute {
  name: string
  displayName: string
  meta: { icon: string }
  children?: INavigationRoute[]
}

export default {
  root: {
    name: '/',
    displayName: 'navigationRoutes.home',
  },
  routes: [
    {
      name: 'home',
      displayName: 'Dashboard',
      meta: {
        icon: 'vuestic-iconset-dashboard',
      },
    },
    {
      name: 'apps.index',
      displayName: 'Apps',
      meta: {
        icon: 'vuestic-iconset-components',
      },
    },
    {
      name: 'users.index',
      displayName: 'Users',
      meta: {
        icon: 'vuestic-iconset-user',
      },
    },
    {
      name: 'groups.index',
      displayName: 'Groups',
      meta: {
        icon: 'entypo-users',
      },
    },
    {
      name: 'settings.organization',
      displayName: 'Settings',
      meta: {
        icon: 'vuestic-iconset-statistics',
      },
      disabled: true,
      children: [
        {
          name: 'settings.organization',
          displayName: 'Organization',
        },
        {
          name: 'settings.web',
          displayName: 'Web Domains',
        },
        {
          name: 'settings.email.setup',
          displayName: 'Email Accounts',
        },
      ],
    },
    {
      name: 'organization.subscription.summary',
      displayName: 'Billing',
      meta: {
        icon: 'vuestic-iconset-forms',
      },
      disabled: true,
      children: [
        {
          name: 'organization.subscription.summary',
          displayName: 'Summary',
        },
        {
          name: 'organization.subscription.plans',
          displayName: 'Billing History',
        },
        {
          name: 'organization.subscription.plans',
          displayName: 'Subscription',
        },
        {
          name: 'organization.payment_method.edit',
          displayName: 'Payment Method',
        },
      ],
    },
  ] as INavigationRoute[],
}
