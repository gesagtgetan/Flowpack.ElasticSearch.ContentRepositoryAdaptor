<?php

declare(strict_types=1);

namespace Flowpack\ElasticSearch\ContentRepositoryAdaptor\Driver\Version5;

/*
 * This file is part of the Flowpack.ElasticSearch.ContentRepositoryAdaptor package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Flowpack\ElasticSearch\ContentRepositoryAdaptor\Driver\AbstractDriver;
use Flowpack\ElasticSearch\ContentRepositoryAdaptor\Driver\DocumentDriverInterface;
use Flowpack\ElasticSearch\ContentRepositoryAdaptor\Driver\NodeTypeMappingBuilderInterface;
use Flowpack\ElasticSearch\Domain\Model\Index;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\NodeType;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Log\Utility\LogEnvironment;

/**
 * Document driver for Elasticsearch version 5.x
 *
 * @Flow\Scope("singleton")
 */
class DocumentDriver extends AbstractDriver implements DocumentDriverInterface
{
    /**
     * @Flow\Inject
     * @var NodeTypeMappingBuilderInterface
     */
    protected $nodeTypeMappingBuilder;

    /**
     * {@inheritdoc}
     */
    public function delete(NodeInterface $node, string $identifier): array
    {
        return [
            [
                'delete' => [
                    '_type' => $this->nodeTypeMappingBuilder->convertNodeTypeNameToMappingName($node->getNodeType()->getName()),
                    '_id' => $identifier
                ]
            ]
        ];
    }

    /**
     * {@inheritdoc}
     * @throws \Flowpack\ElasticSearch\Exception
     */
    public function deleteDuplicateDocumentNotMatchingType(Index $index, string $documentIdentifier, NodeType $nodeType): void
    {
        $result = $index->request('GET', '/_search?scroll=1m', [], json_encode([
            'sort' => ['_doc'],
            'query' => [
                'bool' => [
                    'must' => [
                        'ids' => [
                            'values' => [$documentIdentifier]
                        ]
                    ],
                    'must_not' => [
                        'term' => [
                            '_type' => $this->nodeTypeMappingBuilder->convertNodeTypeNameToMappingName($nodeType->getName())
                        ]
                    ]
                ]
            ]
        ]));
        $treatedContent = $result->getTreatedContent();
        $scrollId = $treatedContent['_scroll_id'];
        $mapHitToDeleteRequest = function ($hit) {
            return json_encode([
                'delete' => [
                    '_type' => $hit['_type'],
                    '_id' => $hit['_id']
                ]
            ]);
        };
        $bulkRequest = [];
        while (isset($treatedContent['hits']['hits']) && $treatedContent['hits']['hits'] !== []) {
            $hits = $treatedContent['hits']['hits'];
            $bulkRequest = array_merge($bulkRequest, array_map($mapHitToDeleteRequest, $hits));
            $result = $index->request('POST', '/_search/scroll', [], json_encode([
                'scroll'    => '1m',
                'scroll_id' => $scrollId,
            ]), false);
            $treatedContent = $result->getTreatedContent();
        }
        $this->logger->debug(sprintf('NodeIndexer: Check duplicate nodes for %s (%s), found %d document(s)', $documentIdentifier, $nodeType->getName(), count($bulkRequest)), LogEnvironment::fromMethodName(__METHOD__));
        if ($bulkRequest !== []) {
            $index->request('POST', '/_bulk', [], implode("\n", $bulkRequest) . "\n");
        }
        $this->searchClient->request('DELETE', '/_search/scroll', [], json_encode([
            'scroll_id' => [
                $scrollId
            ]
        ]));
    }
}
