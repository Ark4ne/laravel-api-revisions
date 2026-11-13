<?php

declare(strict_types=1);

namespace Test\Unit\Checking\Checks;

use Ark4ne\ApiRevisions\Checking\Checks\EagerValue;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\EagerSchema;
use Ark4ne\ApiRevisions\Schema\Schema;
use Test\TestCase;

final class EagerValueTest extends TestCase
{
    use RunsChecks;

    public function test_a_plain_value_in_a_response_is_reported(): void
    {
        self::assertSame(
            [['eager-value', 'error', 'label', null, "'label' (response) is a plain value, typed any and the same in every revision: declare a descriptor."]],
            self::check(new EagerValue(), static fn(): array => [
                'title' => Schema::string(),
                'label' => EagerSchema::of('x'),
            ]),
        );
    }

    public function test_descriptors_and_requests_report_nothing(): void
    {
        self::assertSame([], self::check(new EagerValue(), static fn(): array => [
            'title' => Schema::string(),
            'label' => EagerSchema::of('x'),
        ], [DeclarationKind::RequestBody]));
    }
}
