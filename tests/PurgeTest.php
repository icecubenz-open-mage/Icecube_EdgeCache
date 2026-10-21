<?php

declare(strict_types=1);

use Icecube\EdgeCache\Tests\Support\Catalogue;
use Icecube\EdgeCache\Tests\Support\RecordingAdapter;
use Maho\Queue\HandlerRegistry;
use Maho\Queue\QueueManager;
use Symfony\Component\Messenger\Envelope;

beforeEach(function (): void {
    // Before enabling, so creating the fixtures on a fresh install buffers no purges.
    Catalogue::ensure();
    currentStore()->setConfig(Icecube_EdgeCache_Helper_Data::XML_PATH_ENABLED, '1');
    RecordingAdapter::install();
    deleteQueuedPurges();
    // As a storefront or admin request does, so the buffer holds purges until the response is sent.
    Mage::getSingleton('edgecache/purgeBuffer')->holdUntilResponse();
});

afterEach(function (): void {
    deleteQueuedPurges();
});

function queueTable(): string
{
    return Mage::getSingleton('core/resource')->getTableName('queue/message');
}

function queueConnection(): Maho\Db\Adapter\AdapterInterface
{
    $connection = Mage::getSingleton('core/resource')->getConnection('core_write');
    if ($connection === false) {
        throw new RuntimeException('No database connection.');
    }

    return $connection;
}

function deleteQueuedPurges(): void
{
    queueConnection()->delete(queueTable(), ['queue = ?' => Icecube_EdgeCache_Model_PurgeBuffer::QUEUE]);
}

/**
 * Decoded the way a queue worker decodes them, which also proves the message classes are
 * on the serializer's allowlist (they are, once a #[MessageHandler] handles them).
 *
 * @return list<Envelope>
 */
function queuedPurges(): array
{
    $connection = queueConnection();
    $rows = $connection->fetchAll(
        $connection->select()
            ->from(queueTable(), ['message_class', 'body'])
            ->where('queue = ?', Icecube_EdgeCache_Model_PurgeBuffer::QUEUE)
            ->order('message_id ASC'),
    );

    $envelopes = [];
    foreach ($rows as $row) {
        if (!is_array($row) || !is_string($row['body'] ?? null) || !is_string($row['message_class'] ?? null)) {
            throw new UnexpectedValueException('Unexpected queue row.');
        }
        $envelopes[] = QueueManager::serializer()->decode([
            'body' => $row['body'],
            'headers' => ['type' => $row['message_class']],
        ]);
    }

    return $envelopes;
}

/**
 * Runs the compiled handlers for a message, as the worker does.
 */
function handle(Envelope $envelope): void
{
    foreach (HandlerRegistry::handlersLocator()->getHandlers($envelope) as $descriptor) {
        $descriptor->getHandler()($envelope->getMessage());
    }
}

it('queues a purge of the product tag when a product is saved', function (): void {
    $ids = Catalogue::ensure();
    $product = Mage::getModel('catalog/product')->load($ids['product']);

    $product->setPrice(12.5)->save();

    $buffered = Mage::getSingleton('edgecache/purgeBuffer')->getTags();
    // p_all because a product save cleans the bare catalog_product tag. Its anchor categories
    // are cleaned by id only, so c_all (every page with the top menu) stays.
    expect($buffered)->toContain('p' . $ids['product'], 'p_all', 'c' . $ids['category'])
        ->and(in_array('c_all', $buffered, true))->toBeFalse();

    Mage::getSingleton('edgecache/purgeBuffer')->flush();
    $tags = [];
    foreach (queuedPurges() as $envelope) {
        $message = $envelope->getMessage();
        expect($message)->toBeInstanceOf(Icecube_EdgeCache_Model_PurgeTagsMessage::class);
        if ($message instanceof Icecube_EdgeCache_Model_PurgeTagsMessage) {
            expect(count($message->tags))->toBeLessThanOrEqual(2);
            $tags = [...$tags, ...$message->tags];
        }
    }

    expect($tags)->toBe($buffered)
        ->and(Mage::getSingleton('edgecache/purgeBuffer')->getTags())->toBe([]);
});

it('hands each queued purge to the configured adapter', function (): void {
    Mage::getSingleton('edgecache/purgeBuffer')->addCacheTags(['catalog_product', 'catalog_product_7', 'cms_page_3']);
    Mage::getSingleton('edgecache/purgeBuffer')->flush();

    foreach (queuedPurges() as $envelope) {
        handle($envelope);
    }

    expect(RecordingAdapter::$calls)->toBe([
        ['purgeTags', ['p_all', 'p7']],
        ['purgeTags', ['cp3']],
    ]);
});

it('queues one purge-all however many times it is asked for', function (): void {
    Mage::dispatchEvent('adminhtml_cache_flush_all');
    Mage::getSingleton('edgecache/purgeBuffer')->addCacheTags(['catalog_product_7']);
    Mage::getSingleton('edgecache/purgeBuffer')->flush();
    Mage::dispatchEvent('adminhtml_cache_flush_system');
    Mage::getSingleton('edgecache/purgeBuffer')->flush();

    $queued = queuedPurges();
    expect($queued)->toHaveCount(1)
        ->and($queued[0]->getMessage())->toBeInstanceOf(Icecube_EdgeCache_Model_PurgeAllMessage::class);

    handle($queued[0]);
    expect(RecordingAdapter::$calls)->toBe([['purgeAll', []]]);
});

