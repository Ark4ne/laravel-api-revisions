<?php

declare(strict_types=1);

namespace Test\Types;

use Ark4ne\ApiRevisions\Revisions\RevisionOrder;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Revisions\IntRevision;

use function PHPStan\Testing\assertType;

/*
 * Analysed by PHPStan, never run. On an enum, PHPStan reads `static` as the case itself: the walk methods of
 * IsRevision must still return the enum.
 */

assertType('Test\Fixtures\ApiRevision|null', ApiRevision::Spring26->previous());
assertType('Test\Fixtures\ApiRevision|null', ApiRevision::Spring26->next());
assertType('Test\Fixtures\ApiRevision', ApiRevision::latest());
assertType('Test\Fixtures\ApiRevision', ApiRevision::oldest());
assertType('bool', ApiRevision::Spring26->isBefore(ApiRevision::Atlas));
assertType('Test\\Fixtures\\Revisions\\IntRevision', IntRevision::latest());
assertType('Test\\Fixtures\\Revisions\\IntRevision|null', IntRevision::First->next());
assertType('list<Ark4ne\\ApiRevisions\\Revisions\\Revision>', RevisionOrder::between(ApiRevision::Genesis, ApiRevision::Atlas));
assertType('Ark4ne\\ApiRevisions\\Revisions\\Meta', ApiRevision::Atlas->meta());
assertType('Test\\Fixtures\\ApiRevision', ApiRevision::current());
assertType('1', ApiRevision::using(ApiRevision::Genesis, static fn(): int => 1));
