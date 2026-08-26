<?php

namespace Exceedone\Exment\Services\Migration\Sources;

use Illuminate\Support\Str;

/**
 * Reads a ServiceNow instance through the Table API.
 *
 *   GET https://{instance}.service-now.com/api/now/table/{table}
 *       ?sysparm_query=...&sysparm_limit=...&sysparm_offset=...
 *
 * Two decisions here are worth stating, because both are easy to get wrong and
 * neither announces itself when it is wrong:
 *
 * 1. sysparm_display_value=all. ServiceNow can return either the stored value
 *    ("1") or what a person sees ("1 - Critical"), and a migration needs both:
 *    the stored value to map onto an Exment choice reliably, the label so the
 *    choice reads the same as it did in the old system. "all" returns
 *    {"value": ..., "display_value": ...} for every field, and the mapper
 *    picks whichever it was asked for.
 *
 * 2. Comments and work notes are NOT columns. They live in sys_journal_field,
 *    one row per entry, keyed by element_id. Pulling the incident table alone
 *    gets you tickets with no conversation - which is how most ServiceNow
 *    exports quietly lose the thing the tickets were about.
 */
class ServiceNowSource extends SourceBase
{
    /**
     * Records per request. The API allows far more, but a page of a wide table
     * with display values is already a few megabytes, and a request that times
     * out halfway costs more than two that do not.
     */
    public const PAGE = 500;

    /** Where the conversation actually lives. */
    public const JOURNAL_TABLE = 'sys_journal_field';

    /** Attachment metadata endpoint. */
    public const ATTACHMENT_PATH = 'api/now/attachment';

    /**
     * {@inheritdoc}
     */
    public function name(): string
    {
        return 'servicenow';
    }

    /**
     * {@inheritdoc}
     */
    public function streams(): array
    {
        // masters first, then the record tables the operator asked for, then
        // the things that hang off them
        $streams = ['sys_user_group', 'sys_user'];

        foreach ($this->tables() as $table) {
            if (!in_array($table, $streams)) {
                $streams[] = $table;
            }
        }

        $streams[] = 'journal';
        $streams[] = 'attachment';

        return $streams;
    }

