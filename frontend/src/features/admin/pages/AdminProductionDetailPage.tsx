import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { toast } from 'sonner'
import { ConfirmAction } from '@/components/ConfirmAction'
import { PageHeader } from '@/components/PageHeader'
import { QueryState } from '@/components/QueryState'
import { ResponsiveTable } from '@/components/ResponsiveTable'
import { StatusBadge } from '@/components/StatusBadge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card'
import { Field, FieldDescription, FieldError, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select'
import { Spinner } from '@/components/ui/spinner'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Textarea } from '@/components/ui/textarea'
import { productionApi, type ProductionDetail, type ProductionStage } from '@/lib/api/production'
import { formatQuantity } from '@/lib/format'
import { DECIMAL, fromUnits, toUnits } from '@/lib/quantity'

const LOSS_REASONS = [
  { value: 'BROKEN', label: 'Broken / unusable' },
  { value: 'CRACKS', label: 'Cracks' },
  { value: 'KILN_DAMAGE', label: 'Kiln damage' },
  { value: 'DECORATION_REJECT', label: 'Decoration reject' },
  { value: 'FIRING_DAMAGE', label: 'Firing damage' },
  { value: 'QUALITY_REJECT', label: 'Quality reject' },
  { value: 'OTHER', label: 'Other' },
]

