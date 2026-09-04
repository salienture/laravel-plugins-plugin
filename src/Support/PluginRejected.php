<?php

namespace Salienture\Plugins\Support;

use RuntimeException;

/**
 * Thrown when an uploaded plugin archive fails security or structural
 * inspection. Carries every reason found so admins get actionable
 * feedback instead of a generic failure.
 */
class PluginRejected extends RuntimeException
{
    /**
     * Create a new rejection with the list of issues found.
     *
     * @param  array<int, string>  $issues  Human-readable reasons.
     * @param  string|null  $message  Optional summary override.
     */
    public function __construct(
        public readonly array $issues,
        ?string $message = null,
    ) {
        parent::__construct(
            $message ?? implode('; ', array_slice($issues, 0, 3)).
            (count($issues) > 3 ? ' (+'.(count($issues) - 3).' more)' : ''),
        );
    }
}
