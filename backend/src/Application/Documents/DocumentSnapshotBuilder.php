<?php

declare(strict_types=1);

namespace App\Application\Documents;

use App\Infrastructure\Persistence\Entity\Documents\CommercialDocument;

final class DocumentSnapshotBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function customerSnapshot(\App\Infrastructure\Persistence\Entity\Customer\Customer $customer): array
    {
        $billing = null;
        $shipping = null;

        foreach ($customer->getAddresses() as $address) {
            $payload = [
                'type' => $address->getType(),
                'line1' => $address->getLine1(),
                'line2' => $address->getLine2(),
                'city' => $address->getCity(),
                'postal_code' => $address->getPostalCode(),
                'country' => $address->getCountry(),
            ];

            if ($address->isDefault() || $billing === null) {
                $billing = $payload;
            }

            if ($address->getType() === 'shipping' || $shipping === null) {
                $shipping = $payload;
            }
        }

        return [
            'display_name' => $customer->getDisplayName(),
            'legal_name' => $customer->getLegalName(),
            'tax_id' => $customer->getTaxId(),
            'vat_number' => $customer->getVatNumber(),
            'billing_address' => $billing,
            'shipping_address' => $shipping ?? $billing,
        ];
    }

    /**
     * @return array{subtotal: \App\Domain\Shared\Money, tax_total: \App\Domain\Shared\Money, discount_total: \App\Domain\Shared\Money, grand_total: \App\Domain\Shared\Money}
     */
    public static function totalsFromLines(array $lines, string $currency): array
    {
        $subtotal = \App\Domain\Shared\Money::zero($currency);
        $taxTotal = \App\Domain\Shared\Money::zero($currency);
        $discountTotal = \App\Domain\Shared\Money::zero($currency);
        $grandTotal = \App\Domain\Shared\Money::zero($currency);

        foreach ($lines as $line) {
            $subtotal = $subtotal->add($line['line_subtotal']);
            $taxTotal = $taxTotal->add($line['line_tax']);
            $discountTotal = $discountTotal->add($line['discount_amount']);
            $grandTotal = $grandTotal->add($line['line_total']);
        }

        return [
            'subtotal' => $subtotal,
            'tax_total' => $taxTotal,
            'discount_total' => $discountTotal,
            'grand_total' => $grandTotal,
        ];
    }

    /**
     * @return array{line_subtotal: \App\Domain\Shared\Money, line_tax: \App\Domain\Shared\Money, discount_amount: \App\Domain\Shared\Money, line_total: \App\Domain\Shared\Money}
     */
    public static function lineTotals(
        \App\Domain\Shared\Quantity $quantity,
        \App\Domain\Shared\Money $unitPrice,
        string $taxRate,
        \App\Domain\Shared\Money $discountAmount,
    ): array {
        $lineSubtotal = \App\Domain\Shared\Money::of(
            bcmul($quantity->amount(), $unitPrice->amount(), 4),
            $unitPrice->currency(),
        );
        $taxable = $lineSubtotal->subtract($discountAmount);
        $lineTax = \App\Domain\Shared\Money::of(
            bcmul($taxable->amount(), bcdiv($taxRate, '100', 6), 4),
            $unitPrice->currency(),
        );
        $lineTotal = $taxable->add($lineTax);

        return [
            'line_subtotal' => $lineSubtotal,
            'line_tax' => $lineTax,
            'discount_amount' => $discountAmount,
            'line_total' => $lineTotal,
        ];
    }

    /** @return array<string, mixed> */
    public function serializeDocument(CommercialDocument $document): array
    {
        $lines = [];

        foreach ($document->getLines() as $line) {
            $lines[] = [
                'id' => $line->getId(),
                'source_line_id' => $line->getSourceLineId(),
                'description' => $line->getDescription(),
                'sku' => $line->getSku(),
                'quantity' => $line->getQuantity()->amount(),
                'unit_price' => ['amount' => $line->getUnitPrice()->amount(), 'currency' => $document->getCurrency()],
                'tax_rate' => $line->getTaxRate(),
                'discount_amount' => ['amount' => $line->getDiscountAmount()->amount(), 'currency' => $document->getCurrency()],
                'line_subtotal' => ['amount' => $line->getLineSubtotal()->amount(), 'currency' => $document->getCurrency()],
                'line_tax' => ['amount' => $line->getLineTax()->amount(), 'currency' => $document->getCurrency()],
                'line_total' => ['amount' => $line->getLineTotal()->amount(), 'currency' => $document->getCurrency()],
            ];
        }

        return [
            'id' => $document->getId(),
            'document_type' => $document->getDocumentType()->value,
            'status' => $document->getStatus(),
            'document_number' => $document->getDocumentNumber(),
            'fiscal_year' => $document->getFiscalYear(),
            'customer_id' => $document->getCustomer()->getId(),
            'customer_display_name' => $document->getCustomerDisplayName(),
            'customer_legal_name' => $document->getCustomerLegalName(),
            'order_id' => $document->getOrder()?->getId(),
            'delivery_id' => $document->getDelivery()?->getId(),
            'source_document_id' => $document->getSourceDocument()?->getId(),
            'currency' => $document->getCurrency(),
            'subtotal' => ['amount' => $document->getSubtotal()->amount(), 'currency' => $document->getCurrency()],
            'tax_total' => ['amount' => $document->getTaxTotal()->amount(), 'currency' => $document->getCurrency()],
            'discount_total' => ['amount' => $document->getDiscountTotal()->amount(), 'currency' => $document->getCurrency()],
            'stamp_duty' => ['amount' => $document->getStampDuty()->amount(), 'currency' => $document->getCurrency()],
            'grand_total' => ['amount' => $document->getGrandTotal()->amount(), 'currency' => $document->getCurrency()],
            'amount_paid' => ['amount' => $document->getAmountPaid()->amount(), 'currency' => $document->getCurrency()],
            'amount_due' => ['amount' => $document->getAmountDue()->amount(), 'currency' => $document->getCurrency()],
            'is_posted' => $document->isPosted(),
            'posted_at' => $document->getPostedAt()?->format(DATE_ATOM),
            'issued_at' => $document->getIssuedAt()?->format(DATE_ATOM),
            'due_date' => $document->getDueDate()?->format('Y-m-d'),
            'notes' => $document->getNotes(),
            'share_token' => $document->getShareToken(),
            'created_at' => $document->getCreatedAt()->format(DATE_ATOM),
            'lines' => $lines,
        ];
    }
}
