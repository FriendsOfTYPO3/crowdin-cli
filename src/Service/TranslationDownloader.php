<?php

declare(strict_types=1);

namespace TYPO3\CrowdinCli\Service;

use CrowdinApiClient\Crowdin;
use CrowdinApiClient\Exceptions\ApiException;
use CrowdinApiClient\Exceptions\ApiValidationException;
use GuzzleHttp\Exception\BadResponseException;
use Psr\Http\Message\StreamInterface;

/**
 * Downloads the translation files of a project in one language through the Crowdin API.
 *
 * Building the translations is asynchronous: the build is requested, polled until finished, then its archive is
 * downloaded and extracted.
 */
final readonly class TranslationDownloader
{
    private const string WHAT = 'the project translations';

    /**
     * @param int $pollIntervalMicroseconds pause between two checks whether the build is ready
     * @param int $maxPolls number of checks before giving up
     */
    public function __construct(
        private Crowdin $crowdin,
        private int $pollIntervalMicroseconds = 1_000_000,
        private int $maxPolls = 300,
    ) {}

    /**
     * @return list<string> paths of the extracted files, relative to the target directory
     * @throws \RuntimeException if the translations cannot be built, downloaded or extracted
     */
    public function download(int $projectId, string $languageId, string $targetDirectory, bool $approvedOnly = false): array
    {
        if (!extension_loaded('zip')) {
            throw new \RuntimeException('The PHP extension "zip" is required to extract the translations.');
        }

        try {
            $translations = $this->crowdin->translation;
            $build = $translations->buildProject($projectId, [
                'targetLanguageIds' => [$languageId],
                'exportApprovedOnly' => $approvedOnly,
            ]);
            if ($build === null) {
                throw new \RuntimeException('Crowdin returned no build.');
            }

            $this->waitUntilFinished($projectId, $build->getId(), $build->getStatus());

            $url = $translations->downloadProjectBuild($projectId, $build->getId())?->getUrl()
                ?? throw new \RuntimeException('Crowdin returned no download link for the translations.');
            // The link is pre-signed, it must not be sent with the access token
            /** @var StreamInterface $stream */
            $stream = $this->crowdin->getClient()->request('GET', $url, []);
            $archive = (string)$stream;
        } catch (BadResponseException | ApiException | ApiValidationException $e) {
            throw ApiFailure::describe($e, self::WHAT, $projectId);
        }

        return $this->extract($archive, $targetDirectory);
    }

    private function waitUntilFinished(int $projectId, int $buildId, string $status): void
    {
        for ($poll = 0; $status !== 'finished'; $poll++) {
            if ($status === 'failed' || $status === 'canceled') {
                throw new \RuntimeException('Crowdin failed to build the translations.');
            }
            if ($poll >= $this->maxPolls) {
                throw new \RuntimeException('Crowdin takes too long to build the translations, try again later.');
            }

            usleep($this->pollIntervalMicroseconds);
            $status = $this->crowdin->translation->getProjectBuildStatus($projectId, $buildId)?->getStatus() ?? 'failed';
        }
    }

    /**
     * @return list<string>
     */
    private function extract(string $archive, string $targetDirectory): array
    {
        $temporaryFile = tempnam(sys_get_temp_dir(), 'crowdin-');
        if ($temporaryFile === false) {
            throw new \RuntimeException('Cannot create a temporary file for the downloaded translations.');
        }

        try {
            file_put_contents($temporaryFile, $archive);
            $zip = new \ZipArchive();
            if ($zip->open($temporaryFile) !== true) {
                throw new \RuntimeException('The downloaded translations are not a valid archive.');
            }

            $files = [];
            try {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = (string)$zip->getNameIndex($i);
                    if (str_ends_with($name, '/')) {
                        continue;
                    }
                    $relative = self::safeRelativePath($name);
                    $target = rtrim($targetDirectory, '/') . '/' . $relative;
                    $directory = dirname($target);
                    if (!is_dir($directory) && !mkdir($directory, 0o755, true) && !is_dir($directory)) {
                        throw new \RuntimeException(sprintf('Cannot create the directory "%s".', $directory));
                    }
                    $content = $zip->getFromIndex($i);
                    if ($content === false || file_put_contents($target, $content) === false) {
                        throw new \RuntimeException(sprintf('Cannot write the file "%s".', $target));
                    }
                    $files[] = $relative;
                }
            } finally {
                $zip->close();
            }
        } finally {
            @unlink($temporaryFile);
        }

        sort($files);

        return $files;
    }

    /**
     * Refuses entries which would be written outside of the target directory.
     */
    private static function safeRelativePath(string $name): string
    {
        $segments = array_values(array_filter(explode('/', str_replace('\\', '/', $name)), static fn(string $s): bool => $s !== '' && $s !== '.'));
        if ($segments === [] || in_array('..', $segments, true) || preg_match('#^[A-Za-z]:#', $segments[0]) === 1) {
            throw new \RuntimeException(sprintf('The downloaded archive contains an unsafe path "%s".', $name));
        }

        return implode('/', $segments);
    }
}