    /**
     * {@inheritdoc}
     */
    public function check(): array
    {
        $instance = $this->setting('instance', 'SERVICENOW_INSTANCE');
        if (is_nullorempty($instance)) {
            return ['ok' => false, 'message' => 'SERVICENOW_INSTANCE is not set (the part before .service-now.com)'];
        }

        if (is_nullorempty($this->setting('token', 'SERVICENOW_TOKEN'))
            && is_nullorempty($this->setting('user', 'SERVICENOW_USER'))) {
            return ['ok' => false, 'message' => 'set either SERVICENOW_TOKEN or SERVICENOW_USER + SERVICENOW_PASSWORD'];
        }

        try {
            $body = $this->table('sys_user', [
                'sysparm_limit' => 1,
                'sysparm_fields' => 'sys_id,user_name,email',
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $rows = array_get($body, 'result');
        if (!is_array($rows)) {
            return ['ok' => false, 'message' => 'the instance answered but sent no result set - check the account may read sys_user'];
        }

        // being able to read the journal is the difference between migrating
        // tickets and migrating rows, so it is checked up front rather than
        // discovered as an empty comment table at the end
        $journal = true;
        try {
            $probe = $this->table(static::JOURNAL_TABLE, ['sysparm_limit' => 1, 'sysparm_fields' => 'sys_id']);
            $journal = is_array(array_get($probe, 'result'));
        } catch (\Throwable $e) {
            $journal = false;
        }

        if (!$journal) {
            $this->note('cannot read ' . static::JOURNAL_TABLE . ': comments and work notes will not come across. Grant the account read on that table.');
        }

        return [
            'ok' => true,
            'message' => sprintf('connected to %s (journal readable: %s)', $this->instance(), $journal ? 'yes' : 'NO'),
            'detail' => ['instance' => $this->instance(), 'journal' => $journal],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function fetch(string $stream, array $options = []): \Generator
    {
        if ($stream == 'journal') {
            yield from $this->journal($options);
            return;
        }

        if ($stream == 'attachment') {
            yield from $this->attachments($options);
            return;
        }

        yield from $this->records($stream, $options);
    }

    // ------------------------------------------------------------ streams ---

    /**
     * Any ServiceNow table, paged.
     *
     * @param string $table
     * @param array<string, mixed> $options
     * @return \Generator<int, array<string, mixed>>
     */
    protected function records(string $table, array $options): \Generator
    {
        $limit = intval(array_get($options, 'limit', 0));
        $offset = 0;
        $sent = 0;
        $pages = 0;

        $query = [
            'sysparm_display_value' => 'all',
            'sysparm_exclude_reference_link' => 'true',
            // oldest first so a parent lands before the child pointing at it,
            // and so a run that dies halfway can be resumed by date
            'sysparm_query' => $this->query($options),
        ];

        if ($fields = array_get($options, 'fields')) {
            $query['sysparm_fields'] = is_array($fields) ? implode(',', $fields) : $fields;
        }

        while (true) {
            if (++$pages > static::MAX_PAGES) {
                $this->note(sprintf('stopped paging %s at the safety limit', $table));
                return;
            }

            $body = $this->table($table, $query + [
                'sysparm_limit' => static::PAGE,
                'sysparm_offset' => $offset,
            ]);

            $rows = array_get($body, 'result');
            if (!is_array($rows)) {
                // some versions answer 404 rather than an empty set
                return;
            }

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $row['__table'] = $table;
                yield $row;
                if ($limit > 0 && ++$sent >= $limit) {
                    return;
                }
            }

            if (count($rows) < static::PAGE) {
                return;
            }

            $offset += static::PAGE;
        }
    }

    /**
     * Comments and work notes, from sys_journal_field.
     *
     * Narrowed to the tables being migrated, because the journal holds every
     * conversation on the whole instance and pulling all of it to keep a tenth
     * of it is how a migration turns into an overnight job.
     *
     * @param array<string, mixed> $options
     * @return \Generator<int, array<string, mixed>>
     */
    protected function journal(array $options): \Generator
    {
        $tables = array_filter((array)array_get($options, 'journal_tables', $this->tables()));
        if (empty($tables)) {
            $this->note('no tables named for the journal, comments skipped');
            return;
        }

        $elements = array_filter((array)array_get($options, 'journal_elements', ['comments', 'work_notes']));

        foreach ($tables as $table) {
            $clauses = ['name=' . $table];
            $clauses[] = implode('^OR', array_map(function ($element) {
                return 'element=' . $element;
            }, $elements));

            $parents = array_filter((array)array_get($options, 'parents', []));
            if (!empty($parents)) {
                $clauses[] = 'element_idIN' . implode(',', $parents);
            }

            $query = [
                'sysparm_display_value' => 'all',
                'sysparm_exclude_reference_link' => 'true',
                'sysparm_query' => implode('^', $clauses) . '^ORDERBYsys_created_on',
            ];

            $offset = 0;
            $pages = 0;

            while (true) {
                if (++$pages > static::MAX_PAGES) {
                    $this->note('stopped paging the journal at the safety limit');
                    break;
                }

                $body = $this->table(static::JOURNAL_TABLE, $query + [
                    'sysparm_limit' => static::PAGE,
                    'sysparm_offset' => $offset,
                ]);

                $rows = array_get($body, 'result');
                if (!is_array($rows)) {
                    break;
                }

                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $row['__table'] = $table;
                    yield $row;
                }

                if (count($rows) < static::PAGE) {
                    break;
                }

                $offset += static::PAGE;
            }
        }
    }

    /**
     * Attachment metadata for the tables being migrated.
     *
     * Metadata only. Whether the bytes get pulled across too is the caller's
     * decision, because that turns a migration measured in minutes into one
     * measured in gigabytes.
     *
     * @param array<string, mixed> $options
     * @return \Generator<int, array<string, mixed>>
     */
    protected function attachments(array $options): \Generator
    {
        $tables = array_filter((array)array_get($options, 'attachment_tables', $this->tables()));
        if (empty($tables)) {
            return;
        }

        foreach ($tables as $table) {
            $offset = 0;
            $pages = 0;

            while (true) {
                if (++$pages > static::MAX_PAGES) {
                    break;
                }

                $body = $this->call(static::ATTACHMENT_PATH, [
                    'sysparm_query' => 'table_name=' . $table . '^ORDERBYsys_created_on',
                    'sysparm_limit' => static::PAGE,
                    'sysparm_offset' => $offset,
                ]);

                $rows = array_get($body, 'result');
                if (!is_array($rows)) {
                    break;
                }

                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $row['__table'] = $table;
                    yield $row;
                }

                if (count($rows) < static::PAGE) {
                    break;
                }

                $offset += static::PAGE;
            }
        }
    }

    // ------------------------------------------------------------ helpers ---

    /**
     * The record tables to migrate. Configured, never guessed: the whole point
     * of ServiceNow is that every customer's tables are different.
     *
     * @return string[]
     */
    public function tables(): array
    {
        $tables = $this->setting('tables', 'SERVICENOW_TABLES', []);

        if (is_string($tables)) {
            $tables = array_map('trim', explode(',', $tables));
        }

        return array_values(array_filter((array)$tables));
    }

    /**
     * @param array<string, mixed> $options
     * @return string
     */
    protected function query(array $options): string
    {
        $clauses = [];

        if ($where = array_get($options, 'where')) {
            $clauses[] = $where;
        }

        if ($since = array_get($options, 'since')) {
            $clauses[] = 'sys_updated_on>=' . $since;
        }

        $clauses[] = 'ORDERBYsys_created_on';

        return implode('^', $clauses);
    }

    /**
     * @return string
     */
    protected function instance(): string
    {
        return strval($this->setting('instance', 'SERVICENOW_INSTANCE', ''));
    }

    /**
     * @return string
     */
    protected function baseUrl(): string
    {
        $instance = $this->instance();

        // allow a full url for the on-premise and developer instances that do
        // not sit on service-now.com
        if (Str::startsWith($instance, ['http://', 'https://'])) {
            return rtrim($instance, '/');
        }

        $domain = $this->setting('domain', 'SERVICENOW_DOMAIN', 'service-now.com');

        return sprintf('https://%s.%s', $instance, $domain);
    }

    /**
     * @param string $table
     * @param array<string, mixed> $query
     * @return array<string, mixed>|null
     */
    protected function table(string $table, array $query = [])
    {
        return $this->call('api/now/table/' . rawurlencode($table), $query);
    }

    /**
     * @param string $path
     * @param array<string, mixed> $query
     * @return array<string, mixed>|null
     */
    protected function call(string $path, array $query = [])
    {
        $options = ['headers' => ['Accept' => 'application/json']];

        if ($token = $this->setting('token', 'SERVICENOW_TOKEN')) {
            $options['headers']['Authorization'] = 'Bearer ' . $token;
        } else {
            $options['basic'] = [
                strval($this->setting('user', 'SERVICENOW_USER', '')),
                strval($this->setting('password', 'SERVICENOW_PASSWORD', '')),
            ];
        }

        return $this->get($this->baseUrl() . '/' . ltrim($path, '/'), $query, $options);
    }
}
