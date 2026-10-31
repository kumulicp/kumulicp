export interface Currency {
  code: string
  label: string
  symbol: string
}

export const CURRENCIES: Currency[] = [
  { code: 'USD', label: 'US Dollar', symbol: '$' },
  { code: 'EUR', label: 'Euro', symbol: '€' },
  { code: 'GBP', label: 'British Pound', symbol: '£' },
  { code: 'CAD', label: 'Canadian Dollar', symbol: 'CA$' },
  { code: 'AUD', label: 'Australian Dollar', symbol: 'A$' },
  { code: 'CHF', label: 'Swiss Franc', symbol: 'CHF' },
  { code: 'JPY', label: 'Japanese Yen', symbol: '¥' },
  { code: 'NZD', label: 'New Zealand Dollar', symbol: 'NZ$' },
  { code: 'SEK', label: 'Swedish Krona', symbol: 'kr' },
  { code: 'NOK', label: 'Norwegian Krone', symbol: 'kr' },
  { code: 'DKK', label: 'Danish Krone', symbol: 'kr' },
  { code: 'MXN', label: 'Mexican Peso', symbol: 'MX$' },
  { code: 'BRL', label: 'Brazilian Real', symbol: 'R$' },
  { code: 'SGD', label: 'Singapore Dollar', symbol: 'S$' },
  { code: 'HKD', label: 'Hong Kong Dollar', symbol: 'HK$' },
  { code: 'INR', label: 'Indian Rupee', symbol: '₹' },
]

export function currencyByCode(code: string): Currency | undefined {
  return CURRENCIES.find(c => c.code === code)
}

export interface ComponentPrice {
  amount: number | string | null
  price_id: string
}

/**
 * The per-currency price of a plan component ("base", "standard", "basic",
 * "storage"), falling back to the legacy flat `price`/`price_id` keys that
 * plans saved before multi-currency support still carry. The legacy price
 * only ever applied to the first enabled currency.
 */
export function componentPrice(settings: any, component: string, currency: string, isFirstCurrency = true): ComponentPrice {
  const config = settings?.[component]
  const perCurrency = config?.prices?.[currency]

  return {
    amount: perCurrency?.amount ?? (isFirstCurrency ? (config?.price ?? null) : null),
    price_id: perCurrency?.price_id ?? (isFirstCurrency ? (config?.price_id ?? '') : '')
  }
}

/** Builds the `prices` form payload for every enabled currency of a component. */
export function buildCurrencyPrices(settings: any, component: string, currencies: string[]): Record<string, ComponentPrice> {
  const result: Record<string, ComponentPrice> = {}
  currencies.forEach((currency, index) => {
    const price = componentPrice(settings, component, currency, index === 0)
    result[currency] = { amount: price.amount, price_id: price.price_id }
  })

  return result
}

/** "$5, CA$7" style summary of a component's prices, for read-only views. */
export function formatComponentPrices(settings: any, component: string, currencies: string[]): string {
  return currencies
    .map((currency, index) => {
      const amount = componentPrice(settings, component, currency, index === 0).amount
      if (amount === null || amount === undefined || amount === '') {
        return null
      }
      return `${currencyByCode(currency)?.symbol ?? currency}${amount} ${currency}`
    })
    .filter(Boolean)
    .join(' / ')
}

/** "USD: prod_1 / CAD: prod_2" style summary of a component's Stripe product IDs. */
export function formatComponentPriceIds(settings: any, component: string, currencies: string[]): string {
  return currencies
    .map((currency, index) => {
      const priceId = componentPrice(settings, component, currency, index === 0).price_id
      return priceId ? `${currency}: ${priceId}` : null
    })
    .filter(Boolean)
    .join(' / ')
}
