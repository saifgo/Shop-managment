import { toastManager } from '@/components/ui/toast'

type ToastKind = 'success' | 'error' | 'info' | 'warning'

interface ToastOptions {
  description?: string
  action?: { label: string; onClick: () => void | Promise<void> }
}

function notify(type: ToastKind, title: string, options: ToastOptions | string = {}) {
  const { description, action } = typeof options === 'string' ? { description: options } : options
  return toastManager.add({
    type,
    title,
    description,
    actionProps: action
      ? { children: action.label, onClick: () => void action.onClick() }
      : undefined,
  })
}

/** Thin typed wrapper over the coss toast manager. */
export const toast = {
  success: (title: string, options?: ToastOptions | string) => notify('success', title, options),
  error: (title: string, options?: ToastOptions | string) => notify('error', title, options),
  info: (title: string, options?: ToastOptions | string) => notify('info', title, options),
  warning: (title: string, options?: ToastOptions | string) => notify('warning', title, options),
}
