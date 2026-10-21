<?php

declare(strict_types=1);

use Icecube\EdgeCache\Tests\Support\Catalogue;
use Icecube\EdgeCache\Tests\Support\RecordingAdapter;
use Maho\Queue\HandlerRegistry;
use Maho\Queue\QueueManager;
use Symfony\Component\Messenger\Envelope;

beforeEach(function (): void {
    currentStore()->setConfig(Icecube_EdgeCache_Helper_Data::XML_PATH_ENABLED, '1');
    RecordingAdapter::install();
    deleteQueuedPurges();
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
    expect($buffered)->toContain('p' . $ids['product'])->toContain('p_all');

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
    Mage::getSingleton('edgecache/purgeBuffer')->addCacheTags(['catalog_product_7', 'cms_page_3']);
    Mage::getSingleton('edgecache/purgeBuffer')->flush();

    foreach (queuedPurges() as $envelope) {
        handle($envelope);
    }

    expect(RecordingAdapter::$calls)->toBe([
        ['purgeTags', ['p7', 'cp3']],
        ['purgeTags', ['p_all', 'cp_all']],
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

it('ignores cache cleans that carry no edge tags', function (): void {
    Mage::app()->cleanCache(['config', 'block_html']);

    expect(Mage::getSingleton('edgecache/purgeBuffer')->getTags())->toBe([]);
});

it('buffers nothing while the module is disabled', function (): void {
    currentStore()->setConfig(Icecube_EdgeCache_Helper_Data::XML_PATH_ENABLED, '0');

    Mage::app()->cleanCache(['catalog_product_7']);

    expect(Mage::getSingleton('edgecache/purgeBuffer')->getTags())->toBe([]);
});
