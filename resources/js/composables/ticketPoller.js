import axios from 'axios'

/**
 * Keeps a page fresh without WebSockets.
 *
 * Asks the server "has anything changed since version X?" on a timer. The
 * server answers 304 when nothing has, so an idle page costs almost nothing.
 *
 * - Polls every `interval` ms while the tab is visible and `hiddenInterval`
 *   when it is hidden or the page says it is `slow()` (e.g. a closed ticket).
 * - Backs off exponentially (up to `maxInterval`) while requests fail.
 * - When something changed while the tab was hidden, the update is held back
 *   and delivered the moment the person comes back, so a background tab never
 *   shows (or marks as read) things nobody has looked at.
 * - Coming back to the tab polls straight away.
 *
 * `alwaysNotify` is for endpoints that always answer 200 (the unread badge):
 * every answer is passed to `onChange` and the version is only advanced by
 * `setVersion()`.
 */
export function createPoller ({
  url,
  version = null,
  params = () => ({}),
  onChange,
  interval = 10000,
  hiddenInterval = 60000,
  maxInterval = 120000,
  slow = () => false,
  alwaysNotify = false,
  immediate = false,
}) {
  let timer = null
  let failures = 0
  let running = false
  let current = version
  let held = null

  const visible = () => typeof document === 'undefined' || document.visibilityState === 'visible'

  function delay () {
    const base = visible() && !slow() ? interval : hiddenInterval
    return Math.min(base * 2 ** failures, maxInterval)
  }

  function schedule () {
    clearTimeout(timer)
    if (running) {
      timer = setTimeout(tick, delay())
    }
  }

  async function tick () {
    if (!running) return
    try {
      const response = await axios.get(url, {
        params: { ...params(), v: current ?? undefined },
        validateStatus: (status) => status === 200 || status === 304,
      })
      failures = 0

      if (response.status === 200) {
        if (!alwaysNotify) {
          current = response.data.version
        }
        if (visible() || alwaysNotify) {
          await onChange(response.data)
        } else {
          held = response.data
        }
      }
    } catch {
      failures += 1
    }
    schedule()
  }

  function wake () {
    if (!running || !visible()) return
    if (held) {
      const data = held
      held = null
      onChange(data)
    }
    clearTimeout(timer)
    tick()
  }

  return {
    start () {
      if (running) return
      running = true
      document.addEventListener('visibilitychange', wake)
      window.addEventListener('focus', wake)
      if (immediate) {
        tick()
      } else {
        schedule()
      }
    },
    stop () {
      running = false
      clearTimeout(timer)
      document.removeEventListener('visibilitychange', wake)
      window.removeEventListener('focus', wake)
    },
    // Poll now (e.g. after navigating, so the badge is up to date)
    refresh () {
      if (running) {
        clearTimeout(timer)
        tick()
      }
    },
    setVersion (value) {
      current = value
    },
  }
}
