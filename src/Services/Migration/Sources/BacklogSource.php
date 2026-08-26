<?php

namespace Exceedone\Exment\Services\Migration\Sources;

/**
 * Reads a Backlog space through the v2 REST API.
 *
 *   https://{space}.backlog.com/api/v2/...?apiKey={key}
 *
 * Everything here was taken from the published API: issues page with
 * count/offset where count tops out at 100, the space-wide masters live at
 * /priorities and /users, and the per-project masters at
 * /projects/:key/statuses and /projects/:key/issueTypes.
 *
 * Worth knowing before reading the mapping: a Backlog issue already carries
 * its issueType, status, priority, assignee, category, versions and milestone
 * as nested objects. The master streams are pulled anyway, because a status
 * nobody currently uses still has to become a choice in Exment - otherwise the
 * first person to move a ticket back to it finds the option missing.
 */
class BacklogSource extends SourceBase
{
    /** The API caps a page at 100 whatever you ask for. */
    public const PAGE = 100;

    /** @var array<string, mixed>|null */
    protected $spaceInfo = null;

    /**
     * {@inheritdoc}
     */
    public function name(): string
    {
        return 'backlog';
    }

    /**
     * {@inheritdoc}
     */
    public function streams(): array
    {
        // masters first: an issue points at a user, a status and a project,
        // and those have to already exist when it lands
        return ['user', 'project', 'priority', 'status', 'issue_type', 'category', 'version', 'issue', 'comment'];
    }