it('queues a purge straight away outside a web request', function (): void {
    // A queue worker or cron run: nothing sends a response, and the process may live for an hour.
    $buffer = new Icecube_EdgeCache_Model_PurgeBuffer();

    $buffer->addCacheTags(['catalog_product_7']);

    expect(queuedPurges())->toHaveCount(1)
        ->and($buffer->getTags())->toBe([]);
});

it('keeps purges it could not queue, without throwing after the response', function (): void {
    Mage::getSingleton('edgecache/purgeBuffer')->addCacheTags(['catalog_product_7']);
    RecordingAdapter::$failing = true;

    Mage::dispatchEvent('controller_front_send_response_after');

    expect(Mage::getSingleton('edgecache/purgeBuffer')->getTags())->toBe(['p7'])
        ->and(queuedPurges())->toBe([]);

    RecordingAdapter::$failing = false;
    Mage::getSingleton('edgecache/purgeBuffer')->flush();

    expect(queuedPurges())->toHaveCount(1);
});

it('queues another purge-all behind one that is already running', function (): void {
    Mage::getSingleton('edgecache/purgeBuffer')->addAll();
    Mage::getSingleton('edgecache/purgeBuffer')->flush();
    // A worker has claimed it and may already have called the CDN, so a change made now needs its own purge.
    queueConnection()->update(queueTable(), [
        'status' => Maho\Queue\Transport\DbTransport::STATUS_PROCESSING,
        'claimed_at' => Mage_Core_Model_Locale::nowUtc(),
    ], ['queue = ?' => Icecube_EdgeCache_Model_PurgeBuffer::QUEUE]);

    Mage::getSingleton('edgecache/purgeBuffer')->addAll();
    Mage::getSingleton('edgecache/purgeBuffer')->flush();

    expect(queuedPurges())->toHaveCount(2);
});

it('ignores cache cleans that carry no edge tags', function (): void {
    Mage::app()->cleanCache(['config', 'block_html']);

    expect(Mage::getSingleton('edgecache/purgeBuffer')->getTags())->toBe([]);
});

it('purges the product and the categories listing it when it goes out of stock', function (): void {
    $ids = Catalogue::ensure();
    $stockItem = Mage::getModel('cataloginventory/stock_item')->loadByProduct($ids['product']);

    try {
        $stockItem->setQty(0)->save();

        expect(Mage::getSingleton('edgecache/purgeBuffer')->getTags())->toBe(['p' . $ids['product'], 'c' . $ids['category']]);
    } finally {
        $stockItem->setQty(100)->setIsInStock(1)->save();
    }
});

it('does not purge a stock save that leaves the product in stock', function (): void {
    $ids = Catalogue::ensure();

    Mage::getModel('cataloginventory/stock_item')->loadByProduct($ids['product'])->setQty(99)->save();

    expect(Mage::getSingleton('edgecache/purgeBuffer')->getTags())->toBe([]);
});

it('purges the reviewed product', function (): void {
    $ids = Catalogue::ensure();
    $review = Mage::getModel('review/review');

    $review->setEntityId((int) $review->getEntityIdByCode(Mage_Review_Model_Review::ENTITY_PRODUCT_CODE))
        ->setEntityPkValue($ids['product'])
        ->setStatusId(Mage_Review_Model_Review::STATUS_APPROVED)
        ->setTitle('Edge')
        ->setDetail('Cached')
        ->setNickname('Tester')
        ->setStoreId(1)
        ->setStores([1])
        ->save();

    expect(Mage::getSingleton('edgecache/purgeBuffer')->getTags())->toContain('p' . $ids['product']);
});

it('purges everything when catalogue rules are applied or store config is saved', function (string $event): void {
    Mage::dispatchEvent($event);

    expect(Mage::getSingleton('edgecache/purgeBuffer')->isPurgingAll())->toBeTrue();
})->with(['catalogrule_before_apply', 'admin_system_config_section_save_after']);

it('purges the products an import touched', function (): void {
    $adapter = new class extends Mage_ImportExport_Model_Import_Entity_Product {
        public function __construct() {}

        /** @return list<int|string> */
        #[\Override]
        public function getAffectedEntityIds(): array
        {
            return [3, '4'];
        }
    };

    Mage::dispatchEvent('catalog_product_import_finish_before', ['adapter' => $adapter]);

    expect(Mage::getSingleton('edgecache/purgeBuffer')->getTags())->toBe(['p_all', 'p3', 'p4']);
});

it('purges from admin when only a store view has the module enabled', function (): void {
    Mage::app()->getStore(0)?->setConfig(Icecube_EdgeCache_Helper_Data::XML_PATH_ENABLED, '0');
    Mage::app()->setCurrentStore('admin');

    Mage::app()->cleanCache(['catalog_product_7']);

    expect(Mage::getSingleton('edgecache/purgeBuffer')->getTags())->toBe(['p7']);
});

it('buffers nothing while the module is disabled', function (): void {
    currentStore()->setConfig(Icecube_EdgeCache_Helper_Data::XML_PATH_ENABLED, '0');

    Mage::app()->cleanCache(['catalog_product_7']);

    expect(Mage::getSingleton('edgecache/purgeBuffer')->getTags())->toBe([]);
});
