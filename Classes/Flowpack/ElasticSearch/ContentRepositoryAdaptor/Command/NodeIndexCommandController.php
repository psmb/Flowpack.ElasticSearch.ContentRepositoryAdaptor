<?php
namespace Flowpack\ElasticSearch\ContentRepositoryAdaptor\Command;

/*                                                                                                  *
 * This script belongs to the TYPO3 Flow package "Flowpack.ElasticSearch.ContentRepositoryAdaptor". *
 *                                                                                                  *
 * It is free software; you can redistribute it and/or modify it under                              *
 * the terms of the GNU Lesser General Public License, either version 3                             *
 *  of the License, or (at your option) any later version.                                          *
 *                                                                                                  *
 * The TYPO3 project - inspiring people to share!                                                   *
 *                                                                                                  */

use Flowpack\ElasticSearch\ContentRepositoryAdaptor\Service\IndexWorkspaceTrait;
use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Cli\CommandController;
use Flowpack\ElasticSearch\ContentRepositoryAdaptor\Mapping\NodeTypeMappingBuilder;

/**
 * Provides CLI features for index handling
 *
 * @Flow\Scope("singleton")
 */
class NodeIndexCommandController extends CommandController
{
    use IndexWorkspaceTrait;

    /**
     * @Flow\Inject
     * @var \Flowpack\ElasticSearch\ContentRepositoryAdaptor\Indexer\NodeIndexer
     */
    protected $nodeIndexer;

    /**
     * @Flow\Inject
     * @var \TYPO3\TYPO3CR\Domain\Repository\WorkspaceRepository
     */
    protected $workspaceRepository;

    /**
     * @Flow\Inject
     * @var \TYPO3\TYPO3CR\Domain\Repository\NodeDataRepository
     */
    protected $nodeDataRepository;

    /**
     * @Flow\Inject
     * @var \TYPO3\TYPO3CR\Domain\Factory\NodeFactory
     */
    protected $nodeFactory;

    /**
     * @Flow\Inject
     * @var \TYPO3\Neos\Domain\Service\ContentDimensionPresetSourceInterface
     */
    protected $contentDimensionPresetSource;

    /**
     * @Flow\Inject
     * @var NodeTypeMappingBuilder
     */
    protected $nodeTypeMappingBuilder;

    /**
     * @Flow\Inject
     * @var \Flowpack\ElasticSearch\ContentRepositoryAdaptor\LoggerInterface
     */
    protected $logger;

    /**
     * @Flow\Inject
     * @var \TYPO3\Flow\Configuration\ConfigurationManager
     */
    protected $configurationManager;

    /**
     * @var array
     */
    protected $settings;

    /**
     * Called by the Flow object framework after creating the object and resolving all dependencies.
     *
     * @param integer $cause Creation cause
     */
    public function initializeObject($cause)
    {
        if ($cause === \TYPO3\Flow\Object\ObjectManagerInterface::INITIALIZATIONCAUSE_CREATED) {
            $this->settings = $this->configurationManager->getConfiguration(\TYPO3\Flow\Configuration\ConfigurationManager::CONFIGURATION_TYPE_SETTINGS, 'TYPO3.TYPO3CR.Search');
        }
    }

    /**
     * Show the mapping which would be sent to the ElasticSearch server
     *
     * @return void
     */
    public function showMappingCommand()
    {
        $nodeTypeMappingCollection = $this->nodeTypeMappingBuilder->buildMappingInformation($this->nodeIndexer->getIndex());
        foreach ($nodeTypeMappingCollection as $mapping) {
            /** @var \Flowpack\ElasticSearch\Domain\Model\Mapping $mapping */
            $this->output(\Symfony\Component\Yaml\Yaml::dump($mapping->asArray(), 5, 2));
            $this->outputLine();
        }
        $this->outputLine('------------');

        $mappingErrors = $this->nodeTypeMappingBuilder->getLastMappingErrors();
        if ($mappingErrors->hasErrors()) {
            $this->outputLine('<b>Mapping Errors</b>');
            foreach ($mappingErrors->getFlattenedErrors() as $errors) {
                foreach ($errors as $error) {
                    $this->outputLine($error);
                }
            }
        }

        if ($mappingErrors->hasWarnings()) {
            $this->outputLine('<b>Mapping Warnings</b>');
            foreach ($mappingErrors->getFlattenedWarnings() as $warnings) {
                foreach ($warnings as $warning) {
                    $this->outputLine($warning);
                }
            }
        }
    }

