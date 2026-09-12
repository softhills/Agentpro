<?php

namespace App\Services\Identity;

/**
 * What a lister supplies for a check.
 *
 * NDPA (NFR-04): the document number is passed to the vendor and then dropped.
 * It is never persisted and never written to a log — only the vendor reference
 * and the decision are kept.
 */
final class VerificationSubmission
{
    public function __construct(
        public readonly string $documentType,   // nin | bvn | passport | drivers_licence
        public readonly string $documentNumber,
        public readonly ?string $selfiePath = null,
        public readonly ?string $cacNumber = null,   // firms and developers
    ) {}

    /** Safe to log. The document number is deliberately absent. */
    public function toLogContext(): array
    {
        return [
            'document_type' => $this->documentType,
            'has_selfie'    => $this->selfiePath !== null,
            'has_cac'       => $this->cacNumber !== null,
        ];
    }
}
