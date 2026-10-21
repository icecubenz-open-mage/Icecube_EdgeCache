<?php

declare(strict_types=1);

it('takes the ids from a cached block and leaves out the type tag they make redundant', function (): void {
    $collector = new Icecube_EdgeCache_Model_TagCollector();

    $collector->collectBlock(['cms_block', 'cms_block_7', 'block_html']);

    expect($collector->getRawTags())->toBe(['cms_block_7', 'block_html']);
});

it('keeps the type tag of a cached block that depends on the whole type', function (): void {
    $collector = new Icecube_EdgeCache_Model_TagCollector();

    $collector->collectBlock(['catalog_category', 'block_html']);

    expect($collector->getRawTags())->toBe(['catalog_category', 'block_html']);
});
