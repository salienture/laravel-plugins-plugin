<?php

namespace Salienture\Plugins\Events;

/**
 * Dispatched after a plugin has been deactivated.
 *
 * Data and files are intentionally kept; listeners should not assume the
 * plugin will disappear from disk.
 */
class PluginDeactivated
{
    /**
     * Create a new event instance.
     *
     * @param  string  $slug  Deactivated plugin slug in "vendor/name" form.
     */
    public function __construct(
        public readonly string $slug,
    ) {}
}
