<?php

declare(strict_types=1);

namespace Test\Unit\Revisions;

use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Ark4ne\ApiRevisions\Revisions\Meta;
use Ark4ne\ApiRevisions\Revisions\MetaReader;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Revisions\IntRevision;
use Test\Fixtures\Revisions\InvalidMetaRevision;
use Test\TestCase;

final class MetaReaderTest extends TestCase
{
    public function test_meta_reads_the_attribute_of_the_case(): void
    {
        $meta = MetaReader::of(ApiRevision::Spring26);

        self::assertEquals(
            new Meta('Totals in cents, `state` renamed `status`', publishedAt: '2026-04-01', deprecatedAt: '2026-11-01'),
            $meta,
        );
    }

    public function test_a_case_without_meta_has_an_empty_meta(): void
    {
        self::assertEquals(new Meta(), MetaReader::of(IntRevision::First));
    }

    public function test_meta_is_read_once_per_case(): void
    {
        self::assertSame(MetaReader::of(ApiRevision::Genesis), MetaReader::of(ApiRevision::Genesis));
        self::assertNotSame(MetaReader::of(ApiRevision::Genesis), MetaReader::of(ApiRevision::Winter25));
    }

    public function test_an_invalid_date_in_meta_names_the_case(): void
    {
        try {
            MetaReader::of(InvalidMetaRevision::BadDate);
            self::fail('The invalid #[Meta] was read.');
        } catch (DefinitionException $exception) {
            self::assertSame(
                "Invalid #[Meta] on Test\\Fixtures\\Revisions\\InvalidMetaRevision::BadDate: publishedAt must be a Y-m-d date, got '2026-02-30'.",
                $exception->getMessage(),
            );
            self::assertSame("publishedAt must be a Y-m-d date, got '2026-02-30'.", $exception->getPrevious()?->getMessage());
        }
    }

    /**
     * @return iterable<string, array{InvalidMetaRevision, string}>
     */
    public static function invalidDeclarations(): iterable
    {
        yield 'repeated attribute' => [InvalidMetaRevision::Repeated, 'InvalidMetaRevision::Repeated: Attribute "Ark4ne\\ApiRevisions\\Revisions\\Meta" must not be repeated'];
        yield 'wrong argument type' => [InvalidMetaRevision::WrongType, 'InvalidMetaRevision::WrongType: Ark4ne\\ApiRevisions\\Revisions\\Meta::__construct(): Argument #2 ($publishedAt) must be of type ?string, int given'];
    }

    #[DataProvider('invalidDeclarations')]
    public function test_an_invalid_declaration_of_meta_names_the_case(InvalidMetaRevision $case, string $message): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage($message);

        MetaReader::of($case);
    }
}
