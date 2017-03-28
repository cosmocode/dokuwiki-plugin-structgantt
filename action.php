<?php
/**
 * DokuWiki Plugin structgantt (Action Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Gohr <dokuwiki@cosmocode.de>
 */


// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

class action_plugin_struct_ajax extends DokuWiki_Action_Plugin {

    /**
     * Registers a callback function for a given event
     *
     * @param Doku_Event_Handler $controller DokuWiki's event controller object
     * @return void
     */
    public function register(Doku_Event_Handler $controller) {
        $controller->register_hook('PLUGIN_STRUCT_CONFIGPARSER_UNKNOWNKEY', 'BEFORE', $this, 'handle_configparser');
    }

    /**
     * Add our own config keys
     *
     * @param Doku_Event $event event object by reference
     * @param mixed $param [the parameters passed as fifth argument to register_hook() when this
     *                           handler was registered]
     */
    public function handle_configparser(Doku_Event $event, $param) {
        if(!in_array($event->data['key'], array('skipweekend', 'skipweekends'))) return;
        $event->preventDefault();
        $event->stopPropagation();
        $event->data['config']['skipweekends'] = (bool) $event->data['val'];
    }

}
