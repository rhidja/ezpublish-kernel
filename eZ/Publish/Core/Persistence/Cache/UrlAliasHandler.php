<?php

/**
 * File containing the UrlAlias Handler implementation.
 *
 * @copyright Copyright (C) eZ Systems AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
namespace eZ\Publish\Core\Persistence\Cache;

use eZ\Publish\API\Repository\Exceptions\NotFoundException as APINotFoundException;
use eZ\Publish\Core\Base\Exceptions\NotFoundException;
use eZ\Publish\SPI\Persistence\Content\UrlAlias\Handler as UrlAliasHandlerInterface;
use eZ\Publish\SPI\Persistence\Content\UrlAlias;

/**
 * @see \eZ\Publish\SPI\Persistence\Content\UrlAlias\Handler
 */
class UrlAliasHandler extends AbstractHandler implements UrlAliasHandlerInterface
{
    /**
     * Constant used for storing not found results for lookup().
     */
    const NOT_FOUND = 0;

    /**
     * {@inheritdoc}
     */
    public function publishUrlAliasForLocation(
        $locationId,
        $parentLocationId,
        $name,
        $languageCode,
        $alwaysAvailable = false,
        $updatePathIdentificationString = false
    ) {
        $this->logger->logCall(
            __METHOD__,
            array(
                'location' => $locationId,
                'parent' => $parentLocationId,
                'name' => $name,
                'language' => $languageCode,
                'alwaysAvailable' => $alwaysAvailable,
            )
        );

        $this->persistenceHandler->urlAliasHandler()->publishUrlAliasForLocation(
            $locationId,
            $parentLocationId,
            $name,
            $languageCode,
            $alwaysAvailable,
            $updatePathIdentificationString
        );

        $this->cache->invalidateTags(['urlAlias-location-' . $locationId, 'urlAlias-location-path-' . $locationId, 'urlAlias-notFound']);
    }

    /**
     * {@inheritdoc}
     */
    public function createCustomUrlAlias($locationId, $path, $forwarding = false, $languageCode = null, $alwaysAvailable = false)
    {
        $this->logger->logCall(
            __METHOD__,
            array(
                'location' => $locationId,
                '$path' => $path,
                '$forwarding' => $forwarding,
                'language' => $languageCode,
                'alwaysAvailable' => $alwaysAvailable,
            )
        );

        $urlAlias = $this->persistenceHandler->urlAliasHandler()->createCustomUrlAlias(
            $locationId,
            $path,
            $forwarding,
            $languageCode,
            $alwaysAvailable
        );

        $this->cache->invalidateTags(['urlAlias-location-' . $locationId, 'urlAlias-location-path-' . $locationId, 'urlAlias-notFound']);

        return $urlAlias;
    }

    /**
     * {@inheritdoc}
     */
    public function createGlobalUrlAlias($resource, $path, $forwarding = false, $languageCode = null, $alwaysAvailable = false)
    {
        $this->logger->logCall(
            __METHOD__,
            array(
                'resource' => $resource,
                'path' => $path,
                'forwarding' => $forwarding,
                'language' => $languageCode,
                'alwaysAvailable' => $alwaysAvailable,
            )
        );

        $urlAlias = $this->persistenceHandler->urlAliasHandler()->createGlobalUrlAlias(
            $resource,
            $path,
            $forwarding,
            $languageCode,
            $alwaysAvailable
        );

        $this->cache->invalidateTags(['urlAlias-notFound']);

        return $urlAlias;
    }

    /**
     * {@inheritdoc}
     */
    public function listGlobalURLAliases($languageCode = null, $offset = 0, $limit = -1)
    {
        $this->logger->logCall(__METHOD__, array('language' => $languageCode, 'offset' => $offset, 'limit' => $limit));

        return $this->persistenceHandler->urlAliasHandler()->listGlobalURLAliases($languageCode, $offset, $limit);
    }

