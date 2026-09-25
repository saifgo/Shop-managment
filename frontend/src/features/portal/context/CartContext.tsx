import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { useAuth } from '@/features/auth/hooks/useAuth'

export interface CartItem {
  variantId: string
  productId: string
  productName: string
  variantName: string
  sku: string
  quantity: string
  imageUrl?: string | null
}

interface CartContextValue {
  items: CartItem[]
  itemCount: number
  addItem: (item: Omit<CartItem, 'quantity'>, quantity?: string) => void
  updateQuantity: (variantId: string, quantity: string) => void
  removeItem: (variantId: string) => void
  clear: () => void
}

const CartContext = createContext<CartContextValue | null>(null)

const STORAGE_PREFIX = 'tittawin.portal.cart'

/** One cart per signed-in account, so a shared computer never shows someone else's cart. */
function storageKey(userId: string | undefined): string | null {
  return userId ? `${STORAGE_PREFIX}.${userId}` : null
}

function readStorage(key: string | null): CartItem[] {
  if (!key) return []
  try {
    const raw = localStorage.getItem(key)
    return raw ? (JSON.parse(raw) as CartItem[]) : []
  } catch {
    return []
  }
}

function writeStorage(key: string | null, items: CartItem[]) {
  if (!key) return
  try {
    localStorage.setItem(key, JSON.stringify(items))
  } catch {
    // Storage full or blocked: the cart still works for this page view.
  }
}

export function CartProvider({ children }: { children: ReactNode }) {
  const { user } = useAuth()
  const key = storageKey(user?.id)
  const [state, setState] = useState(() => ({ key, items: readStorage(key) }))

  // Signing in as someone else swaps to that account's cart (state adjusted during render,
  // so the rest of the app is not remounted).
  if (state.key !== key) {
    setState({ key, items: readStorage(key) })
  }

  const items = state.key === key ? state.items : []

  // The pre-account-scoped cart key would otherwise linger forever.
  useEffect(() => {
    try {
      localStorage.removeItem(STORAGE_PREFIX)
    } catch {
      // ignore
    }
  }, [])

  const persist = useCallback(
    (update: (current: CartItem[]) => CartItem[]) => {
      setState((current) => {
        const next = update(current.key === key ? current.items : readStorage(key))
        writeStorage(key, next)
        return { key, items: next }
      })
    },
    [key],
  )

  const value = useMemo<CartContextValue>(
    () => ({
      items,
      itemCount: items.reduce((sum, item) => sum + Number(item.quantity), 0),
      addItem: (item, quantity = '1.0000') =>
        persist((current) => {
          const existing = current.find((entry) => entry.variantId === item.variantId)
          if (existing) {
            const nextQty = (Number(existing.quantity) + Number(quantity)).toFixed(4)
            return current.map((entry) =>
              entry.variantId === item.variantId ? { ...entry, ...item, quantity: nextQty } : entry,
            )
          }
          return [...current, { ...item, quantity }]
        }),
      updateQuantity: (variantId, quantity) =>
        persist((current) =>
          Number(quantity) <= 0
            ? current.filter((entry) => entry.variantId !== variantId)
            : current.map((entry) => (entry.variantId === variantId ? { ...entry, quantity } : entry)),
        ),
      removeItem: (variantId) => persist((current) => current.filter((entry) => entry.variantId !== variantId)),
      clear: () => persist(() => []),
    }),
    [items, persist],
  )

  return <CartContext.Provider value={value}>{children}</CartContext.Provider>
}

export function useCart() {
  const context = useContext(CartContext)

  if (!context) {
    throw new Error('useCart must be used within CartProvider')
  }

  return context
}
