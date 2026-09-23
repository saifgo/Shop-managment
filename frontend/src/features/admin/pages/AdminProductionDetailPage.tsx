import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { ConfirmAction } from '@/components/ConfirmAction'
import { PageHeader } from '@/components/PageHeader'
import { QueryState } from '@/components/QueryState'
import { ResponsiveTable } from '@/components/ResponsiveTable'
import { StatusBadge } from '@/components/StatusBadge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card'
import { Field, FieldDescription, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select'
import { Spinner } from '@/components/ui/spinner'
import { Textarea } from '@/components/ui/textarea'
import { productionApi, type ProductionStage } from '@/lib/api/production'

export function AdminProductionDetailPage() {
  const { id = '' } = useParams()
  const queryClient = useQueryClient()
  const [activeStage, setActiveStage] = useState<ProductionStage | null>(null)
  const [acceptedOutput, setAcceptedOutput] = useState('')
  const [lossQuantity, setLossQuantity] = useState('0.0000')
  const [lossReasonCode, setLossReasonCode] = useState('QUALITY_REJECT')
  const [notes, setNotes] = useState('')

  const { data, isLoading, error } = useQuery({
    queryKey: ['admin', 'production', id],
    queryFn: () => productionApi.get(id),
    enabled: Boolean(id),
  })

  const invalidate = () => void queryClient.invalidateQueries({ queryKey: ['admin', 'production', id] })

  const startMutation = useMutation({ mutationFn: () => productionApi.start(id), onSuccess: invalidate })
  const pauseMutation = useMutation({ mutationFn: () => productionApi.pause(id), onSuccess: invalidate })
  const cancelMutation = useMutation({ mutationFn: () => productionApi.cancel(id), onSuccess: invalidate })
  const startStageMutation = useMutation({
    mutationFn: (stageId: string) => productionApi.startStage(id, stageId),
    onSuccess: invalidate,
  })
  const completeStageMutation = useMutation({
    mutationFn: ({ stageId, payload }: { stageId: string; payload: Parameters<typeof productionApi.completeStage>[2] }) =>
      productionApi.completeStage(id, stageId, payload),
    onSuccess: () => {
      setActiveStage(null)
      invalidate()
    },
  })

  const item = data?.items[0]

  return (
    <section className="flex flex-col gap-6">
      <Link to="/admin/production" className="text-sm text-muted-foreground">
        Production
      </Link>

      <QueryState
        isLoading={isLoading}
        error={error || (!isLoading && !data) ? 'Unable to load production order.' : null}
      >
        {data ? (
          <>
            <PageHeader
              title={data.reference}
              description={`${item?.product_name ?? ''} — ${item?.variant_name ?? ''} (${item?.sku ?? ''})`}
              action={
                <>
                  <StatusBadge status={data.status} />
                  {data.can_start ? (
                    <Button disabled={startMutation.isPending} onClick={() => startMutation.mutate()}>
                      {startMutation.isPending ? <Spinner data-icon="inline-start" /> : null}
                      Start production
                    </Button>
                  ) : null}
                  {data.can_pause ? (
                    <Button variant="outline" disabled={pauseMutation.isPending} onClick={() => pauseMutation.mutate()}>
                      {pauseMutation.isPending ? <Spinner data-icon="inline-start" /> : null}
                      Pause
                    </Button>
                  ) : null}
                  {data.can_cancel ? (
                    <ConfirmAction
                      variant="destructive"
                      title="Cancel this production order?"
                      description="This cannot be undone. The production order will be cancelled."
                      confirmLabel="Cancel production"
                      cancelLabel="Keep production"
                      trigger={<Button variant="destructive">Cancel</Button>}
                      onConfirm={async () => {
                        await cancelMutation.mutateAsync()
                      }}
                    />
                  ) : null}
                </>
              }
            />

            <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
              <div className="flex flex-col gap-1">
                <p className="text-sm text-muted-foreground">Status</p>
                <StatusBadge status={data.status} />
              </div>
              <div className="flex flex-col gap-1">
                <p className="text-sm text-muted-foreground">Priority</p>
                <p className="tabular-nums">{data.priority}</p>
              </div>
              <div className="flex flex-col gap-1">
                <p className="text-sm text-muted-foreground">Planned quantity</p>
                <p className="tabular-nums">{item?.planned_quantity}</p>
              </div>
              <div className="flex flex-col gap-1">
                <p className="text-sm text-muted-foreground">Accepted output</p>
                <p className="tabular-nums">{item?.accepted_output_quantity}</p>
              </div>
            </div>

            <ResponsiveTable
              wide
              data={data.stages}
              getRowKey={(stage) => stage.execution_id}
              columns={[
                {
                  key: 'sequence',
                  header: '#',
                  cell: (stage) => <span className="tabular-nums">{stage.sequence}</span>,
                },
                { key: 'name', header: 'Stage', primary: true, cell: (stage) => stage.name },
                {
                  key: 'status',
                  header: 'Status',
                  cell: (stage) => <StatusBadge status={stage.status} />,
                },
                {
                  key: 'input',
                  header: 'Input',
                  cell: (stage) => <span className="tabular-nums">{stage.input_quantity}</span>,
                },
                {
                  key: 'accepted',
                  header: 'Accepted',
                  cell: (stage) => <span className="tabular-nums">{stage.accepted_output_quantity}</span>,
                },
                {
                  key: 'loss',
                  header: 'Loss',
                  cell: (stage) => <span className="tabular-nums">{stage.loss_quantity}</span>,
                },
              ]}
              rowAction={(stage) => (
                <div className="flex justify-end gap-2">
                  {stage.status === 'PENDING' && data.status === 'IN_PROGRESS' ? (
                    <Button
                      variant="outline"
                      size="sm"
                      disabled={startStageMutation.isPending}
                      onClick={() => startStageMutation.mutate(stage.id)}
                    >
                      {startStageMutation.isPending ? <Spinner data-icon="inline-start" /> : null}
                      Start
                    </Button>
                  ) : null}
                  {stage.status === 'IN_PROGRESS' ? (
                    <Button
                      size="sm"
                      onClick={() => {
                        setActiveStage(stage)
                        setAcceptedOutput(stage.input_quantity)
                        setLossQuantity('0.0000')
                        setNotes('')
                      }}
                    >
                      Complete
                    </Button>
                  ) : null}
                </div>
              )}
            />

            {activeStage ? (
              <Card>
                <CardHeader>
                  <CardTitle>Complete {activeStage.name}</CardTitle>
                  <CardDescription>
                    Input: {activeStage.input_quantity} ({activeStage.reconciliation_mode} reconciliation)
                  </CardDescription>
                </CardHeader>
                <CardContent>
                  <form
                    id="complete-stage-form"
                    className="flex flex-col gap-5"
                    onSubmit={(event) => {
                      event.preventDefault()
                      const loss = lossQuantity || '0.0000'
                      const losses =
                        parseFloat(loss) > 0 ? [{ reason_code: lossReasonCode, quantity: loss }] : undefined

                      completeStageMutation.mutate({
                        stageId: activeStage.id,
                        payload: {
                          accepted_output_quantity: acceptedOutput,
                          loss_quantity: loss,
                          notes: notes || undefined,
                          losses,
                        },
                      })
                    }}
                  >
                    <FieldGroup>
                      <Field>
                        <FieldLabel htmlFor="accepted-output">Accepted output</FieldLabel>
                        <Input
                          id="accepted-output"
                          value={acceptedOutput}
                          onChange={(e) => setAcceptedOutput(e.target.value)}
                          required
                        />
                      </Field>
                      <Field>
                        <FieldLabel htmlFor="loss-quantity">Loss quantity</FieldLabel>
                        <Input
                          id="loss-quantity"
                          value={lossQuantity}
                          onChange={(e) => setLossQuantity(e.target.value)}
                        />
                      </Field>
                      <Field>
                        <FieldLabel htmlFor="loss-reason">Loss reason</FieldLabel>
                        <NativeSelect
                          id="loss-reason"
                          className="w-full"
                          value={lossReasonCode}
                          onChange={(e) => setLossReasonCode(e.target.value)}
                        >
                          <NativeSelectOption value="BROKEN">Broken / unusable</NativeSelectOption>
                          <NativeSelectOption value="CRACKS">Cracks</NativeSelectOption>
                          <NativeSelectOption value="KILN_DAMAGE">Kiln damage</NativeSelectOption>
                          <NativeSelectOption value="DECORATION_REJECT">Decoration reject</NativeSelectOption>
                          <NativeSelectOption value="FIRING_DAMAGE">Firing damage</NativeSelectOption>
                          <NativeSelectOption value="QUALITY_REJECT">Quality reject</NativeSelectOption>
                          <NativeSelectOption value="OTHER">Other</NativeSelectOption>
                        </NativeSelect>
                      </Field>
                      <Field>
                        <FieldLabel htmlFor="stage-notes">Notes</FieldLabel>
                        <Textarea id="stage-notes" value={notes} onChange={(e) => setNotes(e.target.value)} rows={3} />
                        <FieldDescription>Optional notes for this stage completion.</FieldDescription>
                      </Field>
                    </FieldGroup>
                  </form>
                </CardContent>
                <CardFooter className="flex gap-2">
                  <Button type="submit" form="complete-stage-form" disabled={completeStageMutation.isPending}>
                    {completeStageMutation.isPending ? <Spinner data-icon="inline-start" /> : null}
                    Record completion
                  </Button>
                  <Button type="button" variant="outline" onClick={() => setActiveStage(null)}>
                    Cancel
                  </Button>
                </CardFooter>
              </Card>
            ) : null}
          </>
        ) : null}
      </QueryState>
    </section>
  )
}
