<?php

namespace Exceedone\Exment\Tests\Unit\Meili;

/**
 * Read one method's body out of a source file.
 *
 * The Meilisearch write paths can only be reached with a running Meilisearch,
 * so the guards on them are checked against the source instead.
 */
trait MeiliSourceTrait
{
    private function methodBody(string $relativePath, string $signature): string
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/' . $relativePath);

        $start = strpos($source, $signature);
        $this->assertNotFalse($start, "method not found: {$signature} in {$relativePath}");

        $open = strpos($source, '{', $start);
        $this->assertNotFalse($open);

        $depth = 0;
        for ($i = $open; $i < strlen($source); $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $open, $i - $open + 1);
                }
            }
        }

        $this->fail("unterminated method: {$signature}");
    }

    private function assertCallOrder(string $body, string $first, string $second, string $message): void
    {
        $a = strpos($body, $first);
        $b = strpos($body, $second);

        $this->assertNotFalse($a, $message);
        $this->assertNotFalse($b, "expected call not found: {$second}");
        $this->assertLessThan($b, $a, $message);
    }
}
