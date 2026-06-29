<?php

namespace Exceedone\Exment\Tests\Unit\Security;

/**
 * GUARD (bug class 1/3 of commit b26a6dd): SQL Injection by interpolating a VALUE into raw SQL.
 *
 * Original bug (fixed): GridBase.php  whereRaw("$column = '$query_value'")  -> whereRaw("$column = ?", [$query_value])
 *
 * Detector: a *Raw( / DB::raw( / ->raw( call on the same line that contains a single-quoted
 * literal wrapping a PHP variable: '...$...'  (both interpolation "...'$v'..." and concat "'" . $v . "'").
 * That is exactly the shape that lets a value be injected into SQL. A wrapped column
 * (e.g. "$col asc", "$col AS $alias") has no single quotes around the variable -> not matched
 * (safe, no false positive).
 *
 * Required: use a binding `Raw("... = ?", [$value])` or a PDO-quote, never interpolate a raw value.
 */
class GuardRawSqlValueInterpolationTest extends CodebaseGuardTestCase
{
    /** Occurrences reviewed as safe but still matching the detector (currently: none). */
    private const ALLOWLIST = [
        // 'src/Path/To/File.php:123',
    ];

    private const RAW_CALL = '(?:->\s*(?:where|orWhere|having|orHaving|orderBy|groupBy|select|from|join)Raw|DB::raw|->\s*raw)\s*\(';
    // '...$var...' : a PHP variable INTERPOLATED inside a single-quoted literal (the exact b26a6dd shape).
    // Excluding . [ ] { } from the in-quote region => it will NOT span across a concatenation
    // ('a'.$x.'b') or an array-key interpolation ({$arr['key']}) => avoids false positives.
    private const QUOTED_VAR = "'[^'.{}\\[\\]]*\\\$[A-Za-z_]\\w*(?:->[A-Za-z_]\\w*)?[^'.{}\\[\\]]*'";

    /**
     * @return array<array{line:int, code:string}>
     */
    public static function detectViolations(string $code): array
    {
        $code = self::stripComments($code);
        $lines = explode("\n", $code);
        $violations = [];
        foreach ($lines as $i => $line) {
            if (preg_match('/' . self::RAW_CALL . '/', $line)
                && preg_match('/' . self::QUOTED_VAR . '/', $line)) {
                $violations[] = ['line' => $i + 1, 'code' => trim($line)];
            }
        }
        return $violations;
    }

    public function test_no_value_interpolated_into_raw_sql(): void
    {
        $found = [];
        foreach (self::phpFiles() as $file) {
            $code = (string) file_get_contents($file);
            foreach (self::detectViolations($code) as $v) {
                $key = self::rel($file) . ':' . $v['line'];
                if (in_array($key, self::ALLOWLIST, true)) {
                    continue;
                }
                $found[] = "$key\n      > {$v['code']}";
            }
        }

        $this->assertSame(
            [],
            $found,
            "Found a value interpolated directly into raw SQL (SQLi risk like b26a6dd).\n"
            . "Use a binding instead: ->whereRaw(\"col = ?\", [\$value]).\nViolations:\n - "
            . implode("\n - ", $found) . "\n"
        );
    }

    /** Self-check: the detector MUST catch the original bug shape and must NOT catch the fixed/safe shapes. */
    public function test_detector_actually_catches_the_bug_shape(): void
    {
        // Original bug shape (variable interpolated inside single quotes) -> must be caught.
        $this->assertNotEmpty(self::detectViolations('<?php $model->whereRaw("$column = \'$query_value\'");'));
        $this->assertNotEmpty(self::detectViolations('<?php $m->whereRaw("$c = \'$filter_raw->view_filter_condition_value_text\'");'));

        // Fixed / safe shapes -> must NOT be caught.
        $this->assertSame([], self::detectViolations('<?php $model->whereRaw("$column = ?", [$query_value]);'));
        $this->assertSame([], self::detectViolations('<?php $query->orderByRaw("$view_column_target $sort_order");'));
        $this->assertSame([], self::detectViolations('<?php $q->whereRaw("a = \'b\'");')); // constant, no variable
        // Concatenating a sub-query SQL into DB::raw (a DIFFERENT shape, out of scope here) -> not caught.
        $this->assertSame([], self::detectViolations('<?php $this->join(\DB::raw(\'(\' . $query . \') s\'));'));
        // Array key inside interpolation {$arr[\'key\']} -> not caught.
        $this->assertSame([], self::detectViolations('<?php $q->orderByRaw("{$o[\'wrap_column\']} {$o[\'sort_type\']}");'));
        // Inside a comment -> ignored.
        $this->assertSame([], self::detectViolations("<?php\n// \$model->whereRaw(\"x = '\$id'\");"));
    }
}
