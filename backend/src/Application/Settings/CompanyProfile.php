<?php

declare(strict_types=1);

namespace App\Application\Settings;

/** Seller details printed on generated documents. */
final readonly class CompanyProfile
{
    public function __construct(
        public ?string $name,
        public ?string $phone,
        public ?string $email,
        public ?string $taxId,
        public ?string $address,
        public string $bankLabel,
        public ?string $bankAccount,
        /** data: URI of the uploaded stamp/signature image, ready to embed in HTML. */
        public ?string $stampImage,
    ) {
    }
}