export function AdminProductionDetailPage() {
  const { id = '' } = useParams()
  const queryClient = useQueryClient()
  const [activeStageId, setActiveStageId] = useState<string | null>(null)
  const [breakdownStageId, setBreakdownStageId] = useState<string | null>(null)

  const { data, isLoading, error } = useQuery({
    queryKey: ['admin', 'production', id],
    queryFn: () => productionApi.get(id),
    enabled: Boolean(id),
  })

  const invalidate = () => {
    void queryClient.invalidateQueries({ queryKey: ['admin', 'production', id] })
    void queryClient.invalidateQueries({ queryKey: ['admin', 'productions'] })
  }
  const onError = (err: Error) => toast.error(err.message)

  const startMutation = useMutation({ mutationFn: () => productionApi.start(id), onSuccess: invalidate, onError })
  const pauseMutation = useMutation({ mutationFn: () => productionApi.pause(id), onSuccess: invalidate, onError })
  const cancelMutation = useMutation({ mutationFn: () => productionApi.cancel(id), onSuccess: invalidate, onError })
  const startStageMutation = useMutation({
    mutationFn: (stageId: string) => productionApi.startStage(id, stageId),
    onSuccess: invalidate,
    onError,
  })

  const activeStage = data?.stages.find((stage) => stage.id === activeStageId && stage.status === 'IN_PROGRESS') ?? null
  const breakdownStage = data?.stages.find((stage) => stage.id === breakdownStageId) ?? null
  const multiProduct = (data?.items.length ?? 0) > 1

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
              description={describeProducts(data)}
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
                <p className="text-sm text-muted-foreground">Priority</p>
                <p>{data.priority}</p>
              </div>
              <div className="flex flex-col gap-1">
                <p className="text-sm text-muted-foreground">Current stage</p>
                <p>{data.current_stage_name ?? '—'}</p>
              </div>
              <div className="flex flex-col gap-1">
                <p className="text-sm text-muted-foreground">Planned{multiProduct ? ' (total)' : ''}</p>
                <p className="tabular-nums">{formatQuantity(data.planned_quantity)}</p>
              </div>
              <div className="flex flex-col gap-1">
                <p className="text-sm text-muted-foreground">Accepted output{multiProduct ? ' (total)' : ''}</p>
                <p className="tabular-nums">
                  {formatQuantity(fromUnits(sumUnits(data.items.map((item) => item.accepted_output_quantity))))}
                </p>
              </div>
            </div>

            <Card>
              <CardHeader>
                <CardTitle>Products</CardTitle>
                <CardDescription>
                  {multiProduct
                    ? `${data.items.length} products move through the stages together.`
                    : 'The product made by this run.'}
                </CardDescription>
              </CardHeader>
              <CardContent>
                <ResponsiveTable
                  data={data.items}
                  getRowKey={(item) => item.id}
                  columns={[
                    {
                      key: 'product',
                      header: 'Product',
                      primary: true,
                      cell: (item) => `${item.product_name} — ${item.variant_name}`,
                    },
                    { key: 'sku', header: 'SKU', mobile: true, cell: (item) => item.sku },
                    {
                      key: 'planned',
                      header: 'Planned',
                      mobile: true,
                      className: 'tabular-nums',
                      cell: (item) => formatQuantity(item.planned_quantity),
                    },
                    {
                      key: 'in_process',
                      header: 'In process',
                      className: 'tabular-nums',
                      cell: (item) => formatQuantity(currentQuantity(data, item.id, item.planned_quantity)),
                    },
                    {
                      key: 'lost',
                      header: 'Lost',
                      className: 'tabular-nums',
                      cell: (item) => formatQuantity(item.loss_quantity),
                    },
                    {
                      key: 'output',
                      header: 'Final output',
                      className: 'tabular-nums',
                      cell: (item) => (data.status === 'COMPLETED' ? formatQuantity(item.accepted_output_quantity) : '—'),
                    },
                  ]}
                />
              </CardContent>
            </Card>

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
                  header: multiProduct ? 'Input (total)' : 'Input',
                  cell: (stage) => <span className="tabular-nums">{formatQuantity(stage.input_quantity)}</span>,
                },
                {
                  key: 'accepted',
                  header: multiProduct ? 'Accepted (total)' : 'Accepted',
                  cell: (stage) => (
                    <span className="tabular-nums">{formatQuantity(stage.accepted_output_quantity)}</span>
                  ),
                },
                {
                  key: 'loss',
                  header: multiProduct ? 'Loss (total)' : 'Loss',
                  cell: (stage) => <span className="tabular-nums">{formatQuantity(stage.loss_quantity)}</span>,
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
                      {startStageMutation.isPending && startStageMutation.variables === stage.id ? (
                        <Spinner data-icon="inline-start" />
                      ) : null}
                      Start
                    </Button>
                  ) : null}
                  {stage.status === 'IN_PROGRESS' ? (
                    <Button size="sm" onClick={() => setActiveStageId(stage.id)}>
                      Complete
                    </Button>
                  ) : null}
                  {multiProduct && stage.status === 'COMPLETED' ? (
                    <Button
                      variant="ghost"
                      size="sm"
                      onClick={() => setBreakdownStageId(breakdownStageId === stage.id ? null : stage.id)}
                    >
                      {breakdownStageId === stage.id ? 'Hide' : 'By product'}
                    </Button>
                  ) : null}
                </div>
              )}
            />

            {breakdownStage ? <StageBreakdown stage={breakdownStage} onClose={() => setBreakdownStageId(null)} /> : null}

            {activeStage ? (
              // Keyed so the form resets when switching stages.
              <CompleteStageForm
                key={activeStage.id}
                productionId={id}
                stage={activeStage}
                onDone={() => {
                  setActiveStageId(null)
                  invalidate()
                }}
                onCancel={() => setActiveStageId(null)}
              />
            ) : null}
          </>
        ) : null}
      </QueryState>
    </section>
  )
}

interface LineDraft {
  item_id: string
  label: string
  sku: string
  input: string
  accepted: string
  loss: string
  reason: string
}

