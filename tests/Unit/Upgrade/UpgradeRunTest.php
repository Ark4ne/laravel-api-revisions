<?php

declare(strict_types=1);

namespace Test\Unit\Upgrade;

use Ark4ne\ApiRevisions\Conversion\Converters;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Upgrade\UpgradeRun;
use Test\TestCase;

final class UpgradeRunTest extends TestCase
{
    public function test_a_run_keeps_its_converters_and_its_declaration_kind(): void
    {
        $converters = new Converters();

        $run = new UpgradeRun($converters, DeclarationKind::RequestQuery);

        self::assertSame($converters, $run->converters);
        self::assertSame(DeclarationKind::RequestQuery, $run->kind);
    }
}
