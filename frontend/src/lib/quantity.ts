/**
 * Exact arithmetic for the API's 4-decimal quantity strings ("12.5000"). Values are handled as
 * integer ten-thousandths, so sums and differences never pick up floating-point noise.
 */

/** Non-negative quantity with up to 4 decimals, as the API accepts it. */
export const DECIMAL = /^\d+(\.\d{1,4})?$/

const SCALE = 10_000

export function toUnits(value: string): number {
  const trimmed = value.trim()
  const negative = trimmed.startsWith('-')
  const [whole, fraction = ''] = (negative ? trimmed.slice(1) : trimmed).split('.')
  const units = Number(whole || '0') * SCALE + Number(fraction.padEnd(4, '0').slice(0, 4))
  return negative ? -units : units
}

export function fromUnits(units: number): string {
  const sign = units < 0 ? '-' : ''
  const abs = Math.abs(units)
  return `${sign}${Math.floor(abs / SCALE)}.${String(abs % SCALE).padStart(4, '0')}`
}
