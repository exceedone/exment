<?php

namespace Exceedone\Exment\Services\Migration\Sources;

/**
 * A system data is being brought in from.
 *
 * A source knows how to talk to one outside product and hand back its records
 * as plain arrays. It knows nothing about Exment: no table names, no columns,
 * no mapping. That belongs to the preset, so that adding a third product later
 * means writing one of these and nothing else.
 */
interface SourceInterface
{
    /**
     * Short name used on the command line, e.g. "backlog".
     *
     * @return string
     */
    public function name(): string;

    /**
     * Can we reach it, and are the credentials good?
     *
     * Returns ['ok' => bool, 'message' => string, 'detail' => array]. Never
     * throws: the point of this call is to give a readable answer instead of a
     * stack trace, because it is the first thing anyone runs.
     *
     * @return array<string, mixed>
     */
    public function check(): array;

    /**
     * The kinds of record this source can hand over, in the order they have to
     * be imported - masters before the records that point at them.
     *
     * @return string[]
     */
    public function streams(): array;

    /**
     * Hand over one stream, a record at a time.
     *
     * A generator rather than an array on purpose: a real migration is tens of
     * thousands of issues, and holding them all in memory to then write them
     * one by one buys nothing and runs the process out of memory on the large
     * customers - which are exactly the ones who need this.
     *
     * @param string $stream
     * @param array<string, mixed> $options limit, since, project, table...
     * @return \Generator<int, array<string, mixed>>
     */
    public function fetch(string $stream, array $options = []): \Generator;
}
