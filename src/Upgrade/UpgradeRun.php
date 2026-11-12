<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Upgrade;

use Ark4ne\ApiRevisions\Conversion\Converters;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;

/**
 * What one `Upgrader::upgrade()` call carries down its levels: the converters the chains are bound to, and the
 * declaration kind of the request, which its nested resources share (model.md D49).
 *
 * @internal
 */
final readonly class UpgradeRun
{
    public function __construct(
        public Converters $converters,
        public DeclarationKind $kind,
    ) {}
}
