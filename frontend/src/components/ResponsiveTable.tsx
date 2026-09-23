import type { ReactNode } from 'react'
import { ScrollArea, ScrollBar } from '@/components/ui/scroll-area'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { cn } from '@/lib/utils'

export interface ResponsiveTableColumn<T> {
  key: string
  header: string
  cell: (row: T) => ReactNode
  /** Mark the primary identifier shown first on mobile cards */
  primary?: boolean
  /** Include this field on the mobile card (default: first 3 columns) */
  mobile?: boolean
  className?: string
}

interface ResponsiveTableProps<T> {
  data: T[]
  columns: ResponsiveTableColumn<T>[]
  getRowKey: (row: T) => string
  rowAction?: (row: T) => ReactNode
  /** Use horizontal ScrollArea for intentionally wide tables */
  wide?: boolean
  className?: string
}

function resolveMobileColumns<T>(columns: ResponsiveTableColumn<T>[]) {
  const primary = columns.find((column) => column.primary) ?? columns[0]
  const explicit = columns.filter(
    (column) => column.mobile && column.key !== primary?.key,
  )
  const fallback = columns
    .filter((column) => column.key !== primary?.key)
    .slice(0, 3)
  const fields = (explicit.length > 0 ? explicit : fallback).slice(0, 3)
  return { primary, fields }
}

export function ResponsiveTable<T>({
  data,
  columns,
  getRowKey,
  rowAction,
  wide = false,
  className,
}: ResponsiveTableProps<T>) {
  const { primary, fields } = resolveMobileColumns(columns)

  const table = (
    <Table className={cn(wide && 'min-w-[48rem]')}>
      <TableHeader>
        <TableRow>
          {columns.map((column) => (
            <TableHead key={column.key} className={column.className}>
              {column.header}
            </TableHead>
          ))}
          {rowAction ? <TableHead className="w-[1%] text-right">Actions</TableHead> : null}
        </TableRow>
      </TableHeader>
      <TableBody>
        {data.map((row) => (
          <TableRow key={getRowKey(row)}>
            {columns.map((column) => (
              <TableCell key={column.key} className={column.className}>
                {column.cell(row)}
              </TableCell>
            ))}
            {rowAction ? (
              <TableCell className="text-right">{rowAction(row)}</TableCell>
            ) : null}
          </TableRow>
        ))}
      </TableBody>
    </Table>
  )

  return (
    <div className={cn('w-full', className)}>
      <div className="flex flex-col gap-3 md:hidden">
        {data.map((row) => (
          <div
            key={getRowKey(row)}
            className="flex flex-col gap-3 rounded-lg border border-border bg-card p-4 text-card-foreground"
          >
            <div className="flex items-start justify-between gap-3">
              <div className="min-w-0 font-medium">
                {primary ? primary.cell(row) : null}
              </div>
              {rowAction ? <div className="shrink-0">{rowAction(row)}</div> : null}
            </div>
            {fields.length > 0 ? (
              <dl className="grid grid-cols-1 gap-2">
                {fields.map((column) => (
                  <div key={column.key} className="flex flex-col gap-0.5">
                    <dt className="text-xs text-muted-foreground">{column.header}</dt>
                    <dd className="text-sm">{column.cell(row)}</dd>
                  </div>
                ))}
              </dl>
            ) : null}
          </div>
        ))}
      </div>

      <div className="hidden md:block">
        {wide ? (
          <ScrollArea className="w-full">
            <div className="min-w-[48rem]">{table}</div>
            <ScrollBar orientation="horizontal" />
          </ScrollArea>
        ) : (
          table
        )}
      </div>
    </div>
  )
}
