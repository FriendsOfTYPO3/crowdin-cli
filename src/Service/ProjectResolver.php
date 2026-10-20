<?php

declare(strict_types=1);

namespace TYPO3\CrowdinCli\Service;

use FriendsOfTYPO3\CrowdinBase\Configuration\ConfigurationReader;
use FriendsOfTYPO3\CrowdinBase\Configuration\Entity\Project;

/**
 * Finds a project of the local configuration by its Crowdin ID (or identifier).
 */
final readonly class ProjectResolver
{
    public function __construct(
        private ConfigurationReader $reader,
    ) {}

    public function resolve(string $idOrIdentifier): Project
    {
        foreach ($this->reader->read() as $project) {
            if ((string)$project->id === $idOrIdentifier || $project->identifier === $idOrIdentifier) {
                return $project;
            }
        }

        throw new \InvalidArgumentException(
            sprintf('Unknown project "%s". Run "projects:list --refresh" to update the local configuration.', $idOrIdentifier)
        );
    }
}
