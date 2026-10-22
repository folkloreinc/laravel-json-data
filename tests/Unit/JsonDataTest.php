<?php

namespace Folklore\Tests\Unit\Eloquent;

use Folklore\Eloquent\Exceptions\InvalidJsonReference;
use Folklore\Eloquent\JsonData;
use Folklore\Eloquent\JsonReference;
use Folklore\Tests\Unit\Eloquent\Fixtures\ApplicationMedia;
use Folklore\Tests\Unit\Eloquent\Fixtures\MediaHandler;
use Folklore\Tests\Unit\Eloquent\Fixtures\ReferencedMedia;
use Folklore\Tests\Unit\Eloquent\Fixtures\ReferencedPrize;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;

class JsonDataTest extends TestCase
{
    protected function tearDown(): void
    {
        JsonData::flush();

        parent::tearDown();
    }

    private function mapMediaAndPrizes(): void
    {
        JsonData::map([
            'media' => JsonReference::to(ReferencedMedia::class)->by('uid')->using(MediaHandler::class),
            'medias' => JsonReference::to(ReferencedMedia::class)->using(MediaHandler::class),
            'prize' => [ReferencedPrize::class, 'handle'],
        ]);
    }

    public function test_keys_map_to_targets()
    {
        $this->mapMediaAndPrizes();

        $this->assertSame(['media', 'medias', 'prize'], array_keys(JsonData::keys()));
        $this->assertSame('uid', JsonData::target('media')?->column());
        $this->assertSame('id', JsonData::target('medias')?->column());
        $this->assertSame('handle', JsonData::target('prize')?->column());
        $this->assertNull(JsonData::target('blocks'));
    }

    public function test_later_calls_add_keys()
    {
        JsonData::map(['media' => [ReferencedMedia::class, 'uid']]);
        JsonData::map(['prize' => ReferencedPrize::class]);

        $this->assertSame(['media', 'prize'], array_keys(JsonData::keys()));
    }

    public function test_mapping_a_key_again_to_the_same_target_does_nothing()
    {
        JsonData::map(['media' => [ReferencedMedia::class, 'uid']]);
        JsonData::map(['media' => JsonReference::to(ReferencedMedia::class)->by('uid')]);

        $this->assertSame(['media'], array_keys(JsonData::keys()));
    }

    public function test_mapping_a_key_to_another_target_throws()
    {
        JsonData::map(['media' => [ReferencedMedia::class, 'uid']]);

        $this->expectException(InvalidJsonReference::class);
        $this->expectExceptionMessage('[media] is already mapped');

        JsonData::map(['media' => ReferencedMedia::class]);
    }

    public function test_a_conflict_maps_none_of_the_keys_of_the_call()
    {
        JsonData::map(['medias' => ReferencedPrize::class, 'media' => [ReferencedMedia::class, 'uid']]);

        try {
            JsonData::map(['prize' => ReferencedPrize::class, 'media' => ReferencedMedia::class]);
            $this->fail('The conflict on [media] was accepted.');
        } catch (InvalidJsonReference) {
        }

        $this->assertSame(['medias', 'media'], array_keys(JsonData::keys()));
        $this->assertSame('uid', JsonData::target('media')?->column());
    }

    public function test_keys_must_be_valid()
    {
        foreach (['', '1media', 'media item', 'media:', 'médias'] as $key) {
            try {
                JsonData::map([$key => ReferencedMedia::class]);
                $this->fail("The key [{$key}] was accepted.");
            } catch (InvalidJsonReference $exception) {
                $this->assertStringContainsString('not a valid JSON reference key', $exception->getMessage());
            }
        }

        JsonData::map(['relatedMedias' => ReferencedMedia::class, 'og-image_2' => ReferencedMedia::class]);
        $this->assertSame(['relatedMedias', 'og-image_2'], array_keys(JsonData::keys()));
    }

    public function test_keys_of_a_model_are_in_the_order_they_were_mapped()
    {
        $this->mapMediaAndPrizes();

        $this->assertSame(['media', 'medias'], JsonData::keysFor(ReferencedMedia::class));
        $this->assertSame(['media', 'medias'], JsonData::keysFor(new ApplicationMedia));
        $this->assertSame('media', JsonData::keyFor(ReferencedMedia::class));
        $this->assertSame('prize', JsonData::keyFor(new ReferencedPrize));
        $this->assertSame([], JsonData::keysFor(Model::class));
        $this->assertNull(JsonData::keyFor(Model::class));
    }

