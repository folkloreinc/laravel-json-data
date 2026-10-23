<?php

namespace Folklore\Tests\Unit\Eloquent;

use Folklore\Eloquent\Exceptions\ReferenceNotAllowed;
use Folklore\Eloquent\JsonReference;
use Folklore\Eloquent\ReferenceContext;
use Folklore\Eloquent\ReferenceOperation;
use Folklore\Tests\Unit\Eloquent\Fixtures\ReferencedMedia;
use Folklore\Tests\Unit\Eloquent\Fixtures\ReferencedPrize;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('core')]
class ReferenceContextTest extends TestCase
{
    public function test_a_context_tells_the_operation()
    {
        $owner = new ReferencedPrize(['id' => 3]);
        $context = new ReferenceContext(
            operation: ReferenceOperation::Write,
            key: 'media',
            target: JsonReference::to(ReferencedMedia::class)->by('uid'),
            ownerClass: ReferencedPrize::class,
            owner: $owner,
            attribute: 'data',
            path: 'image.fr',
            relation: 'medias',
        );

        $this->assertTrue($context->isWrite());
        $this->assertFalse($context->isRead());
        $this->assertSame($owner, $context->owner);
        $this->assertSame('uid', $context->target->column());
    }

    public function test_a_batch_has_no_owner_and_no_path()
    {
        $context = new ReferenceContext(
            operation: ReferenceOperation::Read,
            key: 'media',
            target: JsonReference::to(ReferencedMedia::class)->by('uid'),
            ownerClass: ReferencedPrize::class,
            owner: null,
            attribute: 'data',
        );

        $this->assertTrue($context->isRead());
        $this->assertNull($context->path);
        $this->assertNull($context->relation);
    }

    public function test_a_refusal_names_the_owner_the_model_and_the_path()
    {
        $context = new ReferenceContext(
            operation: ReferenceOperation::Write,
            key: 'medias',
            target: JsonReference::to(ReferencedMedia::class),
            ownerClass: ReferencedPrize::class,
            owner: null,
            attribute: 'images',
            path: 'logo',
        );

        $exception = ReferenceNotAllowed::for(new ReferencedMedia(['id' => 12]), $context);

        $this->assertSame(
            'A ['.ReferencedPrize::class.'] may not refer to ['.ReferencedMedia::class.'] 12 at [images.logo].',
            $exception->getMessage(),
        );
    }
}
