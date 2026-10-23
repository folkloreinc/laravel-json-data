<?php

namespace Folklore\Tests\Unit\Eloquent;

use Folklore\Eloquent\Exceptions\InvalidJsonReference;
use Folklore\Eloquent\JsonReference;
use Folklore\Tests\Unit\Eloquent\Fixtures\ApplicationMedia;
use Folklore\Tests\Unit\Eloquent\Fixtures\MediaHandler;
use Folklore\Tests\Unit\Eloquent\Fixtures\ReferencedMedia;
use Folklore\Tests\Unit\Eloquent\Fixtures\ReferencedPrize;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use stdClass;

#[Group('core')]
class JsonReferenceTest extends TestCase
{
    public function test_a_target_is_identified_by_the_model_key_by_default()
    {
        $target = JsonReference::to(ReferencedMedia::class);

        $this->assertSame(ReferencedMedia::class, $target->model);
        $this->assertNull($target->column);
        $this->assertSame('id', $target->column());
        $this->assertNull($target->handler);
    }

    public function test_a_target_can_have_a_column_and_a_handler()
    {
        $target = JsonReference::to(ReferencedMedia::class)->by('uid')->using(MediaHandler::class);

        $this->assertSame('uid', $target->column());
        $this->assertSame(MediaHandler::class, $target->handler);
    }

    public function test_by_and_using_return_copies()
    {
        $target = JsonReference::to(ReferencedMedia::class);
        $target->by('uid')->using(MediaHandler::class);

        $this->assertSame('id', $target->column());
        $this->assertNull($target->handler);
    }

    public function test_a_target_must_be_a_model()
    {
        $this->expectException(InvalidJsonReference::class);

        JsonReference::to(stdClass::class);
    }

    public function test_a_handler_must_implement_the_contract()
    {
        $this->expectException(InvalidJsonReference::class);

        JsonReference::to(ReferencedMedia::class)->using(stdClass::class);
    }

    public function test_a_target_is_read_from_a_class_a_pair_or_a_reference()
    {
        $this->assertSame('id', JsonReference::from('blocks', ReferencedMedia::class)->column());
        $this->assertSame('handle', JsonReference::from('prize', [ReferencedPrize::class, 'handle'])->column());

        $target = JsonReference::to(ReferencedMedia::class)->by('uid');
        $this->assertSame($target, JsonReference::from('media', $target));
    }

    public function test_an_invalid_definition_is_rejected()
    {
        foreach ([stdClass::class, [ReferencedPrize::class], [ReferencedPrize::class, ''], ['handle' => 'x'], 12, null] as $definition) {
            try {
                JsonReference::from('prize', $definition);
                $this->fail('A definition was accepted: '.json_encode($definition));
            } catch (InvalidJsonReference $exception) {
                $this->assertStringContainsString('[prize]', $exception->getMessage());
            }
        }
    }

    public function test_a_target_accepts_its_model_and_the_models_that_extend_it()
    {
        $target = JsonReference::to(ReferencedMedia::class);

        $this->assertTrue($target->accepts(ReferencedMedia::class));
        $this->assertTrue($target->accepts(new ApplicationMedia));
        $this->assertFalse($target->accepts(ReferencedPrize::class));
        $this->assertFalse(JsonReference::to(ApplicationMedia::class)->accepts(ReferencedMedia::class));
    }

    public function test_the_identifier_of_a_model_is_its_column()
    {
        $media = new ReferencedMedia(['id' => 12, 'uid' => 'med_01J']);

        $this->assertSame('12', JsonReference::to(ReferencedMedia::class)->identifierOf($media));
        $this->assertSame('med_01J', JsonReference::to(ReferencedMedia::class)->by('uid')->identifierOf($media));
        $this->assertNull(JsonReference::to(ReferencedMedia::class)->by('uid')->identifierOf(new ReferencedMedia));
        $this->assertNull(JsonReference::to(ReferencedMedia::class)->by('uid')->identifierOf(new ReferencedMedia(['uid' => ''])));
    }

    public function test_targets_are_equal_by_model_column_and_handler()
    {
        $target = JsonReference::to(ReferencedMedia::class);

        $this->assertTrue($target->equals(JsonReference::to(ReferencedMedia::class)->by('id')));
        $this->assertFalse($target->equals($target->by('uid')));
        $this->assertFalse($target->equals($target->using(MediaHandler::class)));
        $this->assertFalse($target->equals(JsonReference::to(ApplicationMedia::class)));
    }
}
