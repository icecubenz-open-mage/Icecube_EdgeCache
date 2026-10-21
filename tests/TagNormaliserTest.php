<?php

declare(strict_types=1);

use Icecube_EdgeCache_Model_Adapter_Limits as Limits;
use Icecube_EdgeCache_Model_TagNormaliser as TagNormaliser;

it('shortens entity tags and drops tags with no meaning at the edge', function (): void {
    $tags = new TagNormaliser()->normalise([
        'catalog_product_12',
        'CATALOG_CATEGORY_3',
        'cms_page_2',
        'cms_block_7',
        'blog_post_9',
        'blog_category_4',
        'config',
        'block_html',
        'catalog_product_12',
        'catalog_product_abc',
        42,
    ]);

    expect($tags)->toBe(['p12', 'c3', 'cp2', 'cb7', 'bp9', 'bc4']);
});

it('turns a bare type tag into the coarse tag of that type', function (): void {
    expect(new TagNormaliser()->normalise(['catalog_product', 'blog_post']))->toBe(['p_all', 'bp_all']);
});

it('prefixes every tag so environments sharing a zone stay apart', function (): void {
    $normaliser = new TagNormaliser('Stg-');

    expect($normaliser->normalise(['catalog_product_1', 'catalog_product']))->toBe(['stg-p1', 'stg-p_all']);
});

it('leaves tags alone when they fit', function (): void {
    $tags = ['c3', 'p1', 'p2'];

    expect(new TagNormaliser()->cap($tags, new Limits(maxTagsPerObject: 3)))->toBe($tags);
});

it('swaps the tags over the count limit for their coarse tag, keeping the first ones', function (): void {
    $tags = ['c3', 'p1', 'p2', 'p3', 'p4', 'p5'];

    expect(new TagNormaliser()->cap($tags, new Limits(maxTagsPerObject: 4)))->toBe(['c3', 'p1', 'p_all']);
});

it('keeps the header within its byte limit, separators included', function (): void {
    $tags = array_map(fn(int $id): string => 'p' . $id, range(100, 199));
    $limits = new Limits(tagSeparator: ' ', maxTagHeaderBytes: 50);

    $capped = new TagNormaliser()->cap($tags, $limits);

    expect(strlen(implode(' ', $capped)))->toBeLessThanOrEqual(50)
        ->and($capped)->toContain('p_all')
        ->and($capped[0])->toBe('p100');
});

it('drops a tag longer than the adapter accepts', function (): void {
    $normaliser = new TagNormaliser(str_repeat('x', 10));

    expect($normaliser->cap(['xxxxxxxxxxp1', 'xxxxxxxxxxp123456'], new Limits(maxTagLength: 12)))->toBe(['xxxxxxxxxxp1']);
});
