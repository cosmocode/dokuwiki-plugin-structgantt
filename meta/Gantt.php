<?php

namespace dokuwiki\plugin\structgantt\meta;

use dokuwiki\plugin\struct\meta\Column;
use dokuwiki\plugin\struct\meta\SearchConfig;
use dokuwiki\plugin\struct\meta\StructException;
use dokuwiki\plugin\struct\meta\Value;
use dokuwiki\plugin\struct\types\Color;
use dokuwiki\plugin\struct\types\Date;
use dokuwiki\plugin\struct\types\DateTime;

class Gantt {

    /** @var string the Type of renderer used */
    protected $mode;

    /** @var \Doku_Renderer the DokuWiki renderer used to create the output */
    protected $renderer;

    /** @var SearchConfig the configured search - gives access to columns etc. */
    protected $searchConfig;

    /** @var Column[] the list of columns to be displayed */
    protected $columns;

    /** @var  Value[][] the search result */
    protected $result;

    /** @var int number of all results */
    protected $resultCount;

    /** @var string[] the result PIDs for each row */
    protected $resultPIDs;

    /** @var int column number containing the start date */
    protected $colrefStart = -1;

    /** @var int column number containing the end date */
    protected $colrefEnd = -1;

    /** @var int column number containing the color */
    protected $colrefColor = -1;

    /** @var int column number containing the label */
    protected $labelRef = -1;

    /** @var int column number containing the title */
    protected $titleRef = -1;

    /** @var  string first date */
    protected $minDate;

    /** @var  string last date */
    protected $maxDate;

    /** @var  \DateTime[] all the days */
    protected $days;

    /** @var  int number of days */
    protected $daynum;

    /** @var int the scaling to use */
    protected $scale = 1;

    /** @var  bool do not show saturday and sunday */
    protected $skipWeekends;

    /**
     * Initialize the Aggregation renderer and executes the search
     *
     * You need to call @see render() on the resulting object.
     *
     * @param string $id
     * @param string $mode
     * @param \Doku_Renderer $renderer
     * @param SearchConfig $searchConfig
     */
    public function __construct($id, $mode, \Doku_Renderer $renderer, SearchConfig $searchConfig) {
        $this->mode = $mode;
        $this->renderer = $renderer;
        $this->searchConfig = $searchConfig;
        $this->columns = $searchConfig->getColumns();
        $this->result = $this->searchConfig->execute();
        $this->resultCount = $this->searchConfig->getCount();

        $conf = $searchConfig->getConf();
        $this->skipWeekends = $conf['skipweekends'];

        $this->initColumnRefs();
        $this->initMinMax();
    }

    /**
     * Figure out which columns will be used for dates and color
     *
     * The first date column is the start, the second is the end
     *
     * @todo suport Lookups pointing to dates and colors
     * @todo handle multi columns
     */
    protected function initColumnRefs() {
        $ref = 0;
        foreach($this->columns as $column) {
            if(
                is_a($column->getType(), Date::class) ||
                is_a($column->getType(), DateTime::class)
            ) {
                if($this->colrefStart == -1) {
                    $this->colrefStart = $ref;
                } else {
                    $this->colrefEnd = $ref;
                }
            } elseif(is_a($column->getType(), Color::class)) {
                $this->colrefColor = $ref;
            } else if($this->labelRef == -1) {
                $this->labelRef = $ref;
            } else if($this->titleRef == -1) {
                $this->titleRef = $ref;
            }
            $ref++;
        }

        if($this->colrefStart === -1 || $this->colrefEnd === -1) {
            throw new StructException('Not enough Date columns selected');
        }

        if($this->labelRef === -1) {
            throw new StructException('No label column found');
        }

        if($this->titleRef === -1) {
            $this->titleRef = $this->labelRef;
        }
    }

    /**
     * Figure out the minimum and maximum dates and number of days inbetween
     *
     * @throws StructException when the range is not at least two days
     */
    protected function initMinMax() {
        $min = PHP_INT_MAX;
        $max = 0;

        /** @var Value[] $row */
        foreach($this->result as $row) {
            $start = $row[$this->colrefStart]->getCompareValue();
            $start = explode(' ', $start); // cut off time
            $start = array_shift($start);
            if($start && $start < $min) $min = $start;
            if($start && $start > $max) $max = $start;

            $end = $row[$this->colrefEnd]->getCompareValue();
            $end = explode(' ', $end); // cut off time
            $end = array_shift($end);
            if($end && $end < $min) $min = $end;
            if($end && $end > $max) $max = $end;
        }

        $days = $this->listDays($min, $max);
        $daynum = count($days);
        if($days <= 1) {
            throw new StructException('Not enough variation in dates to create a range');
        }

        $this->minDate = $min;
        $this->maxDate = $max;
        $this->days = $days;
        $this->daynum = $daynum;
        $this->scale = $daynum / 85; // each day should have at least 1% space, 15% for the header
        if($this->scale < 1) $this->scale = 1;
    }

    /**
     * Output the chart
     */
    public function render() {
        if($this->mode !== 'xhtml') {
            $this->renderer->cdata('no other renderer than xhtml supported for struct gantt');
            return;
        }

        $width = 100 * $this->scale;
        $this->renderer->doc .= '<div class="table">';
        $this->renderer->doc .= '<table class="plugin_structgantt" style="width: ' . $width . '%">';
        $this->renderColGroup();
        $this->renderer->doc .= '<thead>';
        $this->renderHeaders();
        $this->renderer->doc .= '</thead>';
        $this->renderer->doc .= '<tbody>';
        foreach($this->result as $row) {
            $this->renderRow($row);
        }
        $this->renderer->doc .= '</tbody>';
        $this->renderer->doc .= '<tfoot>';
        $this->renderDayRow();
        $this->renderer->doc .= '</tfoot>';
        $this->renderer->doc .= '</table>';
        $this->renderer->doc .= '</div>';
    }

