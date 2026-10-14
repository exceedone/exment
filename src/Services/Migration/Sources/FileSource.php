<?php

namespace Exceedone\Exment\Services\Migration\Sources;

/**
 * Reads records that are already on disk instead of calling anybody.
 *
 * This is the source that gets used most, for two reasons that have nothing to
 * do with convenience:
 *
 * 1. Fetching and mapping are separate problems. The fetch is slow, rate
 *    limited, and often only possible from inside somebody's network on a
 *    scheduled window. The mapping is where all the mistakes are, and it takes
 *    ten tries to get right. Pulling once to disk and then replaying that dump
 *    locally turns ten tries into ten seconds each instead of ten more fetches
 *    - and leaves an artefact you can diff when the numbers look wrong.
 *
 * 2. Plenty of customers will never hand over API credentials to their live
 *    ITSM instance, but will happily send a CSV export. Both Backlog and
 *    ServiceNow can produce one from the UI. That path has to work or the
 *    feature only serves the easy half of the customers.
 *
 * Layout, under storage/app/migration/<dir>/ :
 *
 *   issue.jsonl      one json object per line   (what --fetch-only writes)
 *   issue.json       one json array             (hand-made dumps)
 *   issue.csv        header row + rows          (what the UI exports give you)
 */
class FileSource extends SourceBase
{
    /** Tried in this order; the first that exists wins. */
    public const EXTENSIONS = ['jsonl', 'json', 'csv'];

    /**
     * Encodings a Japanese CSV export turns up in. UTF-8 is checked first so a
     * clean file is never mangled by a hopeful conversion.
     */
    public const ENCODINGS = ['UTF-8', 'SJIS-win', 'EUC-JP'];

    /**
     * {@inheritdoc}
     */
    public function name(): string
    {
        return 'file';
    }

    /**
     * {@inheritdoc}
     */
    public function streams(): array
    {
        $dir = $this->directory();
        if (!is_dir($dir)) {
            return [];
        }

        $streams = [];
        foreach (static::EXTENSIONS as $ext) {
            foreach (glob(rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . '*.' . $ext) ?: [] as $path) {
                $stream = pathinfo($path, PATHINFO_FILENAME);
                if (!in_array($stream, $streams)) {
                    $streams[] = $stream;
                }
            }
        }

        sort($streams);

        return $streams;
    }

    /**
     * {@inheritdoc}
     */
    public function check(): array
    {
        $dir = $this->directory();

        if (!is_dir($dir)) {
            return ['ok' => false, 'message' => 'no such directory: ' . $dir];
        }

        $streams = $this->streams();
        if (empty($streams)) {
            return ['ok' => false, 'message' => 'nothing to read in ' . $dir . ' (expected .jsonl, .json or .csv)'];
        }

        return [
            'ok' => true,
            'message' => sprintf('%d file(s) in %s: %s', count($streams), $dir, implode(', ', $streams)),
            'detail' => ['directory' => $dir, 'streams' => $streams],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function fetch(string $stream, array $options = []): \Generator
    {
        $path = $this->path($stream);

        if (!$path) {
            // not an error: a dump of issues with no comments file simply has
            // no comments, and saying so beats failing the run
            $this->note(sprintf('no file for "%s", skipped', $stream));
            return;
        }

        $limit = intval(array_get($options, 'limit', 0));
        $sent = 0;

        foreach ($this->rows($path) as $row) {
            if (!is_array($row)) {
                continue;
            }
            yield $row;
            if ($limit > 0 && ++$sent >= $limit) {
                return;
            }
        }
    }

    // -------------------------------------------------------------- files ---

    /**
     * @param string $path
     * @return \Generator<int, array<string, mixed>>
     */
    protected function rows(string $path): \Generator
    {
        switch (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            case 'jsonl':
                yield from $this->jsonLines($path);
                return;
            case 'json':
                yield from $this->jsonArray($path);
                return;
            case 'csv':
                yield from $this->csv($path);
                return;
        }
    }

    /**
     * One object per line. Read a line at a time so a two gigabyte dump costs
     * one line of memory rather than two gigabytes.
     *
     * @param string $path
     * @return \Generator<int, array<string, mixed>>
     */
    protected function jsonLines(string $path): \Generator
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            $this->note('could not open ' . $path);
            return;
        }

        $line = 0;

        try {
            while (($text = fgets($handle)) !== false) {
                $line++;
                $text = trim($text);
                if ($text === '') {
                    continue;
                }

                $row = json_decode($text, true);
                if (!is_array($row)) {
                    $this->note(sprintf('%s line %d is not valid json, skipped', basename($path), $line));
                    continue;
                }

                yield $row;
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param string $path
     * @return \Generator<int, array<string, mixed>>
     */
    protected function jsonArray(string $path): \Generator
    {
        $body = json_decode(strval(file_get_contents($path)), true);

        if (!is_array($body)) {
            $this->note(basename($path) . ' is not valid json');
            return;
        }

        // both a bare array and a ServiceNow style {"result": [...]} envelope
        if (isset($body['result']) && is_array($body['result'])) {
            $body = $body['result'];
        }

        foreach ($body as $row) {
            if (is_array($row)) {
                yield $row;
            }
        }
    }

    /**
     * Header row, then one record per line.
     *
     * @param string $path
     * @return \Generator<int, array<string, mixed>>
     */
    protected function csv(string $path): \Generator
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            $this->note('could not open ' . $path);
            return;
        }

        $header = null;
        $line = 0;

        try {
            while (($fields = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                $line++;

                if ($fields === [null]) {
                    continue;
                }

                $fields = array_map(function ($field) {
                    return $this->toUtf8(strval($field));
                }, $fields);

                if ($header === null) {
                    // strip the byte order mark Excel leaves on the first
                    // header, which otherwise makes column one unfindable
                    $fields[0] = preg_replace('/^\xEF\xBB\xBF/', '', strval($fields[0]));
                    $header = $fields;
                    continue;
                }

                if (count($fields) != count($header)) {
                    // pad or trim rather than drop: a trailing comma should not
                    // cost somebody a ticket
                    $fields = array_pad(array_slice($fields, 0, count($header)), count($header), null);
                }

                yield array_combine($header, $fields);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param string $text
     * @return string
     */
    protected function toUtf8(string $text): string
    {
        if ($text === '' || mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        $encoding = mb_detect_encoding($text, static::ENCODINGS, true);
        if (!$encoding || $encoding == 'UTF-8') {
            return $text;
        }

        $this->note('converted ' . $encoding . ' text to utf-8');

        return strval(mb_convert_encoding($text, 'UTF-8', $encoding));
    }

    /**
     * @param string $stream
     * @return string|null
     */
    protected function path(string $stream)
    {
        $dir = rtrim($this->directory(), '/\\');

        foreach (static::EXTENSIONS as $ext) {
            $path = $dir . DIRECTORY_SEPARATOR . $stream . '.' . $ext;
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @return string
     */
    public function directory(): string
    {
        $dir = strval($this->setting('directory', 'EXMENT_MIGRATION_DIR', ''));

        if ($dir === '') {
            return '';
        }

        // an absolute path is taken as given; a bare name is a folder under
        // storage, so the common case needs no path at all
        if (preg_match('/^([a-zA-Z]:[\\\\\/]|[\\\\\/])/', $dir)) {
            return $dir;
        }

        return storage_path(path_join('app', 'migration', $dir));
    }
}
