import { createContext, useContext, useMemo, useState, type ReactNode } from 'react'

export interface CartItem {
  variantId: string
  productId: string
  productName: string
  variantName: string
  sku: string
  quantity: string
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

const STORAGE_KEY = 'tittawin.portal.cart'

function readStorage(): CartItem[] {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    return raw ? (JSON.parse(raw) as CartItem[]) : []
  } catch {
    return []
  }
}

export function CartProvider({ children }: { children: ReactNode }) {
  const [items, setItems] = useState<CartItem[]>(() => readStorage())

  const persist = (next: CartItem[]) => {
    setItems(next)
    localStorage.setItem(STORAGE_KEY, JSON.stringify(next))
  }

  const value = useMemo<CartContextValue>(() => ({
    items,
    itemCount: items.reduce((sum, item) => sum + Number(item.quantity), 0),
    addItem: (item, quantity = '1.0000') => {
      const existing = items.find((entry) => entry.variantId === item.variantId)

      if (existing) {
        const nextQty = (Number(existing.quantity) + Number(quantity)).toFixed(4)
        persist(items.map((entry) => (entry.variantId === item.variantId ? { ...entry, quantity: nextQty } : entry)))
        return
      }

      persist([...items, { ...item, quantity }])
    },
    updateQuantity: (variantId, quantity) => {
      if (Number(quantity) <= 0) {
        persist(items.filter((entry) => entry.variantId !== variantId))
        return
      }

      persist(items.map((entry) => (entry.variantId === variantId ? { ...entry, quantity } : entry)))
    },
    removeItem: (variantId) => persist(items.filter((entry) => entry.variantId !== variantId)),
    clear: () => persist([]),
  }), [items])

  return <CartContext.Provider value={value}>{children}</CartContext.Provider>
}

export function useCart() {
  const context = useContext(CartContext)

  if (!context) {
    throw new Error('useCart must be used within CartProvider')
  }

  return context
}
