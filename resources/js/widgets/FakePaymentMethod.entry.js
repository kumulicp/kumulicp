import { createApp } from 'vue'
import FakePaymentMethod from './FakePaymentMethod.vue'
import { createVuestic } from 'vuestic-ui'
import vuesticGlobalConfig from '../services/global-config'
import i18n from '../i18n'

export function mount (el, { locale, ...props } = {}) {
  // Widgets are separate bundles, so they need their own i18n instance and the host app's locale
  if (locale) {
    i18n.global.locale.value = locale
  }

  return createApp(FakePaymentMethod, props)
    .use(i18n)
    .use(createVuestic({ config: vuesticGlobalConfig }))
    .mount(el)
}
