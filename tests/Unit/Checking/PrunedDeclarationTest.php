<?php

declare(strict_types=1);

namespace Test\Unit\Checking;

use Ark4ne\ApiRevisions\Checking\PrunedDeclaration;
use Test\TestCase;

final class PrunedDeclarationTest extends TestCase
{
    public function test_each_declaration_advises_what_to_delete(): void
    {
        self::assertSame('delete the field', PrunedDeclaration::Until->advice());
        self::assertSame('delete the was()', PrunedDeclaration::Was->advice());
        self::assertSame('delete the since(), already a no-op', PrunedDeclaration::Since->advice());
    }
}
