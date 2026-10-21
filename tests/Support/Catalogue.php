<?php

declare(strict_types=1);

namespace Icecube\EdgeCache\Tests\Support;

use Mage;
use Mage_Catalog_Model_Product_Status;
use Mage_Catalog_Model_Product_Type;
use Mage_Catalog_Model_Product_Visibility;

/**
 * One category, product and CMS page for the pages the edge cache serves. The test install
 * is empty, and every run starts from a fresh one, so they are created on first use.
 */
final class Catalogue
{
    public const SKU = 'edgecache-test';
    public const CMS_PAGE = 'edgecache-test';

    /**
     * @return array{category: int, product: int, page: int}
     */
    public static function ensure(): array
    {
        $productId = (int) Mage::getModel('catalog/product')->getIdBySku(self::SKU);
        if ($productId > 0) {
            return self::ids($productId);
        }

        $store = Mage::app()->getStore(1) ?? throw new \LogicException('Store 1 is missing');
        $root = Mage::getModel('catalog/category')->load((int) $store->getRootCategoryId());

        $category = Mage::getModel('catalog/category');
        $category->setStoreId(0)
            ->setName('Edge Cache Category')
            ->setUrlKey('edgecache-category')
            ->setIsActive(1)
            ->setIsAnchor(1)
            ->setDisplayMode('PRODUCTS')
            ->setPath((string) $root->getPath())
            ->save();

        $product = Mage::getModel('catalog/product');
        $product->setStoreId(0)
            ->setAttributeSetId((int) $product->getDefaultAttributeSetId())
            ->setTypeId(Mage_Catalog_Model_Product_Type::TYPE_SIMPLE)
            ->setSku(self::SKU)
            ->setName('Edge Cache Product')
            ->setUrlKey('edgecache-product')
            ->setPrice(10)
            ->setTaxClassId(0)
            ->setStatus(Mage_Catalog_Model_Product_Status::STATUS_ENABLED)
            ->setVisibility(Mage_Catalog_Model_Product_Visibility::VISIBILITY_BOTH)
            ->setWebsiteIds([1])
            ->setCategoryIds([(int) $category->getId()])
            ->setStockData(['use_config_manage_stock' => 1, 'qty' => 100, 'is_in_stock' => 1])
            ->save();

        Mage::getModel('cms/page')
            ->setTitle('Edge Cache Page')
            ->setIdentifier(self::CMS_PAGE)
            ->setContent('<p>Cached at the edge</p>')
            ->setIsActive(1)
            ->setStores([0])
            ->save();

        Mage::getModel('index/indexer')->getProcessesCollection()->walk('reindexEverything');

        return self::ids((int) $product->getId());
    }

    /**
     * @return array{category: int, product: int, page: int}
     */
    private static function ids(int $productId): array
    {
        $product = Mage::getModel('catalog/product')->load($productId);
        $categoryIds = array_filter($product->getCategoryIds(), is_numeric(...));

        return [
            'category' => (int) reset($categoryIds),
            'product' => $productId,
            'page' => (int) Mage::getModel('cms/page')->load(self::CMS_PAGE, 'identifier')->getId(),
        ];
    }
}