function CompleteStageForm({
  productionId,
  stage,
  onDone,
  onCancel,
}: {
  productionId: string
  stage: ProductionStage
  onDone: () => void
  onCancel: () => void
}) {
  const [lines, setLines] = useState<LineDraft[]>(() =>
    stage.lines.map((line) => ({
      item_id: line.item_id,
      label: `${line.product_name} — ${line.variant_name}`,
      sku: line.sku,
      input: line.input_quantity,
      accepted: line.input_quantity,
      loss: '0',
      reason: 'QUALITY_REJECT',
    })),
  )
  const [notes, setNotes] = useState('')
  const [showErrors, setShowErrors] = useState(false)
  const balanced = stage.reconciliation_mode === 'STRICT'

  const update = (itemId: string, patch: Partial<LineDraft>) =>
    setLines((current) => current.map((line) => (line.item_id === itemId ? { ...line, ...patch } : line)))

  /** In strict / flexible stages, entering a loss moves the rest to accepted output. */
  const updateLoss = (line: LineDraft, loss: string) => {
    const patch: Partial<LineDraft> = { loss }
    if (stage.reconciliation_mode !== 'CONVERSION' && DECIMAL.test(loss.trim())) {
      const rest = toUnits(line.input) - toUnits(loss)
      if (rest >= 0) patch.accepted = fromUnits(rest)
    }
    update(line.item_id, patch)
  }

  const lineError = (line: LineDraft): string | null => {
    if (!DECIMAL.test(line.accepted.trim()) || !DECIMAL.test((line.loss || '0').trim())) {
      return 'Enter quantities of 0 or more (up to 4 decimals).'
    }
    const sum = toUnits(line.accepted) + toUnits(line.loss || '0')
    if (balanced && sum !== toUnits(line.input)) {
      return `Accepted + loss must equal the input (${formatQuantity(line.input)}).`
    }
    if (stage.reconciliation_mode === 'FLEXIBLE' && sum > toUnits(line.input)) {
      return `Accepted + loss cannot exceed the input (${formatQuantity(line.input)}).`
    }
    return null
  }

  const complete = useMutation({
    mutationFn: () =>
      productionApi.completeStage(productionId, stage.id, {
        notes: notes.trim() || undefined,
        items: lines.map((line) => {
          const loss = fromUnits(toUnits(line.loss || '0'))
          return {
            item_id: line.item_id,
            accepted_output_quantity: fromUnits(toUnits(line.accepted)),
            loss_quantity: loss,
            losses: toUnits(loss) > 0 ? [{ reason_code: line.reason, quantity: loss }] : undefined,
          }
        }),
      }),
    onSuccess: () => {
      toast.success(`${stage.name} completed`)
      onDone()
    },
  })

  const hasErrors = lines.some((line) => lineError(line) !== null)

  return (
    <Card>
      <CardHeader>
        <CardTitle>Complete {stage.name}</CardTitle>
        <CardDescription>
          Record what came out of this stage for each product.{' '}
          {balanced
            ? 'Accepted + loss must equal what went in.'
            : stage.reconciliation_mode === 'FLEXIBLE'
              ? 'Accepted + loss may be less than what went in.'
              : 'Output is counted in its own unit.'}
        </CardDescription>
      </CardHeader>
      <CardContent>
        <form
          id="complete-stage-form"
          className="flex flex-col gap-5"
          onSubmit={(event) => {
            event.preventDefault()
            setShowErrors(true)
            if (!hasErrors) complete.mutate()
          }}
        >
          <div className="overflow-x-auto [contain:inline-size]">
            <Table className="min-w-[44rem]">
              <TableHeader>
                <TableRow>
                  <TableHead>Product</TableHead>
                  <TableHead className="text-right">Input</TableHead>
                  <TableHead>Accepted</TableHead>
                  <TableHead>Loss</TableHead>
                  <TableHead>Loss reason</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {lines.map((line) => {
                  const error = showErrors ? lineError(line) : null
                  const hasLoss = DECIMAL.test((line.loss || '0').trim()) && toUnits(line.loss || '0') > 0

                  return (
                    <TableRow key={line.item_id}>
                      <TableCell className="align-top">
                        <div className="flex flex-col">
                          <span className="font-medium">{line.label}</span>
                          <span className="text-xs text-muted-foreground">{line.sku}</span>
                          {error ? <FieldError className="mt-1">{error}</FieldError> : null}
                        </div>
                      </TableCell>
                      <TableCell className="text-right align-top tabular-nums">{formatQuantity(line.input)}</TableCell>
                      <TableCell className="align-top">
                        <Input
                          aria-label={`Accepted output for ${line.sku}`}
                          inputMode="decimal"
                          className="w-24 tabular-nums"
                          value={line.accepted}
                          aria-invalid={error ? true : undefined}
                          onChange={(event) => update(line.item_id, { accepted: event.target.value })}
                        />
                      </TableCell>
                      <TableCell className="align-top">
                        <Input
                          aria-label={`Loss for ${line.sku}`}
                          inputMode="decimal"
                          className="w-24 tabular-nums"
                          value={line.loss}
                          disabled={!stage.can_record_loss}
                          aria-invalid={error ? true : undefined}
                          onChange={(event) => updateLoss(line, event.target.value)}
                        />
                      </TableCell>
                      <TableCell className="align-top">
                        <NativeSelect
                          aria-label={`Loss reason for ${line.sku}`}
                          value={line.reason}
                          disabled={!hasLoss}
                          onChange={(event) => update(line.item_id, { reason: event.target.value })}
                        >
                          {LOSS_REASONS.map((reason) => (
                            <NativeSelectOption key={reason.value} value={reason.value}>
                              {reason.label}
                            </NativeSelectOption>
                          ))}
                        </NativeSelect>
                      </TableCell>
                    </TableRow>
                  )
                })}
              </TableBody>
            </Table>
          </div>

          <Field>
            <FieldLabel htmlFor="stage-notes">Notes</FieldLabel>
            <Textarea id="stage-notes" value={notes} onChange={(event) => setNotes(event.target.value)} rows={2} />
            <FieldDescription>Optional notes for this stage completion.</FieldDescription>
          </Field>

          {complete.error ? <FieldError>{complete.error.message}</FieldError> : null}
        </form>
      </CardContent>
      <CardFooter className="flex gap-2">
        <Button type="submit" form="complete-stage-form" disabled={complete.isPending}>
          {complete.isPending ? <Spinner data-icon="inline-start" /> : null}
          Record completion
        </Button>
        <Button type="button" variant="outline" onClick={onCancel}>
          Cancel
        </Button>
      </CardFooter>
    </Card>
  )
}

