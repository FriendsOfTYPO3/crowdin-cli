<?php

declare(strict_types=1);

namespace TYPO3\CrowdinCli\Tests\Service;

use CrowdinApiClient\Api\TranslationStatusApi;
use CrowdinApiClient\Crowdin;
use CrowdinApiClient\Model\Progress;
use CrowdinApiClient\ModelCollection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use TYPO3\CrowdinCli\Service\ProjectProgressRepository;

#[AllowMockObjectsWithoutExpectations]
final class ProjectProgressRepositoryTest extends TestCase
{
    private function subject(): ProjectProgressRepository
    {
        $collection = new ModelCollection();
        foreach ([['fr', 50, 30], ['de', 90, 90]] as [$language, $translated, $approved]) {
            $collection->add(new Progress(['language' => [], 'languageId' => $language, 'translationProgress' => $translated, 'approvalProgress' => $approved]));
        }
        $api = $this->createMock(TranslationStatusApi::class);
        $api->expects(self::once())->method('getProjectProgress')->with(123, ['limit' => 500])->willReturn($collection);
        $crowdin = $this->createMock(Crowdin::class);
        $crowdin->method('__get')->willReturnMap([['translationStatus', $api]]);

        return new ProjectProgressRepository($crowdin);
    }

    public function testFindProgressIsOrderedByLanguage(): void
    {
        $result = $this->subject()->findProgress(123);

        self::assertSame(['de', 'fr'], array_column($result, 'languageId'));
        self::assertSame(20, $result[1]->pending());
    }

    public function testFindProgressFiltersByLanguageIgnoringCase(): void
    {
        $result = $this->subject()->findProgress(123, 'FR');

        self::assertCount(1, $result);
        self::assertSame('fr', $result[0]->languageId);
    }
}