    /**
     * {@inheritdoc}
     */
    public function listURLAliasesForLocation($locationId, $custom = false)
    {
        $cacheItem = $this->cache->getItem('ez-urlAlias-location-list-' . $locationId . ($custom ? '-custom' : ''));
        if ($cacheItem->isHit()) {
            return $cacheItem->get();
        }

        $this->logger->logCall(__METHOD__, array('location' => $locationId, 'custom' => $custom));
        $urlAliases = $this->persistenceHandler->urlAliasHandler()->listURLAliasesForLocation($locationId, $custom);

        $cacheItem->set($urlAliases);
        $cacheTags = ['urlAlias-location-' . $locationId];
        foreach ($urlAliases as $urlAlias) {
            $cacheTags = $this->getCacheTags($urlAlias, $cacheTags);
        }
        $cacheItem->tag($cacheTags);
        $this->cache->save($cacheItem);

        return $urlAliases;
    }

    /**
     * {@inheritdoc}
     */
    public function removeURLAliases(array $urlAliases)
    {
        $this->logger->logCall(__METHOD__, array('aliases' => $urlAliases));
        $return = $this->persistenceHandler->urlAliasHandler()->removeURLAliases($urlAliases);

        $cacheTags = [];
        foreach ($urlAliases as $urlAlias) {
            $cacheTags[] = 'urlAlias-' . $urlAlias->id;
            if ($urlAlias->type === UrlAlias::LOCATION) {
                $cacheTags[] = 'urlAlias-location-' . $urlAlias->destination;
                $cacheTags[] = 'urlAlias-location-path-' . $urlAlias->destination;
            }
            if ($urlAlias->isCustom) {
                $cacheTags[] = 'urlAlias-custom-' . $urlAlias->destination;
            }
        }
        $this->cache->invalidateTags($cacheTags);

        return $return;
    }

    /**
     * {@inheritdoc}
     */
    public function lookup($url)
    {
        $cacheItem = $this->cache->getItem(
            'ez-urlAlias-url-' . str_replace(['/', ':', '(', ')', '@'], ['_S', '_C', '_B', '_B', '_A'], $url)
        );
        if ($cacheItem->isHit()) {
            if (($return = $cacheItem->get()) === self::NOT_FOUND) {
                throw new NotFoundException('UrlAlias', $url);
            }

            return $return;
        }

        $this->logger->logCall(__METHOD__, array('url' => $url));
        try {
            $urlAlias = $this->persistenceHandler->urlAliasHandler()->lookup($url);
        } catch (APINotFoundException $e) {
            $cacheItem->set(self::NOT_FOUND)
                ->expiresAfter(30)
                ->tag(['urlAlias-notFound']);
            $this->cache->save($cacheItem);
            throw $e;
        }

        $cacheItem->set($urlAlias);
        $cacheItem->tag($this->getCacheTags($urlAlias));
        $this->cache->save($cacheItem);

        return $urlAlias;
    }

    /**
     * {@inheritdoc}
     */
    public function loadUrlAlias($id)
    {
        $cacheItem = $this->cache->getItem('ez-urlAlias-' . $id);
        if ($cacheItem->isHit()) {
            return $cacheItem->get();
        }

        $this->logger->logCall(__METHOD__, array('alias' => $id));
        $urlAlias = $this->persistenceHandler->urlAliasHandler()->loadUrlAlias($id);

        $cacheItem->set($urlAlias);
        $cacheItem->tag($this->getCacheTags($urlAlias));
        $this->cache->save($cacheItem);

        return $urlAlias;
    }

    /**
     * {@inheritdoc}
     */
    public function locationMoved($locationId, $oldParentId, $newParentId)
    {
        $this->logger->logCall(
            __METHOD__,
            array(
                'location' => $locationId,
                'oldParent' => $oldParentId,
                'newParent' => $newParentId,
            )
        );

        $return = $this->persistenceHandler->urlAliasHandler()->locationMoved($locationId, $oldParentId, $newParentId);

        $this->cache->invalidateTags(['urlAlias-location-' . $locationId, 'urlAlias-location-path-' . $locationId]);

        return $return;
    }

    /**
     * {@inheritdoc}
     */
    public function locationCopied($locationId, $newLocationId, $newParentId)
    {
        $this->logger->logCall(
            __METHOD__,
            array(
                'oldLocation' => $locationId,
                'newLocation' => $newLocationId,
                'newParent' => $newParentId,
            )
        );

        $return = $this->persistenceHandler->urlAliasHandler()->locationCopied(
            $locationId,
            $newLocationId,
            $newParentId
        );
        $this->cache->invalidateTags(['urlAlias-location-' . $locationId, 'urlAlias-location-' . $newLocationId]);

        return $return;
    }