    /**
     * Index all nodes by creating a new index and when everything was completed, switch the index alias.
     *
     * This command (re-)indexes all nodes contained in the content repository and sets the schema beforehand.
     *
     * @param integer $limit Amount of nodes to index at maximum
     * @param boolean $update if TRUE, do not throw away the index at the start. Should *only be used for development*.
     * @param string $workspace name of the workspace which should be indexed
     * @param string $postfix Index postfix, index with the same postifix will be deleted if exist
     * @return void
     */
    public function buildCommand($limit = null, $update = false, $workspace = null, $postfix = null)
    {
        if ($update === true) {
            $this->logger->log('!!! Update Mode (Development) active!', LOG_INFO);
        } else {
            $this->nodeIndexer->setIndexNamePostfix($postfix ?: time());
            if ($this->nodeIndexer->getIndex()->exists() === true) {
                $this->logger->log(sprintf('Deleted index with the same postfix (%s)!', $postfix), LOG_WARNING);
                $this->nodeIndexer->getIndex()->delete();
            }
            $this->nodeIndexer->getIndex()->create();
            $this->logger->log('Created index ' . $this->nodeIndexer->getIndexName(), LOG_INFO);

            $nodeTypeMappingCollection = $this->nodeTypeMappingBuilder->buildMappingInformation($this->nodeIndexer->getIndex());
            foreach ($nodeTypeMappingCollection as $mapping) {
                /** @var \Flowpack\ElasticSearch\Domain\Model\Mapping $mapping */
                $mapping->apply();
            }
            $this->logger->log('Updated Mapping.', LOG_INFO);
        }

        $this->logger->log(sprintf('Indexing %snodes ... ', ($limit !== null ? 'the first ' . $limit . ' ' : '')), LOG_INFO);

        $count = 0;

        if ($workspace === null && $this->settings['indexAllWorkspaces'] === false) {
            $workspace = 'live';
        }

        $callback = function ($workspaceName, $indexedNodes, $dimensions) {
            if ($dimensions === array()) {
                $this->outputLine('Workspace "' . $workspaceName . '" without dimensions done. (Indexed ' . $indexedNodes . ' nodes)');
            } else {
                $this->outputLine('Workspace "' . $workspaceName . '" and dimensions "' . json_encode($dimensions) . '" done. (Indexed ' . $indexedNodes . ' nodes)');
            }
        };
        if ($workspace === null) {
            foreach ($this->workspaceRepository->findAll() as $workspace) {
                $count += $this->indexWorkspace($workspace->getName(), $limit, $callback);
            }
        } else {
            $count += $this->indexWorkspace($workspace, $limit, $callback);
        }

        $this->nodeIndexingManager->flushQueues();

        $this->logger->log('Done. (indexed ' . $count . ' nodes)', LOG_INFO);
        $this->nodeIndexer->getIndex()->refresh();

        // TODO: smoke tests
        if ($update === false) {
            $this->nodeIndexer->updateIndexAlias();
        }
    }

    /**
     * Clean up old indexes (i.e. all but the current one)
     *
     * @return void
     */
    public function cleanupCommand()
    {
        try {
            $indicesToBeRemoved = $this->nodeIndexer->removeOldIndices();
            if (count($indicesToBeRemoved) > 0) {
                foreach ($indicesToBeRemoved as $indexToBeRemoved) {
                    $this->logger->log('Removing old index ' . $indexToBeRemoved);
                }
            } else {
                $this->logger->log('Nothing to remove.');
            }
        } catch (\Flowpack\ElasticSearch\Transfer\Exception\ApiException $exception) {
            $response = json_decode($exception->getResponse());
            $this->logger->log(sprintf('Nothing removed. ElasticSearch responded with status %s, saying "%s"', $response->status, $response->error));
        }
    }
}
