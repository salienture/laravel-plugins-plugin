<?php

namespace Salienture\Plugins\Events;

/**
 * Dispatched after a plugin has been activated successfully.
 *
 * Fired by the PluginManager once state is persisted, migrations have run
 * and the plugin's own activate() hook has returned.
 */
class PluginActivated
{
    /**
     * Create a new event instance.
     *
     * @param  string  $slug  Activated plugin slug in "vendor/name" form.
     */
    public function __construct(
        public readonly string $slug,
    ) {}
}