    /**
     * {@inheritdoc}
     */
    public function locationDeleted($locationId)
    {
        $this->logger->logCall(__METHOD__, array('location' => $locationId));
        $return = $this->persistenceHandler->urlAliasHandler()->locationDeleted($locationId);

        $this->cache->invalidateTags(['urlAlias-location-' . $locationId, 'urlAlias-location-path-' . $locationId]);

        return $return;
    }

    /**
     * {@inheritdoc}
     */
    public function locationSwapped($location1Id, $location1ParentId, $location2Id, $location2ParentId)
    {
        $this->logger->logCall(
            __METHOD__,
            [
                'location1Id' => $location1Id,
                'location1ParentId' => $location1ParentId,
                'location2Id' => $location2Id,
                'location2ParentId' => $location2ParentId,
            ]
        );

        $return = $this->persistenceHandler->urlAliasHandler()->locationSwapped(
            $location1Id,
            $location1ParentId,
            $location2Id,
            $location2ParentId
        );

        $this->cache->invalidateTags(
            [
                'urlAlias-location-' . $location1Id,
                'urlAlias-location-path-' . $location1Id,
                'urlAlias-location-' . $location2Id,
                'urlAlias-location-path-' . $location2Id,
            ]
        );

        return $return;
    }

    /**
     * {@inheritdoc}
     */
    public function translationRemoved(array $locationIds, $languageCode)
    {
        $this->logger->logCall(
            __METHOD__,
            ['locations' => implode(',', $locationIds), 'language' => $languageCode]
        );

        $this->persistenceHandler->urlAliasHandler()->translationRemoved($locationIds, $languageCode);

        $locationTags = [];
        foreach ($locationIds as $locationId) {
            $locationTags[] = 'urlAlias-location-' . $locationId;
            $locationTags[] = 'urlAlias-location-path-' . $locationId;
        }

        $this->cache->invalidateTags($locationTags);
    }

    /**
     * {@inheritdoc}
     */
    public function archiveUrlAliasesForDeletedTranslations($locationId, $parentLocationId, array $languageCodes)
    {
        $this->logger->logCall(
            __METHOD__,
            [
                'locationId' => $locationId,
                'parentLocationId' => $parentLocationId,
                'languageCodes' => implode(',', $languageCodes),
            ]
        );

        $this->persistenceHandler->urlAliasHandler()->archiveUrlAliasesForDeletedTranslations(
            $locationId,
            $parentLocationId,
            $languageCodes
        );

        $this->cache->invalidateTags(['urlAlias-location-' . $locationId, 'urlAlias-location-path-' . $locationId]);
    }

    /**
     * Return relevant UrlAlias and optionally UrlAlias location tags so cache can be purged reliably.
     *
     * For use when generating cache, not on invalidation.
     *
     * @param \eZ\Publish\SPI\Persistence\Content\UrlAlias $urlAlias
     * @param array $tags Optional, can be used to specify other tags.
     *
     * @return array
     */
    private function getCacheTags(UrlAlias $urlAlias, array $tags = [])
    {
        $tags[] = 'urlAlias-' . $urlAlias->id;

        if ($urlAlias->type === UrlAlias::LOCATION) {
            $cacheTags[] = 'urlAlias-location-' . $urlAlias->destination;
            $location = $this->persistenceHandler->locationHandler()->load($urlAlias->destination);
            foreach (explode('/', trim($location->pathString, '/')) as $pathId) {
                $tags[] = 'urlAlias-location-path-' . $pathId;
            }
        }

        return array_unique($tags);
    }

    /**
     * Delete corrupted URL aliases (global, custom and system).
     *
     * @return int Number of deleted URL aliases
     */
    public function deleteCorruptedUrlAliases()
    {
        $this->logger->logCall(__METHOD__);

        $deletedCount = $this->persistenceHandler->urlAliasHandler()->deleteCorruptedUrlAliases();

        $this->cache->clear('urlAlias');

        return $deletedCount;
    }
}
