import { CheckIcon, XIcon } from 'lucide-react'
import { cn } from '@/lib/utils'

const STEPS = [
  { label: 'Placed', statuses: ['DRAFT', 'SUBMITTED'] },
  { label: 'Confirmed', statuses: ['CONFIRMED', 'PARTIALLY_ALLOCATED'] },
  { label: 'Ready to ship', statuses: ['READY_TO_DELIVER', 'PARTIALLY_DELIVERED'] },
  { label: 'Delivered', statuses: ['DELIVERED'] },
]

const HINTS: Record<string, string> = {
  SUBMITTED: 'Waiting for confirmation.',
  CONFIRMED: 'Confirmed; stock is being allocated.',
  PARTIALLY_ALLOCATED: 'Some items are being made — they ship as soon as production finishes.',
  READY_TO_DELIVER: 'Everything is reserved and ready to ship.',
  PARTIALLY_DELIVERED: 'Part of the order has been delivered.',
  DELIVERED: 'All items delivered.',
}

/** Horizontal order timeline used on both the admin and customer order pages. */
export function OrderProgress({ status, className }: { status: string; className?: string }) {
  if (status === 'CANCELLED') {
    return (
      <div className={cn('flex items-center gap-2 rounded-lg border border-dashed px-4 py-3 text-sm', className)}>
        <span className="flex size-6 items-center justify-center rounded-full bg-destructive/10 text-destructive">
          <XIcon className="size-3.5" />
        </span>
        This order was cancelled.
      </div>
    )
  }

  const current = Math.max(0, STEPS.findIndex((step) => step.statuses.includes(status)))

  return (
    <div className={cn('flex flex-col gap-2 rounded-lg border bg-card px-4 py-3', className)}>
      <ol className="flex items-center gap-2" aria-label="Order progress">
        {STEPS.map((step, index) => {
          const done = index < current || status === 'DELIVERED'
          const active = index === current && status !== 'DELIVERED'
          return (
            <li key={step.label} className="flex flex-1 items-center gap-2 last:flex-none">
              <span
                className={cn(
                  'flex size-6 shrink-0 items-center justify-center rounded-full border text-xs tabular-nums',
                  done && 'border-foreground bg-foreground text-background',
                  active && 'border-foreground font-medium',
                  !done && !active && 'text-muted-foreground',
                )}
                aria-current={active ? 'step' : undefined}
              >
                {done ? <CheckIcon className="size-3.5" /> : index + 1}
              </span>
              <span
                className={cn(
                  'hidden text-sm whitespace-nowrap sm:inline',
                  active || done ? 'text-foreground' : 'text-muted-foreground',
                  active && 'font-medium',
                )}
              >
                {step.label}
              </span>
              {index < STEPS.length - 1 ? (
                <span className={cn('h-px flex-1', index < current ? 'bg-foreground' : 'bg-border')} />
              ) : null}
            </li>
          )
        })}
      </ol>
      {HINTS[status] ? <p className="text-sm text-muted-foreground">{HINTS[status]}</p> : null}
    </div>
  )
}
