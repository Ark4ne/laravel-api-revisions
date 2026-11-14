<?php

declare(strict_types=1);

namespace Test;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;

/**
 * A fresh directory per test (not created: `$temporary`), removed with its content after it.
 */
trait TemporaryDirectory
{
    private string $temporary;

    #[Before]
    protected function chooseTemporaryDirectory(): void
    {
        $this->temporary = sys_get_temp_dir() . '/api-revisions-' . bin2hex(random_bytes(6));
    }

    #[After]
    protected function removeTemporaryDirectory(): void
    {
        self::remove($this->temporary);
    }

    private static function remove(string $path): void
    {
        if (is_dir($path)) {
            foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
                self::remove($path . '/' . $entry);
            }
            rmdir($path);
        } elseif (file_exists($path)) {
            unlink($path);
        }
    }
}