    public function test_a_mapped_reference_is_parsed_with_its_target()
    {
        $this->mapMediaAndPrizes();

        $entry = JsonData::parse('media://med_01JAB3');

        $this->assertNotNull($entry);
        $this->assertSame('media', $entry->key);
        $this->assertSame('med_01JAB3', $entry->identifier);
        $this->assertTrue($entry->isMapped());
        $this->assertSame(ReferencedMedia::class, $entry->target?->model);
        $this->assertSame('media://med_01JAB3', (string) $entry);
        $this->assertNull($entry->attribute);
        $this->assertNull($entry->path);
        $this->assertNull($entry->relation);
    }

    public function test_an_unmapped_key_falls_back_without_a_target()
    {
        $this->mapMediaAndPrizes();

        $entry = JsonData::parse('blocks://12');
        $this->assertNotNull($entry);
        $this->assertSame('blocks', $entry->key);
        $this->assertFalse($entry->isMapped());

        // 1.x keys are relation names, which may be in camel case
        $this->assertSame('relatedPages', JsonData::parse('relatedPages://3')?->key);
    }

    public function test_under_enforce_map_an_unmapped_key_is_not_a_reference()
    {
        JsonData::enforceMap(['media' => [ReferencedMedia::class, 'uid']]);

        $this->assertTrue(JsonData::enforcesMap());
        $this->assertNotNull(JsonData::parse('media://med_01JAB3'));
        $this->assertNull(JsonData::parse('blocks://12'));
        $this->assertNull(JsonData::parse('https://example.com/image.jpg'));
    }

    public function test_enforce_map_keeps_the_keys_already_mapped()
    {
        JsonData::map(['prize' => [ReferencedPrize::class, 'handle']]);
        JsonData::enforceMap();

        $this->assertSame('grand-prize', JsonData::parse('prize://grand-prize')?->identifier);
    }

    public function test_other_strings_are_not_references()
    {
        foreach (['', '12', 'medias:/12', 'medias://', '://12', 'medias:// 12', 'medias://12 13', '1media://12', 'med ias://12'] as $value) {
            $this->assertNull(JsonData::parse($value), "[{$value}] was parsed as a reference.");
        }
    }

    public function test_the_reference_of_a_model_uses_its_first_key()
    {
        $this->mapMediaAndPrizes();
        $media = new ApplicationMedia(['id' => 12, 'uid' => 'med_01JAB3']);

        $this->assertSame('media://med_01JAB3', JsonData::reference($media));
        $this->assertSame('medias://12', JsonData::reference($media, 'medias'));
        $this->assertSame('prize://grand-prize', JsonData::reference(new ReferencedPrize(['handle' => 'grand-prize'])));
    }

    public function test_the_reference_of_a_model_needs_a_key_for_it_and_an_identifier()
    {
        $this->mapMediaAndPrizes();
        $media = new ReferencedMedia(['id' => 12]);

        $this->assertThrowsMessage(fn () => JsonData::reference($media), 'has no identifier for the JSON reference key [media]');
        $this->assertThrowsMessage(fn () => JsonData::reference($media, 'prize'), 'is not mapped to');
        $this->assertThrowsMessage(fn () => JsonData::reference($media, 'blocks'), 'The JSON reference key [blocks] is not mapped');

        JsonData::flush();
        $this->assertThrowsMessage(fn () => JsonData::reference($media), 'No JSON reference key is mapped to');
    }

    public function test_flush_forgets_keys_and_enforcement()
    {
        JsonData::enforceMap(['media' => ReferencedMedia::class]);
        JsonData::flush();

        $this->assertSame([], JsonData::keys());
        $this->assertFalse(JsonData::enforcesMap());
    }

    private function assertThrowsMessage(callable $callback, string $message): void
    {
        try {
            $callback();
            $this->fail("No exception was thrown, expected [{$message}].");
        } catch (InvalidJsonReference $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }
}
