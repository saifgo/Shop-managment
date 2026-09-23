<?php

declare(strict_types=1);

namespace App\Application\Documents\Message;

final readonly class GenerateDocumentPdf
{
    public function __construct(
        public string $documentId,
        public string $companyId,
    ) {
    }
}