    /**
     * Get the color to use in this row
     *
     * @param Value[] $row
     * @return string
     */
    protected function getColorStyle($row) {
        if($this->colrefColor === -1) return '';
        $color = $row[$this->colrefColor]->getValue();
        $conf = $row[$this->colrefColor]->getColumn()->getType()->getConfig();
        if($color == $conf['default']) return '';
        return 'style="background-color:' . $color . '"';
    }

    /**
     * Render the headers
     *
     * Automatically decides on the scale
     */
    protected function renderHeaders() {
        // define the resolution
        if($this->daynum < 14) {
            $format = 'j'; // days
        } elseif($this->daynum < 60) {
            $format = '\wW'; // week numbers
        } else {
            $format = 'F'; // months
        }
        $headers = $this->makeHeaders($this->minDate, $this->maxDate, $format);

        $this->renderer->doc .= '<tr>';
        $this->renderer->doc .= '<th></th>';
        foreach($headers as $name => $days) {
            $this->renderer->doc .= '<th colspan="' . $days . '">' . $name . '</th>';
        }
        $this->renderer->doc .= '</tr>';
        $this->renderDayRow();
    }

    /**
     * Calculates how wide a day should be and creates an appropriate colgroup
     */
    protected function renderColGroup() {

        $headwidth = 15 * $this->scale;
        $daywidth = (100 * $this->scale - $headwidth) / $this->daynum;

        $this->renderer->doc .= '<colgroup>';
        $this->renderer->doc .= '<col style="width:' . $headwidth . '%" />';
        foreach($this->days as $day) {
            $this->renderer->doc .= '<col style="width:' . $daywidth . '%" />';
        }
        $this->renderer->doc .= '</colgroup>';

    }

    /**
     * Render a row for the days and the today pointer
     */
    protected function renderDayRow() {
        $today = date('Y-m-d');
        $this->renderer->doc .= '<tr class="days">';
        $this->renderer->doc .= '<th></th>';
        foreach($this->days as $day) {
            if($day->format('Y-m-d') == $today) {
                $class = 'today';
            } else {
                $class = '';
            }
            $text = substr($day->format('l'), 0, 1);
            $this->renderer->doc .= '<td title="' . $day->format('Y-m-d') . '" class="' . $class . '">' . $text . '</td>';
        }
        $this->renderer->doc .= '</tr>';
    }

    /**
     * Render one row in the  diagram
     *
     * @param Value[] $row
     */
    protected function renderRow($row) {
        $start = $row[$this->colrefStart]->getCompareValue();
        $end = $row[$this->colrefEnd]->getCompareValue();

        if($start && $end) {
            $r1 = $this->listDays($start, $this->minDate);
            $r2 = $this->listDays($end, $start);
            $r3 = $this->listDays($this->maxDate, $end);
        } else {
            $r1 = $this->days;
            $r2 = 0;
            $r3 = 0;
        }

        // header
        $this->renderer->doc .= '<tr>';
        $this->renderer->doc .= '<th>';
        $row[$this->labelRef]->render($this->renderer, $this->mode);
        $this->renderer->doc .= '</th>';

        // period before the task
        foreach($r1 as $day) {
            $this->renderer->doc .= '<td title="' . $day->format('Y-m-d') . '"></td>';
        }

        // the task itself
        if($r2) {
            $style = $this->getColorStyle($row);
            $this->renderer->doc .= '<td colspan="' . count($r2) . '" class="task" ' . $style . '>';
            $row[$this->titleRef]->render($this->renderer, $this->mode);

            $this->renderer->doc .= '<dl class="flyout">';
            foreach($row as $value) {
                $this->renderer->doc .= '<dd>';
                $value->render($this->renderer, $this->mode);
                $this->renderer->doc .= '<dd>';

            }
            $this->renderer->doc .= '</dl>';

            $this->renderer->doc .= '</td>';
        }

        // period after the task
        foreach($r3 as $day) {
            $this->renderer->doc .= '<td title="' . $day->format('Y-m-d') . '"></td>';
        }

        $this->renderer->doc .= '</tr>';
    }

    /**
     * Returns the days in the given period
     *
     * @link based on http://stackoverflow.com/a/31046319/172068
     * @param string $start as YYYY-MM-DD
     * @param string $end as YYYY-MM-DD
     * @return \DateTime[]
     */
    protected function listDays($start, $end) {
        if($start > $end) list($start, $end) = array($end, $start);
        $days = array();

        $period = new \DatePeriod(
            new \DateTime($start),
            new \DateInterval('P1D'),
            new \DateTime($end)
        );

        /** @var \DateTime $date */
        foreach($period as $date) {
            if($this->skipWeekends && (int) $date->format('N') >= 6) {
                continue;
            } else {
                $days[] = $date;
            }
        }

        return $days;
    }

    /**
     * Returns the headers
     *
     * @param string $start as YYYY-MM-DD
     * @param string $end as YYYY-MM-DD
     * @param string $format a format string as understood by date(), used for grouping
     * @return array
     */
    protected function makeHeaders($start, $end, $format) {
        if($start > $end) list($start, $end) = array($end, $start);
        $headers = array();

        $period = new \DatePeriod(
            new \DateTime($start),
            new \DateInterval('P1D'),
            new \DateTime($end)
        );

        /** @var \DateTime $date */
        foreach($period as $date) {
            if($this->skipWeekends && (int) $date->format('N') >= 6) {
                continue;
            } else {
                $ident = $date->format($format);
                if(!isset($headers[$ident])) {
                    $headers[$ident] = 1;
                } else {
                    $headers[$ident]++;
                }
            }
        }

        return $headers;
    }
}
