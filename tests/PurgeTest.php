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
    currentStore()->setConfig(Icecube_EdgeCache_Helper_Data::XML_PATH_PURGE_DELAY, '0');
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
function queuedPurges(?string $status = null): array
{
    $connection = queueConnection();
    $select = $connection->select()
        ->from(queueTable(), ['message_class', 'body'])
        ->where('queue = ?', Icecube_EdgeCache_Model_PurgeBuffer::QUEUE)
        ->order('message_id ASC');
    if ($status !== null) {
        $select->where('status = ?', $status);
    }
    $rows = $connection->fetchAll($select);

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
 * Claims, handles and acks every due edgecache message, as a worker does.
 */
function work(): void
{
    $transport = QueueManager::dbTransport();
    while (($envelopes = [...$transport->getFromQueues([Icecube_EdgeCache_Model_PurgeBuffer::QUEUE])]) !== []) {
        foreach ($envelopes as $envelope) {
            handle($envelope);
            $transport->ack($envelope);
        }
    }
}

/**
 * @return list<object>
 */
function pendingPurges(): array
{
    return array_map(fn(Envelope $envelope): object => $envelope->getMessage(), queuedPurges(Maho\Queue\Transport\DbTransport::STATUS_PENDING));
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

    work();

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
    currentStore()->setConfig(Icecube_EdgeCache_Helper_Data::XML_PATH_ADAPTER, 'unregistered');

    Mage::dispatchEvent('controller_front_send_response_after');

    expect(Mage::getSingleton('edgecache/purgeBuffer')->getTags())->toBe(['p7'])
        ->and(queuedPurges())->toBe([]);

    RecordingAdapter::install();
    Mage::getSingleton('edgecache/purgeBuffer')->flush();

    expect(queuedPurges())->toHaveCount(1);
});

it('holds queued purges back so a burst of saves shares one call', function (): void {
    currentStore()->setConfig(Icecube_EdgeCache_Helper_Data::XML_PATH_PURGE_DELAY, '30');

    Mage::getSingleton('edgecache/purgeBuffer')->addCacheTags(['catalog_product_7']);
    Mage::getSingleton('edgecache/purgeBuffer')->flush();

    $availableAt = queueConnection()->fetchOne(
        queueConnection()->select()->from(queueTable(), 'available_at')->where('queue = ?', Icecube_EdgeCache_Model_PurgeBuffer::QUEUE),
    );
    expect(is_string($availableAt) ? strtotime($availableAt . ' UTC') : false)->toBeGreaterThan(time() + 20);
});

it('merges waiting purges into one call up to the adapter limit', function (): void {
    foreach (['p1', 'p2', 'p3'] as $tag) {
        QueueManager::dispatch(new Icecube_EdgeCache_Model_PurgeTagsMessage([$tag]), queue: Icecube_EdgeCache_Model_PurgeBuffer::QUEUE);
    }
    // Not due yet, but purging it early costs nothing and saves a call.
    QueueManager::dispatch(new Icecube_EdgeCache_Model_PurgeTagsMessage(['p4']), 3600, Icecube_EdgeCache_Model_PurgeBuffer::QUEUE);

    work();

    expect(RecordingAdapter::$calls)->toBe([
        ['purgeTags', ['p1', 'p2']],
        ['purgeTags', ['p3', 'p4']],
    ])->and(pendingPurges())->toBe([]);
});

it('requeues a failed purge with a backoff instead of throwing', function (): void {
    RecordingAdapter::$failing = true;
    QueueManager::dispatch(new Icecube_EdgeCache_Model_PurgeTagsMessage(['p1']), queue: Icecube_EdgeCache_Model_PurgeBuffer::QUEUE);
    QueueManager::dispatch(new Icecube_EdgeCache_Model_PurgeTagsMessage(['p2']), queue: Icecube_EdgeCache_Model_PurgeBuffer::QUEUE);

    work();

    expect(pendingPurges())->toEqual([new Icecube_EdgeCache_Model_PurgeTagsMessage(['p1', 'p2'], 2)])
        ->and(queuedPurges(Maho\Queue\Transport\DbTransport::STATUS_FAILED))->toBe([]);
});

