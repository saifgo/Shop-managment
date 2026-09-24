import { useQuery } from '@tanstack/react-query'
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select'
import { customersApi } from '@/lib/api/customers'

interface CustomerSelectProps {
  id: string
  value: string
  onChange: (customerId: string) => void
  disabled?: boolean
}

export function CustomerSelect({ id, value, onChange, disabled }: CustomerSelectProps) {
  const { data, isLoading, error } = useQuery({
    queryKey: ['admin', 'customers', 'select'],
    queryFn: () => customersApi.list({ per_page: 100 }),
  })

  const customers = (data?.items ?? []).filter((customer) => customer.is_active || customer.id === value)

  return (
    <NativeSelect
      id={id}
      className="w-full"
      value={value}
      disabled={disabled || isLoading}
      aria-invalid={error ? true : undefined}
      onChange={(event) => onChange(event.target.value)}
    >
      <NativeSelectOption value="">
        {isLoading ? 'Loading customers…' : error ? 'Unable to load customers' : 'Select a customer'}
      </NativeSelectOption>
      {customers.map((customer) => (
        <NativeSelectOption key={customer.id} value={customer.id}>
          {customer.display_name}
          {customer.legal_name && customer.legal_name !== customer.display_name ? ` (${customer.legal_name})` : ''}
        </NativeSelectOption>
      ))}
    </NativeSelect>
  )
}
