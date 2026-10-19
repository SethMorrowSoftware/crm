<?php

declare(strict_types=1);

/**
 * The template editor's two tag rules: what a template may use, and which of
 * the tags in a draft will come out blank.
 */

use App\Controllers\TemplateController;
use Tests\Assert;
use Tests\Harness;

Harness::suite('unit/template-tags');

Harness::test('a template may use its own tags and the venue\'s', function (): void {
    $tags = TemplateController::tagsFor(['merge_tags' => json_encode(['first_name', 'party_date'])]);

    foreach (['first_name', 'party_date', 'venue_name', 'venue_phone', 'venue_address'] as $tag) {
        Assert::inArray($tag, $tags);
    }

    Assert::same(['venue_name', 'venue_phone', 'venue_address'], TemplateController::tagsFor(['merge_tags' => null]));
});

Harness::test('a mistyped tag is named, a known one is not, whatever the spacing or case', function (): void {
    $known = ['first_name', 'party_date', 'venue_name'];

    Assert::same(['frist_name'], TemplateController::unknownTags('Hi {{frist_name}}, see you {{ party_date }}', $known));
    Assert::same([], TemplateController::unknownTags('Hi {{ First_Name }} at {{venue_name}}', $known));
    Assert::same([], TemplateController::unknownTags('No tags at all.', $known));
    Assert::same(['child_name'], TemplateController::unknownTags('{{child_name}} {{child_name}}', $known), 'once, not twice');
});

Harness::test('every sample a preview uses is a plain string', function (): void {
    foreach (TemplateController::SAMPLES as $tag => $sample) {
        Assert::true(is_string($sample) && $sample !== '', $tag . ' needs a sample');
    }
});
