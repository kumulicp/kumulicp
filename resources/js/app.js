import '../css/app.css'

import { createApp, h } from 'vue'
import { createInertiaApp } from '@inertiajs/vue3'
import { ZiggyVue } from '../../vendor/tightenco/ziggy'
import vuesticGlobalConfig from './services/global-config'
import i18n from './i18n'
import { createVuestic } from 'vuestic-ui'
import stores from './stores'
import 'vuestic-ui/styles/essential.css'
import 'vuestic-ui/styles/typography.css'
import AppLayout from '@/layouts/AppLayout.vue'

createInertiaApp({
  resolve: (name) => {
    const [module, pageName] = name.split('::')
    if (module && pageName) {
      // Import from module directory

      const pages = import.meta.glob('../../modules/**/Resources/js/Pages/**/*.vue', { eager: true })
      // const page = pages[`./Pages/${name}.vue`]
      let page = pages[`../../modules/${module}/Resources/js/Pages/${pageName}.vue`]
      if (page) {
        page.default.layout = page.default.layout || AppLayout

        return page
      }
    } else {
      const pages = import.meta.glob('./Pages/**/*.vue', { eager: true })
      let page = pages[`./Pages/${name}.vue`]
      if (page) {
        page.default.layout = page.default.layout || AppLayout

        return page
      }
    }
    return null
  },
  progress: {
    color: '#4B5563'
  },
  setup ({ el, App, props, plugin }) {
    const VueApp = createApp({ render: () => h(App, props) })

    VueApp.use(plugin)
      .use(i18n)
      .use(stores)
      .use(ZiggyVue)
      .use(createVuestic({ config: vuesticGlobalConfig }))
      .mount(el)

    return VueApp
  }
})
