<?php

declare(strict_types=1);

namespace TYPO3\CrowdinCli\Entity;

/**
 * What would be uploaded from one local file: only the translations Crowdin does not have yet.
 */
final readonly class UploadCandidate
{
    /**
     * @param string $relativePath path of the local file, relative to the directory being uploaded
     * @param string|null $content the XLIFF to upload, reduced to the translations to upload (null if nothing to upload)
     * @param int $toUpload translations which are new or differ from the current ones of Crowdin
     * @param int $unchanged translations already identical to the current ones of Crowdin
     * @param int $sameAsSource translations identical to their source text, left out unless explicitly allowed
     * @param int $empty units without translation
     * @param string|null $skipReason why nothing can be uploaded from this file
     */
    public function __construct(
        public string $relativePath,
        public ?string $sourcePath = null,
        public ?int $fileId = null,
        public ?string $content = null,
        public int $toUpload = 0,
        public int $unchanged = 0,
        public int $sameAsSource = 0,
        public int $empty = 0,
        public ?string $skipReason = null,
    ) {}

    public function isUploadable(): bool
    {
        return $this->skipReason === null && $this->content !== null && $this->fileId !== null && $this->toUpload > 0;
    }

    public static function skipped(string $relativePath, string $reason, ?string $sourcePath = null): self
    {
        return new self($relativePath, $sourcePath, skipReason: $reason);
    }
}
