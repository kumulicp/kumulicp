// Vuestic stacks a toast below the older ones by measuring their height, but a toast is
// still hidden (height 0) in the tick it is created. Toasts created together therefore land
// on top of each other, so they are shown one after another instead.
const STACK_DELAY_MS = 60

type ToastOptions = Record<string, unknown>

interface ToastService {
  init: (options: ToastOptions) => unknown
}

const colors = {
  warning: { color: 'warning', duration: 15000 },
  success: { color: 'success', duration: 10000 },
  error: { color: 'danger', duration: 10000 }
} as const

export async function showToasts (toast: ToastService, toasts: ToastOptions[]) {
  for (const [index, options] of toasts.entries()) {
    if (index > 0) {
      await new Promise((resolve) => setTimeout(resolve, STACK_DELAY_MS))
    }
    toast.init({ customClass: 'success-toast', ...options })
  }
}

type Notices = Partial<Record<keyof typeof colors, string | null>> | undefined

// The flash messages of a page: warning, success and error, whichever are set
export function noticeToasts (notices: Notices): ToastOptions[] {
  return (Object.keys(colors) as Array<keyof typeof colors>)
    .filter((type) => notices?.[type])
    .map((type) => ({ message: notices![type], ...colors[type] }))
}

// Validation errors, one toast per field
export function errorToasts (errors: Record<string, unknown> | undefined): ToastOptions[] {
  return Object.values(errors ?? {}).map((message) => ({ message, ...colors.error }))
}
