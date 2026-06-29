<?php

namespace Exceedone\Exment\Tests\Unit\Security;

/**
 * GUARD (bug class 2/3 of commit b26a6dd): array_splice on the array being foreach-iterated (index shift).
 *
 * Original bug (fixed): FileController.php
 *   foreach ($current_val as $key => $value) { ... array_splice($current_val, $key, 1); ... }
 *   (array_splice reindexes the source array while foreach iterates a copy -> $key drifts -> wrong/missed removal)
 *   -> array_values(array_filter(...))
 *
 * Detector: an array_splice($X, ...) call with an enclosing foreach ($X as ...) over the SAME variable $X
 * (within a few lines above). Note: unset($arr[$key]) of the current key is SAFE in PHP, so it is NOT
 * flagged (to avoid false positives) - only array_splice causes the index shift.
 */
class GuardArraySpliceInLoopTest extends CodebaseGuardTestCase
{
    private const ALLOWLIST = [
        // 'src/Path/To/File.php:123',
    ];

    /** Max distance (in lines) from the foreach to the array_splice to consider it "enclosing". */
    private const WINDOW = 40;

    /** Matches $var and $this->prop. */
    private const VAR = '\$[A-Za-z_]\w*(?:->[A-Za-z_]\w*)*';

    /**
     * @return array<array{line:int, code:string, var:string}>
     */
    public static function detectViolations(string $code): array
    {
        $code = self::stripComments($code);
        $lines = explode("\n", $code);

        // map: line -> array variable iterated by a foreach (if any)
        $foreachVarAtLine = [];
        foreach ($lines as $i => $line) {
            if (preg_match('/foreach\s*\(\s*(' . self::VAR . ')\s+as\b/', $line, $m)) {
                $foreachVarAtLine[$i] = $m[1];
            }
        }

        $violations = [];
        foreach ($lines as $i => $line) {
            if (!preg_match('/array_splice\s*\(\s*(' . self::VAR . ')/', $line, $m)) {
                continue;
            }
            $spliceVar = $m[1];
            // look for a foreach over the same variable within WINDOW lines above
            for ($j = $i - 1; $j >= max(0, $i - self::WINDOW); $j--) {
                if (isset($foreachVarAtLine[$j]) && $foreachVarAtLine[$j] === $spliceVar) {
                    $violations[] = ['line' => $i + 1, 'code' => trim($line), 'var' => $spliceVar];
                    break;
                }
            }
        }
        return $violations;
    }

    public function test_no_array_splice_on_array_being_foreached(): void
    {
        $found = [];
        foreach (self::phpFiles() as $file) {
            $code = (string) file_get_contents($file);
            foreach (self::detectViolations($code) as $v) {
                $key = self::rel($file) . ':' . $v['line'];
                if (in_array($key, self::ALLOWLIST, true)) {
                    continue;
                }
                $found[] = "$key (array_splice on {$v['var']} while foreach-iterating it)\n      > {$v['code']}";
            }
        }

        $this->assertSame(
            [],
            $found,
            "Found array_splice on an array being foreach-iterated (index shift like b26a6dd).\n"
            . "Use array_values(array_filter(...)) or collect items to remove and apply after the loop.\nViolations:\n - "
            . implode("\n - ", $found) . "\n"
        );
    }

    public function test_detector_actually_catches_the_bug_shape(): void
    {
        $bug = <<<'PHP'
        foreach ($current_val as $key => $value) {
            if ($value == $target) {
                array_splice($current_val, $key, 1);
            }
        }
        PHP;
        $this->assertNotEmpty(self::detectViolations($bug));

        // Fixed shape (array_filter) -> not caught.
        $fixed = '$current_val = array_values(array_filter($current_val, fn($v) => $v != $target));';
        $this->assertSame([], self::detectViolations($fixed));

        // array_splice on a DIFFERENT array than the foreach one -> not caught.
        $other = "foreach (\$rows as \$k => \$v) {\n array_splice(\$buffer, \$k, 1);\n}";
        $this->assertSame([], self::detectViolations($other));

        // unset of the current key (safe in PHP) -> not caught.
        $unset = "foreach (\$arr as \$key => \$v) {\n unset(\$arr[\$key]);\n}";
        $this->assertSame([], self::detectViolations($unset));
    }
}
