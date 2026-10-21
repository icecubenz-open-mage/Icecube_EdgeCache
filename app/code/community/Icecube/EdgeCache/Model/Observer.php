<?php

/**
 * SPDX-FileCopyrightText: 2026 Icecube
 * SPDX-License-Identifier: OSL-3.0
 * @package Icecube_EdgeCache
 */

declare(strict_types=1);

class Icecube_EdgeCache_Model_Observer
{
    #[Maho\Config\Observer('controller_action_predispatch_session_start', id: 'edgecache_prepare_session')]
    public function prepareSession(\Maho\Event\Observer $observer): void
    {
        $action = $observer->getEvent()->getData('controller_action');
        if ($action instanceof Mage_Core_Controller_Varien_Action) {
            Mage::getSingleton('edgecache/policy')->prepareSession($action);
        }
    }

    /**
     * After ContentNegotiation and every other controller_front_send_response_before observer.
     */
    #[Maho\Config\Observer('http_response_send_before', id: 'edgecache_apply_policy')]
    public function applyPolicy(\Maho\Event\Observer $observer): void
    {
        $response = $observer->getEvent()->getData('response');
        if ($response instanceof Mage_Core_Controller_Response_Http) {
            Mage::getSingleton('edgecache/policy')->applyToResponse($response);
        }
    }

    /**
     * Lets edgecache.js on a cached page tell whether this visitor has a session worth hydrating.
     */
    #[Maho\Config\Observer('session_before_renew_cookie', id: 'edgecache_set_marker_cookie')]
    public function setMarkerCookie(\Maho\Event\Observer $observer): void
    {
        $event = $observer->getEvent();
        $cookie = $event->getData('cookie');
        if (!$cookie instanceof Mage_Core_Model_Cookie
            || $event->getData('session_name') !== Mage_Core_Controller_Front_Action::SESSION_NAMESPACE
            || !Mage::helper('edgecache')->isEnabled()
        ) {
            return;
        }

        $cookie->set(Icecube_EdgeCache_Helper_Data::MARKER_COOKIE, '1', null, null, null, null, false);
    }

    #[Maho\Config\Observer('model_load_after', id: 'edgecache_collect_model_tags')]
    public function collectModelTags(\Maho\Event\Observer $observer): void
    {
        $model = $observer->getEvent()->getData('object');
        if ($model instanceof Mage_Core_Model_Abstract && $this->isCollecting()) {
            Mage::getSingleton('edgecache/tagCollector')->collectModel($model);
        }
    }

    #[Maho\Config\Observer('catalog_product_collection_load_after', id: 'edgecache_collect_product_tags')]
    #[Maho\Config\Observer('core_collection_abstract_load_after', id: 'edgecache_collect_collection_tags')]
    public function collectCollectionTags(\Maho\Event\Observer $observer): void
    {
        $collection = $observer->getEvent()->getData('collection');
        if ($collection instanceof Maho\Data\Collection && $this->isCollecting()) {
            Mage::getSingleton('edgecache/tagCollector')->collectItems($collection);
        }
    }

    /**
     * Only blocks with a cache lifetime: getCacheTags() reads the cache, which is wasted on the rest.
     */
    #[Maho\Config\Observer('core_block_abstract_to_html_after', id: 'edgecache_collect_block_tags')]
    public function collectBlockTags(\Maho\Event\Observer $observer): void
    {
        $block = $observer->getEvent()->getData('block');
        if (!$block instanceof Mage_Core_Block_Abstract || $block->getCacheLifetime() === null || !$this->isCollecting()) {
            return;
        }

        $tags = $block->getCacheTags();
        if (is_array($tags)) {
            Mage::getSingleton('edgecache/tagCollector')->collectBlock($tags);
        }
    }

    /**
     * EAV collections other than products fire no after-load event (blog posts, for one),
     * so a page listing them is tagged with their type instead of each item.
     */
    #[Maho\Config\Observer('eav_collection_abstract_load_before', id: 'edgecache_collect_listing_tags')]
    public function collectListingTags(\Maho\Event\Observer $observer): void
    {
        $collection = $observer->getEvent()->getData('collection');
        if (!$collection instanceof Mage_Eav_Model_Entity_Collection_Abstract
            || $collection instanceof Mage_Catalog_Model_Resource_Collection_Abstract
            || !$this->isCollecting()
        ) {
            return;
        }

        $item = $collection->getNewEmptyItem();
        if ($item instanceof Mage_Core_Model_Abstract) {
            Mage::getSingleton('edgecache/tagCollector')->collectType($item);
        }
    }

    #[Maho\Config\Observer('application_clean_cache', id: 'edgecache_purge_cleaned_tags')]
    public function purgeCleanedTags(\Maho\Event\Observer $observer): void
    {
        $tags = $observer->getEvent()->getData('tags');
        if (is_array($tags) && Mage::helper('edgecache')->isEnabledForAnyStore()) {
            Mage::getSingleton('edgecache/purgeBuffer')->addCacheTags($tags);
        }
    }