function StageBreakdown({ stage, onClose }: { stage: ProductionStage; onClose: () => void }) {
  return (
    <Card>
      <CardHeader>
        <CardTitle>{stage.name} — by product</CardTitle>
      </CardHeader>
      <CardContent>
        <ResponsiveTable
          data={stage.lines}
          getRowKey={(line) => line.id}
          columns={[
            {
              key: 'product',
              header: 'Product',
              primary: true,
              cell: (line) => `${line.product_name} — ${line.variant_name} (${line.sku})`,
            },
            {
              key: 'input',
              header: 'Input',
              mobile: true,
              className: 'tabular-nums',
              cell: (line) => formatQuantity(line.input_quantity),
            },
            {
              key: 'accepted',
              header: 'Accepted',
              mobile: true,
              className: 'tabular-nums',
              cell: (line) => formatQuantity(line.accepted_output_quantity),
            },
            {
              key: 'loss',
              header: 'Loss',
              mobile: true,
              className: 'tabular-nums',
              cell: (line) => formatQuantity(line.loss_quantity),
            },
          ]}
        />
      </CardContent>
      <CardFooter>
        <Button variant="outline" size="sm" onClick={onClose}>
          Close
        </Button>
      </CardFooter>
    </Card>
  )
}

function describeProducts(data: ProductionDetail): string {
  const [first] = data.items
  if (!first) return ''
  if (data.items.length === 1) return `${first.product_name} — ${first.variant_name} (${first.sku})`
  return `${data.items.length} products: ${data.items.map((item) => item.sku).join(', ')}`
}

function sumUnits(values: string[]): number {
  return values.reduce((total, value) => total + toUnits(value), 0)
}

/** Quantity of a product still moving through the stages: the latest stage's input or accepted output. */
function currentQuantity(data: ProductionDetail, itemId: string, planned: string): string {
  let current = planned
  for (const stage of data.stages) {
    const line = stage.lines.find((candidate) => candidate.item_id === itemId)
    if (!line) continue
    current = stage.status === 'COMPLETED' ? line.accepted_output_quantity : line.input_quantity
  }
  return current
}
