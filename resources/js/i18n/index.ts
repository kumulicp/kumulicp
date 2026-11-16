import { createI18n } from 'vue-i18n'

const fileNameToLocaleModuleDict = import.meta.glob('./locales/*.json', {
    eager: true,
  })

const messages: { [P: string]: Record<string, string> } = {}
Object.entries(fileNameToLocaleModuleDict)
  .map(([fileName, localeModule]) => {
    const fileNameParts = fileName.split('/')
    const fileNameWithoutPath = fileNameParts[fileNameParts.length - 1]
    const localeName = fileNameWithoutPath.split('.json')[0]

    return [localeName, localeModule.default]
  })
  .forEach((localeNameLocaleMessagesTuple) => {
    messages[localeNameLocaleMessagesTuple[0]] = localeNameLocaleMessagesTuple[1]
  })

const i18n = createI18n({
  legacy: false,
  locale: 'en',
  fallbackLocale: 'en',
  messages,
})

// What a single-organization install calls things differently, laid over the normal strings:
// an app plan is a "profile" there, since nobody subscribes to it
const singleOrganizationFiles = import.meta.glob('./single/*.json', {
  eager: true,
}) as Record<string, { default: Record<string, unknown> }>

export function useSingleOrganizationTerms (): void {
  Object.entries(singleOrganizationFiles).forEach(([fileName, module]) => {
    const locale = fileName.split('/').pop()!.replace('.json', '')
    i18n.global.mergeLocaleMessage(locale, module.default)
  })
}

export default i18n