it('leaves a retry alone until it is due, so new purges do not cut its backoff short', function (): void {
    QueueManager::dispatch(new Icecube_EdgeCache_Model_PurgeTagsMessage(['p2'], 2), 3600, Icecube_EdgeCache_Model_PurgeBuffer::QUEUE);
    QueueManager::dispatch(new Icecube_EdgeCache_Model_PurgeTagsMessage(['p1']), queue: Icecube_EdgeCache_Model_PurgeBuffer::QUEUE);

    work();

    expect(RecordingAdapter::$calls)->toBe([['purgeTags', ['p1']]])
        ->and(pendingPurges())->toEqual([new Icecube_EdgeCache_Model_PurgeTagsMessage(['p2'], 2)]);
});

it('waits as long as the CDN asks before retrying', function (): void {
    RecordingAdapter::$failing = true;
    RecordingAdapter::$retryAfter = 900;
    QueueManager::dispatch(new Icecube_EdgeCache_Model_PurgeTagsMessage(['p1']), queue: Icecube_EdgeCache_Model_PurgeBuffer::QUEUE);

    work();

    $availableAt = queueConnection()->fetchOne(
        queueConnection()->select()->from(queueTable(), 'available_at')
            ->where('queue = ?', Icecube_EdgeCache_Model_PurgeBuffer::QUEUE)
            ->where('status = ?', Maho\Queue\Transport\DbTransport::STATUS_PENDING),
    );
    expect(is_string($availableAt) ? strtotime($availableAt . ' UTC') : false)->toBeGreaterThan(time() + 800);
});

it('takes over purges a dead worker had merged, but not ones a live worker holds', function (): void {
    $claim = function (string $tag, string $claimedAt): void {
        $envelope = QueueManager::dispatch(new Icecube_EdgeCache_Model_PurgeTagsMessage([$tag]), 3600, Icecube_EdgeCache_Model_PurgeBuffer::QUEUE);
        $id = $envelope->last(Symfony\Component\Messenger\Stamp\TransportMessageIdStamp::class)?->getId();
        expect($id)->toBeNumeric();
        queueConnection()->update(queueTable(), [
            'status' => Maho\Queue\Transport\DbTransport::STATUS_PROCESSING,
            'claimed_at' => $claimedAt,
        ], ['message_id = ?' => is_numeric($id) ? (int) $id : 0]);
    };
    $claim('p2', Maho\Queue\Transport\DbTransport::abandonedBefore(Maho\Queue\Transport\DbTransport::ABANDONED_AFTER_SECONDS + 60));
    $claim('p3', Mage_Core_Model_Locale::nowUtc());
    QueueManager::dispatch(new Icecube_EdgeCache_Model_PurgeTagsMessage(['p1']), queue: Icecube_EdgeCache_Model_PurgeBuffer::QUEUE);

    work();

    expect(RecordingAdapter::$calls)->toBe([['purgeTags', ['p1', 'p2']]])
        ->and(queuedPurges(Maho\Queue\Transport\DbTransport::STATUS_PROCESSING))->toHaveCount(1);
});

it('purges everything once a purge has failed too often', function (): void {
    RecordingAdapter::$failing = true;
    QueueManager::dispatch(
        new Icecube_EdgeCache_Model_PurgeTagsMessage(['p1'], Icecube_EdgeCache_Model_PurgeHandler::MAX_ATTEMPTS),
        queue: Icecube_EdgeCache_Model_PurgeBuffer::QUEUE,
    );

    // One message only: the purge-all it queues would fail against the same CDN.
    $transport = QueueManager::dbTransport();
    foreach ($transport->getFromQueues([Icecube_EdgeCache_Model_PurgeBuffer::QUEUE]) as $envelope) {
        handle($envelope);
        $transport->ack($envelope);
    }

    expect(pendingPurges())->toEqual([new Icecube_EdgeCache_Model_PurgeAllMessage()]);
});

it('drops tag purges a purge-all has made redundant', function (): void {
    QueueManager::dispatch(new Icecube_EdgeCache_Model_PurgeTagsMessage(['p1']), 3600, Icecube_EdgeCache_Model_PurgeBuffer::QUEUE);
    QueueManager::dispatch(new Icecube_EdgeCache_Model_PurgeAllMessage(), queue: Icecube_EdgeCache_Model_PurgeBuffer::QUEUE);

    work();

    expect(RecordingAdapter::$calls)->toBe([['purgeAll', []]])
        ->and(pendingPurges())->toBe([]);
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
