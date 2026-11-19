<?php

namespace Exceedone\Exment\Exceptions;

/**
 * The configured Meilisearch index belongs to a different Exment installation.
 *
 * Acting on it anyway would overwrite that installation's documents, serve its
 * record content in this one's search results, and delete its documents on
 * exment:meili-reconcile. See MeiliIndexOwnership.
 */
class MeiliForeignIndexException extends \RuntimeException
{
}