    /**
     * Stock moves from orders skip cleanModelCache, but an item going in or out of stock changes the page.
     */
    #[Maho\Config\Observer('cataloginventory_stock_item_save_after', id: 'edgecache_purge_stock_change')]
    public function purgeStockChange(\Maho\Event\Observer $observer): void
    {
        $item = $observer->getEvent()->getData('item');
        if (!$item instanceof Mage_CatalogInventory_Model_Stock_Item
            || !$item->dataHasChangedFor('is_in_stock')
            || !Mage::helper('edgecache')->isEnabledForAnyStore()
        ) {
            return;
        }

        // The categories too: a listing hides or shows the product as it goes in or out of stock.
        $product = Mage::getModel('catalog/product')->setId((int) $item->getProductId());
        $tags = [Mage_Catalog_Model_Product::CACHE_TAG . '_' . (int) $product->getId()];
        foreach (Mage::getResourceSingleton('catalog/product')->getCategoryIdsWithAnchors($product) as $categoryId) {
            if (is_numeric($categoryId)) {
                $tags[] = Mage_Catalog_Model_Category::CACHE_TAG . '_' . (int) $categoryId;
            }
        }
        Mage::getSingleton('edgecache/purgeBuffer')->addCacheTags($tags);
    }

    /**
     * Imports write straight to the tables and clean no cache tags.
     */
    #[Maho\Config\Observer('catalog_product_import_finish_before', id: 'edgecache_purge_imported_products')]
    public function purgeImportedProducts(\Maho\Event\Observer $observer): void
    {
        $adapter = $observer->getEvent()->getData('adapter');
        if (!$adapter instanceof Mage_ImportExport_Model_Import_Entity_Product || !Mage::helper('edgecache')->isEnabledForAnyStore()) {
            return;
        }

        $tags = [Mage_Catalog_Model_Product::CACHE_TAG];
        foreach ($adapter->getAffectedEntityIds() as $productId) {
            if (is_numeric($productId)) {
                $tags[] = Mage_Catalog_Model_Product::CACHE_TAG . '_' . (int) $productId;
            }
        }
        Mage::getSingleton('edgecache/purgeBuffer')->addCacheTags($tags);
    }

    #[Maho\Config\Observer('review_save_after', id: 'edgecache_purge_review_save')]
    #[Maho\Config\Observer('review_delete_after', id: 'edgecache_purge_review_delete')]
    public function purgeReviewedProduct(\Maho\Event\Observer $observer): void
    {
        $review = $observer->getEvent()->getData('object');
        if (!$review instanceof Mage_Review_Model_Review || !Mage::helper('edgecache')->isEnabledForAnyStore()) {
            return;
        }

        $productEntityId = $review->getEntityIdByCode(Mage_Review_Model_Review::ENTITY_PRODUCT_CODE);
        if ((int) $review->getEntityId() === (int) $productEntityId) {
            Mage::getSingleton('edgecache/purgeBuffer')->addCacheTags([Mage_Catalog_Model_Product::CACHE_TAG . '_' . (int) $review->getEntityPkValue()]);
        }
    }

    /**
     * Rule, currency and store config changes touch pages no tag points to: prices, the header
     * and footer, contact details. Config saves clean only Maho's config and layout tags.
     */
    #[Maho\Config\Observer('admin_system_config_section_save_after', id: 'edgecache_purge_all_on_config')]
    #[Maho\Config\Observer('adminhtml_cache_flush_all', id: 'edgecache_purge_all_on_flush')]
    #[Maho\Config\Observer('adminhtml_cache_flush_system', id: 'edgecache_purge_all_on_system_flush')]
    #[Maho\Config\Observer('catalogrule_before_apply', id: 'edgecache_purge_all_on_rules')]
    #[Maho\Config\Observer('directory_currency_rates_save_after', id: 'edgecache_purge_all_on_rates')]
    public function purgeAll(\Maho\Event\Observer $observer): void
    {
        if (Mage::helper('edgecache')->isEnabledForAnyStore()) {
            Mage::getSingleton('edgecache/purgeBuffer')->addAll();
        }
    }

    #[Maho\Config\Observer('controller_front_init_before', id: 'edgecache_hold_purges')]
    public function holdPurges(\Maho\Event\Observer $observer): void
    {
        Mage::getSingleton('edgecache/purgeBuffer')->holdUntilResponse();
    }

    #[Maho\Config\Observer('controller_front_send_response_after', id: 'edgecache_flush_purges')]
    public function flushPurges(\Maho\Event\Observer $observer): void
    {
        Mage::getSingleton('edgecache/purgeBuffer')->flushQuietly();
    }

    private function isCollecting(): bool
    {
        return Mage::getSingleton('edgecache/policy')->isSessionless();
    }
}