    /**
     * {@inheritdoc}
     */
    public function check(): array
    {
        $space = $this->setting('space', 'BACKLOG_SPACE');
        $key = $this->setting('api_key', 'BACKLOG_API_KEY');

        if (is_nullorempty($space)) {
            return ['ok' => false, 'message' => 'BACKLOG_SPACE is not set (the part before .backlog.com)'];
        }
        if (is_nullorempty($key)) {
            return ['ok' => false, 'message' => 'BACKLOG_API_KEY is not set'];
        }

        try {
            $me = $this->call('users/myself');
            $info = $this->call('space');
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        if (!is_array($me) || !array_get($me, 'id')) {
            return ['ok' => false, 'message' => 'the api key was accepted but returned no user'];
        }

        return [
            'ok' => true,
            'message' => sprintf(
                'connected to %s as %s <%s>',
                array_get($info, 'name', $this->space()),
                array_get($me, 'name', '?'),
                array_get($me, 'mailAddress', '?')
            ),
            'detail' => [
                'space' => array_get($info, 'spaceKey'),
                'user' => array_get($me, 'id'),
                'admin' => array_get($me, 'roleType') == 1,
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function fetch(string $stream, array $options = []): \Generator
    {
        switch ($stream) {
            case 'user':
                yield from $this->simple('users', $options);
                return;

            case 'project':
                yield from $this->projects($options);
                return;

            case 'priority':
                yield from $this->simple('priorities', $options);
                return;

            case 'status':
                yield from $this->perProject('statuses', $options);
                return;

            case 'issue_type':
                yield from $this->perProject('issueTypes', $options);
                return;

            case 'category':
                yield from $this->perProject('categories', $options);
                return;

            case 'version':
                yield from $this->perProject('versions', $options);
                return;

            case 'issue':
                yield from $this->issues($options);
                return;

            case 'comment':
                yield from $this->comments($options);
                return;
        }

        $this->note(sprintf('backlog has no stream called "%s", skipped', $stream));
    }

    // ------------------------------------------------------------ streams ---

    /**
     * A stream that is one call returning one array.
     *
     * @param string $path
     * @param array<string, mixed> $options
     * @return \Generator<int, array<string, mixed>>
     */
    protected function simple(string $path, array $options): \Generator
    {
        $rows = $this->call($path);
        $limit = intval(array_get($options, 'limit', 0));
        $sent = 0;

        foreach ((array)$rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            yield $row;
            if ($limit > 0 && ++$sent >= $limit) {
                return;
            }
        }
    }

    /**
     * Projects, optionally narrowed to the keys the operator named.
     *
     * @param array<string, mixed> $options
     * @return \Generator<int, array<string, mixed>>
     */
    protected function projects(array $options): \Generator
    {
        $wanted = array_filter((array)array_get($options, 'projects', []));

        foreach ((array)$this->call('projects') as $project) {
            if (!is_array($project)) {
                continue;
            }
            if (!empty($wanted) && !in_array(array_get($project, 'projectKey'), $wanted)
                && !in_array(strval(array_get($project, 'id')), array_map('strval', $wanted))) {
                continue;
            }
            yield $project;
        }
    }

    /**
     * A master that lives under a project, tagged with the project it came
     * from so two projects with a status of the same name stay apart.
     *
     * @param string $path
     * @param array<string, mixed> $options
     * @return \Generator<int, array<string, mixed>>
     */
    protected function perProject(string $path, array $options): \Generator
    {
        foreach ($this->projects($options) as $project) {
            $key = array_get($project, 'projectKey');
            $rows = $this->call(sprintf('projects/%s/%s', rawurlencode(strval($key)), $path));

            if ($rows === null) {
                $this->note(sprintf('project %s has no %s', $key, $path));
                continue;
            }

            foreach ((array)$rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $row['projectKey'] = $key;
                yield $row;
            }
        }
    }

    /**
     * Issues, paged.
     *
     * @param array<string, mixed> $options
     * @return \Generator<int, array<string, mixed>>
     */
    protected function issues(array $options): \Generator
    {
        $query = $this->issueQuery($options);
        $limit = intval(array_get($options, 'limit', 0));
        $offset = 0;
        $sent = 0;
        $pages = 0;

        while (true) {
            if (++$pages > static::MAX_PAGES) {
                $this->note('stopped paging issues at the safety limit');
                return;
            }

            $page = $this->call('issues', $query + ['count' => static::PAGE, 'offset' => $offset]);
            $page = is_array($page) ? $page : [];

            foreach ($page as $issue) {
                if (!is_array($issue)) {
                    continue;
                }
                yield $issue;
                if ($limit > 0 && ++$sent >= $limit) {
                    return;
                }
            }

            // a short page is the last page - the API gives no total here
            if (count($page) < static::PAGE) {
                return;
            }

            $offset += static::PAGE;
        }
    }

    /**
     * Comments, for the issues named in $options['parents'], or for every
     * issue if nothing was named.
     *
     * Comments are the reason a migration is worth doing at all - a ticket
     * without its conversation is a row, not a history - so this deliberately
     * spends one call per issue rather than skipping them.
     *
     * @param array<string, mixed> $options
     * @return \Generator<int, array<string, mixed>>
     */
    protected function comments(array $options): \Generator
    {
        $parents = array_filter((array)array_get($options, 'parents', []));

        if (empty($parents)) {
            foreach ($this->issues($options) as $issue) {
                $key = array_get($issue, 'issueKey');
                if ($key) {
                    $parents[] = $key;
                }
            }
        }

        foreach ($parents as $issueKey) {
            $minId = 0;
            $pages = 0;

            while (true) {
                if (++$pages > static::MAX_PAGES) {
                    break;
                }

                $query = ['count' => static::PAGE, 'order' => 'asc'];
                if ($minId > 0) {
                    $query['minId'] = $minId;
                }

                $page = $this->call(sprintf('issues/%s/comments', rawurlencode(strval($issueKey))), $query);
                $page = is_array($page) ? $page : [];

                foreach ($page as $comment) {
                    if (!is_array($comment)) {
                        continue;
                    }
                    // carry the parent down: the comment payload has issueId
                    // but not the human key the issue was imported under
                    $comment['issueKey'] = $issueKey;
                    yield $comment;
                    $minId = max($minId, intval(array_get($comment, 'id', 0)));
                }

                if (count($page) < static::PAGE) {
                    break;
                }
            }
        }
    }

    // ------------------------------------------------------------ helpers ---

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    protected function issueQuery(array $options): array
    {
        $query = ['sort' => 'created', 'order' => 'asc'];

        // asking for them oldest first matters: a parent issue has to be in
        // before the child that names it, and Backlog numbers them in order

        $projectIds = array_filter((array)array_get($options, 'project_ids', []));
        if (!empty($projectIds)) {
            $query['projectId[]'] = array_values($projectIds);
        }

        if ($since = array_get($options, 'since')) {
            $query['updatedSince'] = $since;
        }

        // deliberately no statusId filter. The four built-in ids are 1..4, but
        // a project may define its own, and naming the built-ins would quietly
        // drop every issue sitting in a custom status - the worst possible
        // outcome for a migration, because the count still looks plausible.

        return $query;
    }

    /**
     * @return string
     */
    protected function space(): string
    {
        return strval($this->setting('space', 'BACKLOG_SPACE', ''));
    }

    /**
     * @return string
     */
    protected function baseUrl(): string
    {
        $domain = $this->setting('domain', 'BACKLOG_DOMAIN', 'backlog.com');
        return sprintf('https://%s.%s/api/v2', $this->space(), $domain);
    }

    /**
     * @param string $path
     * @param array<string, mixed> $query
     * @return array<string, mixed>|null
     */
    protected function call(string $path, array $query = [])
    {
        $query['apiKey'] = $this->setting('api_key', 'BACKLOG_API_KEY', '');

        return $this->get($this->baseUrl() . '/' . ltrim($path, '/'), $query);
    }
}
