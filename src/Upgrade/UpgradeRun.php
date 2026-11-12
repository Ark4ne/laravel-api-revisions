<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Upgrade;

use Ark4ne\ApiRevisions\Conversion\Converters;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;

/**
 * What one `Upgrader::upgrade()` call carries down its levels: the converters the chains are bound to, and the
 * declaration kind of the request, which its nested resources share (model.md D49), and the client's revision, whose
 * names make the key of a rejected value (model.md D63).
 *
 * @internal
 */
final readonly class UpgradeRun
{
    public function __construct(
        public Converters $converters,
        public DeclarationKind $kind,
        public Revision $client,
    ) {}
}
