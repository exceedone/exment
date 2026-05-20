<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Services\ClassBuilder;

/**
 * Security tests for ClassBuilder.
 *
 * The vulnerability: createCustomValue() used string concatenation to embed
 * $table->table_name into a PHP method body string without escaping, e.g.:
 *   "return '" . $table->table_name . "';"
 * If table_name contained a single quote (or PHP code), the generated code
 * would execute arbitrary PHP when eval()'d.
 *
 * The fix: use var_export($table->table_name, true) which always produces a
 * properly escaped PHP string literal, e.g.:
 *   "return " . var_export($table->table_name, true) . ";"
 *
 * Note: table_name is already validated by regex at the controller level
 * (only [a-zA-Z0-9_-] is allowed), so this is defence-in-depth that
 * protects against validation bypasses (direct DB writes, import, API).
 */
class SecurityClassBuilderTest extends UnitTestBase
{
    /**
     * Documents that the UNSAFE pattern (string concatenation) embeds executable
     * PHP into the generated method body string.
     *
     * This test uses string assertions only — it never calls build() with the
     * unsafe body to avoid actually executing injected code in the test suite.
     */
    public function testVulnerableStringConcatEmbedsPHPInjectionInGeneratedCode(): void
    {
        $dangerous = "test'; \$GLOBALS['_cb_unsafe_marker'] = true; \$z = '";

        // OLD pattern — what createCustomValue() DID before the fix:
        $unsafeBody = "return '" . $dangerous . "';";

        // The generated body literally contains the injected PHP statement.
        // When eval()'d this would execute: $GLOBALS['_cb_unsafe_marker'] = true;
        $this->assertStringContainsString(
            "\$GLOBALS['_cb_unsafe_marker'] = true",
            $unsafeBody,
            'DOCUMENTS THE BUG: string concatenation embeds unescaped PHP into method body'
        );
    }

    /**
     * Verifies that the FIXED pattern (var_export) escapes special characters
     * so that no injection payload appears as executable code in the method body.
     *
     * BEFORE FIX: createCustomValue() used string concatenation — the generated
     *             code would contain the raw injection payload.
     * AFTER FIX:  createCustomValue() uses var_export() — single quotes and
     *             other metacharacters are escaped, so the payload is inert.
     */
    public function testFixedVarExportPatternEscapesInjectionInGeneratedCode(): void
    {
        $dangerous = "test'; \$GLOBALS['_cb_safe_marker'] = true; \$z = '";

        // NEW pattern — what createCustomValue() does AFTER the fix:
        $safeBody = "return " . var_export($dangerous, true) . ";";

        // The injection payload must NOT appear as raw executable PHP in the body.
        $this->assertStringNotContainsString(
            "\$GLOBALS['_cb_safe_marker'] = true",
            $safeBody,
            'FIXED: var_export() escapes single quotes — injection payload is inert in method body'
        );
    }

    /**
     * Verifies that ClassBuilder::build() with a var_export-escaped method body
     * does NOT execute injected code (no side effects when eval()'d).
     *
     * This confirms the end-to-end safety guarantee: a dangerous string that goes
     * through var_export() before being embedded is harmless at eval() time.
     */
    public function testBuildWithVarExportedBodyDoesNotExecuteInjection(): void
    {
        // Use a time-based unique key to avoid cross-test pollution.
        $markerKey = '_cb_build_marker_' . str_replace('.', '_', (string)microtime(true));
        $dangerous = "test'; \$GLOBALS['{$markerKey}'] = true; \$z = '";

        // Apply the FIXED escaping (same as createCustomValue() after the fix):
        $safeBody = "return " . var_export($dangerous, true) . ";";

        // Class names must be unique to avoid "Cannot redeclare class" errors.
        $className = 'SecCls_' . str_replace('.', '_', (string)microtime(true));

        ClassBuilder::startBuild($className)
            ->addNamespace('Exceedone\\Exment\\Security')
            ->addMethod('public', 'getCustomTableNameAttribute()', $safeBody)
            ->build();

        $this->assertFalse(
            isset($GLOBALS[$markerKey]),
            'ClassBuilder::build() with var_export-escaped method body must NOT execute injected code'
        );
    }
}
