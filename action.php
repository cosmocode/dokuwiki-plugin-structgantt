<?php

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\EventHandler;
use dokuwiki\Extension\Event;

/**
 * DokuWiki Plugin structgantt (Action Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Gohr <dokuwiki@cosmocode.de>
 */
class action_plugin_structgantt extends ActionPlugin
{
    /**
     * Registers a callback function for a given event
     *
     * @param EventHandler $controller DokuWiki's event controller object
     * @return void
     */
    public function register(EventHandler $controller)
    {
        $controller->register_hook('PLUGIN_STRUCT_CONFIGPARSER_UNKNOWNKEY', 'BEFORE', $this, 'handleConfigparser');
    }

    /**
     * Add our own config keys
     *
     * @param Event $event event object by reference
     * @param mixed $param [the parameters passed as fifth argument to register_hook() when this
     *                           handler was registered]
     */
    public function handleConfigparser(Event $event, $param)
    {
        if (!in_array($event->data['key'], ['skipweekend', 'skipweekends'])) return;
        $event->preventDefault();
        $event->stopPropagation();
        $event->data['config']['skipweekends'] = (bool) $event->data['val'];
    }
}
