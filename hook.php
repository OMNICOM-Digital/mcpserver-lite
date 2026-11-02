<?php

/**
 * Install / uninstall — intentionally empty. mcpserverlite is read-only and
 * stores nothing: no tables, no GLPI right, no configuration. Activating
 * the plugin is all that is needed; deactivating or uninstalling leaves no
 * data behind.
 */

function plugin_mcpserverlite_install()
{
    return true;
}

function plugin_mcpserverlite_uninstall()
{
    return true;
}
