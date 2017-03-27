<?php
/**
 * DokuWiki Plugin structgantt (Syntax Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Gohr <gohr@cosmocode.de>
 */

// must be run within Dokuwiki
use dokuwiki\plugin\structgantt\meta\Gantt;

if (!defined('DOKU_INC')) die();

class syntax_plugin_structgantt extends syntax_plugin_struct_table {

    /** @var string which class to use for output */
    protected $tableclass = Gantt::class;

    /**
     * Connect lookup pattern to lexer.
     *
     * @param string $mode Parser mode
     */
    public function connectTo($mode) {
        $this->Lexer->addSpecialPattern('----+ *struct gantt *-+\n.*?\n----+', $mode, 'plugin_structgantt');
    }

}

// vim:ts=4:sw=4:et:
