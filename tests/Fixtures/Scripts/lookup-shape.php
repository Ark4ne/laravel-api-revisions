<?php

declare(strict_types=1);

/*
 * Run by `CompiledShapesTest` in a fresh process: looks up one shape of `OrderDeclaration` at
 * `Winter25` from the compiled file given as argument, and prints as JSON the classes the load and the lookup
 * declared.
 *
 * Usage: php tests/Fixtures/Scripts/lookup-shape.php <compiled file>
 */

use Ark4ne\ApiRevisions\Compilation\CompiledShapes;
use Ark4ne\ApiRevisions\Compilation\Shapes;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Test\Fixtures\ApiRevision;

require __DIR__ . '/../../../vendor/autoload.php';

$arguments = $_SERVER['argv'] ?? [];
$path = is_array($arguments) ? $arguments[1] ?? null : null;
if (!is_string($path)) {
    fwrite(STDERR, 'Usage: lookup-shape.php <compiled file>' . PHP_EOL);
    exit(1);
}

$before = get_declared_classes();
$shapes = new Shapes(compiled: CompiledShapes::load($path));
$shapes->for('Test\Fixtures\Declarations\OrderDeclaration', DeclarationKind::Response, ApiRevision::Winter25);

echo json_encode(array_values(array_diff(get_declared_classes(), $before)), JSON_THROW_ON_ERROR);
