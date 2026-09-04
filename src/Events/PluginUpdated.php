<?php

namespace Salienture\Plugins\Events;

/**
 * Dispatched after an update archive was installed for a plugin.
 *
 * The version is the newly installed one; migrations and reactivation have
 * already completed when this fires.
 */
class PluginUpdated
{
    /**
     * Create a new event instance.
     *
     * @param  string  $slug  Updated plugin slug in "vendor/name" form.
     * @param  string  $version  Version that was installed.
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $version,
    ) {}
}
