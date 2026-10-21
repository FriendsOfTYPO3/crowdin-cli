<?php

declare(strict_types=1);

namespace TYPO3\CrowdinCli\Service;

use CrowdinApiClient\Crowdin;
use CrowdinApiClient\Exceptions\ApiException;
use CrowdinApiClient\Exceptions\ApiValidationException;
use CrowdinApiClient\Model\File;
use CrowdinApiClient\Model\LanguageTranslation;
use CrowdinApiClient\Model\SourceString;
use GuzzleHttp\Exception\BadResponseException;
use TYPO3\CrowdinCli\Entity\UploadCandidate;

/**
 * Uploads local translation files, as downloaded by TranslationDownloader, back to Crowdin.
 *
 * A downloaded file "<dir>/fr.locallang.xlf" belongs to the source file "/<dir>/locallang.xlf" of the project.
 * Only the translations which Crowdin does not already show for a string are uploaded, by sending a reduced copy
 * of the file. Crowdin imports them as suggestions, unless told to approve them.
 */
final readonly class TranslationUploader
{
    private const int LIMIT = 500;
    private const string WHAT = 'the project translations';

    /**
     * @param int $pollIntervalMicroseconds pause between two checks whether the import is finished
     * @param int $maxPolls number of checks before giving up
     */
    public function __construct(
        private Crowdin $crowdin,
        private int $pollIntervalMicroseconds = 1_000_000,
        private int $maxPolls = 300,
    ) {}

    /**
     * @param string|null $pathPrefix only files whose path (relative to the directory) starts with this value
     * @return list<string> relative paths of the files found, ordered
     */
    public function findLocalFiles(string $directory, ?string $pathPrefix = null): array
    {
        if (!is_dir($directory)) {
            throw new \RuntimeException(sprintf('"%s" is not a directory.', $directory));
        }

        $root = rtrim((string)realpath($directory), '/');
        $prefix = $pathPrefix === null ? null : ltrim($pathPrefix, '/');
        $files = [];
        /** @var \SplFileInfo $item */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $item) {
            if (!$item->isFile()) {
                continue;
            }
            $relative = substr($item->getPathname(), strlen($root) + 1);
            if ($prefix === null || str_starts_with($relative, $prefix)) {
                $files[] = $relative;
            }
        }
        sort($files);

        return $files;
    }

    /**
     * @return array<string, File> the files of the project, indexed by path
     */
    public function findProjectFiles(int $projectId): array
    {
        $files = [];
        try {
            $offset = 0;
            do {
                $count = 0;
                /** @var File $file */
                foreach ($this->crowdin->file->list($projectId, ['limit' => self::LIMIT, 'offset' => $offset]) as $file) {
                    $count++;
                    $files[$file->getPath()] = $file;
                }
                $offset += self::LIMIT;
            } while ($count === self::LIMIT);
        } catch (BadResponseException | ApiException | ApiValidationException $e) {
            throw ApiFailure::describe($e, 'the project files', $projectId);
        }

        return $files;
    }

    /**
     * Works out what a local file would upload. Nothing is sent to Crowdin.
     *
     * @param array<string, File> $projectFiles see findProjectFiles()
     * @param bool $allowSameAsSource also upload translations identical to their source text
     */
    public function analyze(
        int $projectId,
        string $languageId,
        string $directory,
        string $relativePath,
        array $projectFiles,
        bool $allowSameAsSource = false,
    ): UploadCandidate {
        $sourcePath = self::sourcePath($relativePath, $languageId);
        if ($sourcePath === null) {
            return UploadCandidate::skipped($relativePath, sprintf('not a file of the language "%s" (its name should start with "%s.")', $languageId, $languageId));
        }
        $file = $projectFiles[$sourcePath] ?? null;
        if ($file === null) {
            return UploadCandidate::skipped($relativePath, sprintf('no source file "%s" in the project', $sourcePath), $sourcePath);
        }
        if ($file->getType() !== 'xliff') {
            return UploadCandidate::skipped($relativePath, sprintf('format "%s" is not supported, only XLIFF', $file->getType()), $sourcePath);
        }

        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = true;
        if (!@$dom->loadXML((string)file_get_contents(rtrim($directory, '/') . '/' . $relativePath), LIBXML_NONET)) {
            return UploadCandidate::skipped($relativePath, 'not a valid XML file', $sourcePath);
        }

        try {
            [$sources, $current] = $this->findCurrentState($projectId, $languageId, $file->getId());
        } catch (BadResponseException | ApiException | ApiValidationException $e) {
            throw ApiFailure::describe($e, self::WHAT, $projectId);
        }

        $toUpload = $unchanged = $sameAsSource = $empty = 0;
        $remove = [];
        /** @var \DOMElement $unit */
        foreach ($dom->getElementsByTagName('trans-unit') as $unit) {
            $target = null;
            foreach ($unit->childNodes as $child) {
                if ($child instanceof \DOMElement && $child->nodeName === 'target') {
                    $target = self::normalize($child->textContent);
                }
            }
            $source = $sources[$unit->getAttribute('id')] ?? null;

            if ($target === null || trim($target) === '') {
                $empty++;
                $remove[] = $unit;
            } elseif ($source !== null && ($current[$source['id']] ?? null) === $target) {
                $unchanged++;
                $remove[] = $unit;
            } elseif ($source !== null && self::normalize($source['text']) === $target && !$allowSameAsSource) {
                $sameAsSource++;
                $remove[] = $unit;
            } else {
                $toUpload++;
            }
        }
        foreach ($remove as $unit) {
            $unit->parentNode?->removeChild($unit);
        }

        return new UploadCandidate(
            $relativePath,
            $sourcePath,
            $file->getId(),
            $toUpload > 0 ? (string)$dom->saveXML() : null,
            $toUpload,
            $unchanged,
            $sameAsSource,
            $empty,
        );
    }

    /**
     * Sends the translations of a candidate to Crowdin and waits until they are imported.
     *
     * @param bool $allowSameAsSource import translations identical to their source text
     * @param bool $autoApprove approve the imported translations instead of suggesting them
     */
    public function upload(int $projectId, string $languageId, UploadCandidate $candidate, bool $autoApprove = false, bool $allowSameAsSource = false): void
    {
        if (!$candidate->isUploadable()) {
            throw new \LogicException(sprintf('Nothing to upload from "%s".', $candidate->relativePath));
        }

        $directory = sys_get_temp_dir() . '/crowdin-cli-' . bin2hex(random_bytes(6));
        if (!mkdir($directory, 0o700)) {
            throw new \RuntimeException('Cannot create a temporary directory for the upload.');
        }
        $temporaryFile = $directory . '/' . basename($candidate->relativePath);

        try {
            file_put_contents($temporaryFile, (string)$candidate->content);
            $storage = $this->crowdin->storage->create(new \SplFileInfo($temporaryFile))
                ?? throw new \RuntimeException('Crowdin returned no storage for the file.');
            $import = $this->crowdin->translation->importTranslations($projectId, [
                'storageId' => $storage->getId(),
                'languageIds' => [$languageId],
                'fileId' => $candidate->fileId,
                'importEqSuggestions' => $allowSameAsSource,
                'autoApproveImported' => $autoApprove,
            ]) ?? throw new \RuntimeException('Crowdin returned no import.');

            $status = $import->getStatus();
            for ($poll = 0; $status !== 'finished'; $poll++) {
                if ($status === 'failed') {
                    throw new \RuntimeException('Crowdin failed to import the translations.');
                }
                if ($poll >= $this->maxPolls) {
                    throw new \RuntimeException('Crowdin takes too long to import the translations, check the project later.');
                }
                usleep($this->pollIntervalMicroseconds);
                $status = $this->crowdin->translation->getTranslationImportStatus($projectId, $import->getIdentifier())?->getStatus() ?? 'failed';
            }
        } catch (BadResponseException | ApiException | ApiValidationException $e) {
            throw ApiFailure::describe($e, self::WHAT, $projectId);
        } finally {
            @unlink($temporaryFile);
            @rmdir($directory);
        }
    }

    /**
     * "master/Resources/Private/Language/fr.locallang.xlf" becomes "/master/Resources/Private/Language/locallang.xlf".
     */
    private static function sourcePath(string $relativePath, string $languageId): ?string
    {
        $directory = dirname($relativePath);
        $name = basename($relativePath);
        foreach (array_unique([$languageId, str_replace('-', '_', $languageId)]) as $prefix) {
            if (str_starts_with($name, $prefix . '.') && strlen($name) > strlen($prefix) + 1) {
                return '/' . ($directory === '.' ? '' : $directory . '/') . substr($name, strlen($prefix) + 1);
            }
        }

        return null;
    }

    /**
     * @return array{array<string, array{id: int, text: string}>, array<int, string>} source strings by identifier, and
     *         the translation Crowdin currently shows by string ID
     */
    private function findCurrentState(int $projectId, string $languageId, int $fileId): array
    {
        $sources = [];
        $offset = 0;
        do {
            $count = 0;
            /** @var SourceString $string */
            foreach ($this->crowdin->sourceString->list($projectId, ['fileId' => $fileId, 'limit' => self::LIMIT, 'offset' => $offset]) as $string) {
                $count++;
                $sources[$string->getIdentifier()] = ['id' => $string->getId(), 'text' => is_string($string->getText()) ? $string->getText() : ''];
            }
            $offset += self::LIMIT;
        } while ($count === self::LIMIT);

        $current = [];
        $offset = 0;
        do {
            $count = 0;
            /** @var LanguageTranslation $translation */
            foreach ($this->crowdin->stringTranslation->listLanguageTranslations($projectId, $languageId, ['fileId' => $fileId, 'limit' => self::LIMIT, 'offset' => $offset]) as $translation) {
                $count++;
                $current[$translation->getStringId()] = self::normalize((string)$translation->getText());
            }
            $offset += self::LIMIT;
        } while ($count === self::LIMIT);

        return [$sources, $current];
    }

    private static function normalize(string $text): string
    {
        return str_replace("\r\n", "\n", $text);
    }
}
