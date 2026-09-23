import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { ConfirmAction } from '@/components/ConfirmAction'
import { PageHeader } from '@/components/PageHeader'
import { QueryState } from '@/components/QueryState'
import { ResponsiveTable } from '@/components/ResponsiveTable'
import { StatusBadge } from '@/components/StatusBadge'
import { Button } from '@/components/ui/button'
import { Card, CardAction, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Spinner } from '@/components/ui/spinner'
import { returnsApi } from '@/lib/api/returns'

const RESOLUTIONS = ['REPLACEMENT', 'CREDIT_NOTE', 'REFUND', 'REJECTED'] as const
const CONDITIONS = ['SELLABLE', 'DAMAGED_IN_TRANSIT', 'DAMAGED_CUSTOMER', 'DEFECTIVE', 'SCRAP'] as const

export function AdminReturnsPage() {
  const queryClient = useQueryClient()
  const [selectedId, setSelectedId] = useState<string | null>(null)

  const { data, isLoading, error } = useQuery({
    queryKey: ['admin', 'returns'],
    queryFn: () => returnsApi.list(),
  })

  const detail = useQuery({
    queryKey: ['admin', 'returns', selectedId],
    queryFn: () => returnsApi.get(selectedId!),
    enabled: Boolean(selectedId),
  })

  const approve = useMutation({
    mutationFn: (id: string) => returnsApi.approve(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'returns'] })
      queryClient.invalidateQueries({ queryKey: ['admin', 'returns', selectedId] })
    },
  })

  const receive = useMutation({
    mutationFn: (id: string) => returnsApi.receive(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'returns'] })
      queryClient.invalidateQueries({ queryKey: ['admin', 'returns', selectedId] })
    },
  })

  const inspect = useMutation({
    mutationFn: ({ id, items }: { id: string; items: Array<{ return_item_id: string; condition: string }> }) =>
      returnsApi.inspect(id, items),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'returns'] })
      queryClient.invalidateQueries({ queryKey: ['admin', 'returns', selectedId] })
    },
  })

  const resolve = useMutation({
    mutationFn: ({ id, resolution, invoiceId }: { id: string; resolution: string; invoiceId?: string }) =>
      returnsApi.resolve(id, { resolution, invoice_id: invoiceId }, crypto.randomUUID()),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'returns'] })
      queryClient.invalidateQueries({ queryKey: ['admin', 'returns', selectedId] })
    },
  })

  const selected = detail.data

  return (
    <section className="flex flex-col gap-6">
      <PageHeader title="Returns" />
      <QueryState
        isLoading={isLoading}
        error={error ? 'Failed to load returns.' : null}
        isEmpty={data?.items.length === 0}
        emptyTitle="No returns"
        emptyDescription="Return requests will appear here."
      >
        <ResponsiveTable
          data={data?.items ?? []}
          getRowKey={(item) => item.id}
          columns={[
            { key: 'reference', header: 'Reference', primary: true, cell: (item) => item.reference },
            { key: 'order', header: 'Order', cell: (item) => item.order_id.slice(-8) },
            {
              key: 'status',
              header: 'Status',
              cell: (item) => <StatusBadge status={item.status} />,
            },
            { key: 'resolution', header: 'Resolution', cell: (item) => item.resolution ?? '—' },
          ]}
          rowAction={(item) => (
            <Button variant="outline" size="sm" onClick={() => setSelectedId(item.id)}>
              Details
            </Button>
          )}
        />
      </QueryState>

      {selected ? (
        <Card>
          <CardHeader>
            <CardTitle>{selected.reference}</CardTitle>
            <CardDescription>{selected.reason ?? 'No reason provided'}</CardDescription>
            <CardAction>
              <StatusBadge status={selected.status} />
            </CardAction>
          </CardHeader>
          <CardContent className="flex flex-col gap-4">
            <ul className="flex flex-col gap-2">
              {(selected.items ?? []).map((item) => (
                <li key={item.id} className="text-sm">
                  {item.product_name} ({item.sku}) — qty{' '}
                  <span className="tabular-nums">{item.quantity}</span> — {item.condition ?? 'pending inspection'}
                </li>
              ))}
            </ul>

            <div className="flex flex-wrap gap-2">
              {selected.status === 'REQUESTED' ? (
                <Button onClick={() => approve.mutate(selected.id)} disabled={approve.isPending}>
                  {approve.isPending ? <Spinner data-icon="inline-start" /> : null}
                  Approve
                </Button>
              ) : null}
              {selected.status === 'APPROVED' ? (
                <Button onClick={() => receive.mutate(selected.id)} disabled={receive.isPending}>
                  {receive.isPending ? <Spinner data-icon="inline-start" /> : null}
                  Mark received
                </Button>
              ) : null}
              {selected.status === 'RECEIVED' ? (
                <Button
                  onClick={() => {
                    const items = (selected.items ?? []).map((item) => ({
                      return_item_id: item.id,
                      condition: CONDITIONS[0],
                    }))
                    inspect.mutate({ id: selected.id, items })
                  }}
                  disabled={inspect.isPending}
                >
                  {inspect.isPending ? <Spinner data-icon="inline-start" /> : null}
                  Inspect (default sellable)
                </Button>
              ) : null}
              {selected.status === 'INSPECTED'
                ? RESOLUTIONS.map((resolution) =>
                    resolution === 'REJECTED' ? (
                      <ConfirmAction
                        key={resolution}
                        variant="destructive"
                        title="Reject this return?"
                        description="The return will be resolved as rejected."
                        confirmLabel="Reject return"
                        trigger={
                          <Button variant="destructive" disabled={resolve.isPending}>
                            Resolve: {resolution}
                          </Button>
                        }
                        onConfirm={async () => {
                          await resolve.mutateAsync({ id: selected.id, resolution })
                        }}
                      />
                    ) : (
                      <Button
                        key={resolution}
                        variant="secondary"
                        onClick={() => resolve.mutate({ id: selected.id, resolution })}
                        disabled={resolve.isPending}
                      >
                        {resolve.isPending ? <Spinner data-icon="inline-start" /> : null}
                        Resolve: {resolution}
                      </Button>
                    ),
                  )
                : null}
            </div>

            {(selected.events ?? []).length > 0 ? (
              <div className="flex flex-col gap-2">
                <h3 className="font-heading text-sm font-medium">Event trail</h3>
                <ul className="flex flex-col gap-1">
                  {selected.events?.map((event) => (
                    <li key={event.id} className="text-sm">
                      {event.event_type} — {event.from_status ?? '—'} → {event.to_status ?? '—'} (
                      {new Date(event.created_at).toLocaleString()})
                    </li>
                  ))}
                </ul>
              </div>
            ) : null}
          </CardContent>
        </Card>
      ) : null}
    </section>
  )
}
