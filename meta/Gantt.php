<?php

namespace dokuwiki\plugin\structgantt\meta;

use dokuwiki\plugin\struct\meta\Aggregation;
use dokuwiki\plugin\struct\meta\SearchConfig;
use dokuwiki\plugin\struct\meta\StructException;
use dokuwiki\plugin\struct\meta\Value;
use dokuwiki\plugin\struct\types\Color;
use dokuwiki\plugin\struct\types\Date;
use dokuwiki\plugin\struct\types\DateTime;

class Gantt extends Aggregation
{

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

    /** @var  bool do not show saturday and sunday */
    protected $skipWeekends;

    /** @var string[] interval formats used */
    protected $interval = [
        'header' => 'j', // shown in header
        'period' => 'P1D', // smallest shown interval
        'next' => '+1 day', // one more interval
        'short' => 'q', // interval label
        'long' => 'Y-m-d', // interval long label
        'comp' => 'Y-m-d', // sortable interval
    ];

    /** @inheritdoc */
    public function __construct($id, $mode, \Doku_Renderer $renderer, SearchConfig $searchConfig)
    {
        parent::__construct($id, $mode, $renderer, $searchConfig);
        if ($this->mode !== 'xhtml') {
            return;
        }

        $conf = $searchConfig->getConf();
        $this->skipWeekends = $conf['skipweekends'] ?? false;
        $this->initColumnRefs();
        if ($this->resultCount) {
            $this->initMinMax();
        }
    }

    /**
     * Figure out which columns will be used for dates and color
     *
     * The first date column is the start, the second is the end
     *
     * @todo suport Lookups pointing to dates and colors
     * @todo handle multi columns
     */
    protected function initColumnRefs()
    {
        $ref = 0;
        foreach ($this->columns as $column) {
            if (
                is_a($column->getType(), Date::class) ||
                is_a($column->getType(), DateTime::class)
            ) {
                if ($this->colrefStart == -1) {
                    $this->colrefStart = $ref;
                } else {
                    $this->colrefEnd = $ref;
                }
            } elseif (is_a($column->getType(), Color::class)) {
                $this->colrefColor = $ref;
            } else {
                if ($this->labelRef == -1) {
                    $this->labelRef = $ref;
                } else {
                    if ($this->titleRef == -1) {
                        $this->titleRef = $ref;
                    }
                }
            }
            $ref++;
        }

        if ($this->colrefStart === -1 || $this->colrefEnd === -1) {
            throw new StructException('Not enough Date columns selected');
        }

        if ($this->labelRef === -1) {
            throw new StructException('No label column found');
        }

        if ($this->titleRef === -1) {
            $this->titleRef = $this->labelRef;
        }
    }

    /**
     * Figure out the minimum and maximum dates and number of days inbetween
     *
     * @throws StructException when the range is not at least two days
     */
    protected function initMinMax()
    {
        $min = PHP_INT_MAX;
        $max = 0;

        /** @var Value[] $row */
        foreach ($this->result as $row) {
            $start = $row[$this->colrefStart]->getCompareValue();
            $start = explode(' ', $start); // cut off time
            $start = array_shift($start);
            if ($start && $start < $min) $min = $start;
            if ($start && $start > $max) $max = $start;

            $end = $row[$this->colrefEnd]->getCompareValue();
            $end = explode(' ', $end); // cut off time
            $end = array_shift($end);
            if ($end && $end < $min) $min = $end;
            if ($end && $end > $max) $max = $end;
        }

        $daynum = (new \DateTime($min))->diff(new \DateTime($max))->days;
        if ($daynum <= 1) {
            throw new StructException('Not enough variation in dates to create a range');
        }

        // define the resolution
        if ($daynum < 14) {
            $this->interval = [
                'header' => 'j', // days
                'period' => 'P1D',
                'next' => '+1 day',
                'short' => '\\\\q',
                'long' => 'Y-m-d',
                'comp' => 'Y-m-d'
            ];
        } elseif ($daynum < 52) {
            $this->interval = [
                'header' => '\wW', // week numbers
                'period' => 'P1D',
                'next' => '+1 day',
                'short' => '\\\\q',
                'long' => 'Y-m-d',
                'comp' => 'Y-m-d',
            ];
        } elseif ($daynum < 360) {
            $this->interval = [
                'header' => 'F', // months
                'period' => 'P1D',
                'next' => '+1 day',
                'short' => '\\\\q',
                'long' => 'Y-m-d',
                'comp' => 'Y-m-d',
            ];
        } elseif ($daynum < 600) {
            $this->interval = [
                'header' => 'M \'y', // months and year
                'period' => 'P1W', // weeks
                'next' => '+1 week',
                'short' => '\wW',
                'long' => '\wW o',
                'comp' => 'o-W',
            ];
            $this->skipWeekends = false;
        } else {
            $this->interval = [
                'header' => '\\\\Q \'y', // quarter and year
                'period' => 'P1M', // months
                'next' => '+1 month',
                'short' => 'M',
                'long' => 'F Y',
                'comp' => 'Y-m',
            ];
            $this->skipWeekends = false;
        }

        $this->minDate = $min;
        $this->maxDate = $max;
        $this->days = $this->listDays($min, $max);
        $this->daynum = $daynum;
    }

    /** @inheritdoc */
    public function getScopeClasses()
    {
        $classes = parent::getScopeClasses();
        $classes[] = 'table';
        return $classes;
    }


    /** @inheritdoc */
    public function render($showNotFound = false)
    {
        if ($this->mode !== 'xhtml') {
            return;
        }

        if ($this->resultCount) {
            $this->renderer->doc .= '<table>';
            $this->renderer->doc .= '<thead>';
            $this->renderHeaders();
            $this->renderer->doc .= '</thead>';
            $this->renderer->doc .= '<tbody>';
            foreach ($this->result as $row) {
                $this->renderRow($row);
            }
            $this->renderer->doc .= '</tbody>';
            $this->renderer->doc .= '<tfoot>';
            $this->renderDayRow();
            $this->renderer->doc .= '</tfoot>';
            $this->renderer->doc .= '</table>';
        } elseif ($showNotFound) {
            global $lang;
            $this->renderer->cdata($lang['nothingfound']);
        }
    }

    /**
     * Get the color to use in this row
     *
     * @param Value[] $row
     * @return string
     */
    protected function getColorStyle($row)
    {
        if ($this->colrefColor === -1) return '';
        $color = $row[$this->colrefColor]->getValue();
        $conf = $row[$this->colrefColor]->getColumn()->getType()->getConfig();
        if ($color == $conf['default']) return '';
        return 'style="background-color:' . $color . '"';
    }

    /**
     * Render the headers
     *
     * Automatically decides on the scale
     */
    protected function renderHeaders()
    {
        $headers = $this->makeHeaders($this->minDate, $this->maxDate);

        $this->renderer->doc .= '<tr>';
        $this->renderer->doc .= '<th></th>';
        foreach ($headers as $name => $days) {
            $this->renderer->doc .= '<th colspan="' . $days . '">' . $name . '</th>';
        }
        $this->renderer->doc .= '</tr>';
        $this->renderDayRow();
    }

    /**
     * Render a row for the days and the today pointer
     */
    protected function renderDayRow()
    {
        $today = new \DateTime();
        $this->renderer->doc .= '<tr class="days">';
        $this->renderer->doc .= '<th></th>';
        foreach ($this->days as $day) {
            if ($day->format($this->interval['long']) == $today->format($this->interval['long'])) {
                $class = 'today';
            } else {
                $class = '';
            }
            $text = $this->intervalFormat($day, 'short');
            $title = $this->intervalFormat($day, 'long');
            $this->renderer->doc .= '<td title="' . $title . '" class="' . $class . '">' . $text . '</td>';
        }
        $this->renderer->doc .= '</tr>';
    }

    /**
     * Render one row in the  diagram
     *
     * @param Value[] $row
     */
    protected function renderRow($row)
    {
        $start = $row[$this->colrefStart]->getCompareValue();
        $end = $row[$this->colrefEnd]->getCompareValue();

        if ($start && $end) {
            $r1 = $this->listDays($this->minDate, $start);
            $r2 = $this->listDays($start, $end);
            $r3 = $this->listDays($end, $this->maxDate);

            while ($r1 && ($this->intervalFormat(end($r1), 'comp') >= $this->intervalFormat($r2[0], 'comp'))) {
                array_pop($r1);
            }
            while ($r3 && ($this->intervalFormat($r3[0], 'comp') <= $this->intervalFormat(end($r2), 'comp'))) {
                array_shift($r3);
            }
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
        foreach ($r1 as $day) {
            $this->renderer->doc .= '<td title="' . $this->intervalFormat($day, 'long') . '"></td>';
        }

        // the task itself
        if ($r2) {
            $style = $this->getColorStyle($row);
            $this->renderer->doc .= '<td colspan="' . count($r2) . '" class="task" ' . $style . '>';
            $row[$this->titleRef]->render($this->renderer, $this->mode);

            $this->renderer->doc .= '<dl class="flyout">';
            foreach ($row as $value) {
                $this->renderer->doc .= '<dd>';
                $value->render($this->renderer, $this->mode);
                $this->renderer->doc .= '<dd>';

            }
            $this->renderer->doc .= '</dl>';

            $this->renderer->doc .= '</td>';
        }

        // period after the task
        foreach ($r3 as $day) {
            $this->renderer->doc .= '<td title="' . $this->intervalFormat($day, 'long') . '"></td>';
        }

        $this->renderer->doc .= '</tr>';
    }

    /**
     * Returns the interval units in the given period
     *
     * @fixme currently it's still called days, but may actually use weeks or months
     * @link based on http://stackoverflow.com/a/31046319/172068
     * @param string $start as YYYY-MM-DD
     * @param string $end as YYYY-MM-DD
     * @return \DateTime[]
     */
    protected function listDays($start, $end)
    {
        if ($start > $end) list($start, $end) = array($end, $start);
        $days = array();

        $period = new \DatePeriod(
            new \DateTime($start),
            new \DateInterval($this->interval['period']),
            (new \DateTime($end))->modify($this->interval['next']) // Include End Date (flag is only available in PHP8)
        );

        /** @var \DateTime $date */
        foreach ($period as $date) {
            if ($this->skipWeekends && (int)$date->format('N') >= 6) {
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
     * @return array
     */
    protected function makeHeaders($start, $end)
    {
        if ($start > $end) list($start, $end) = array($end, $start);
        $headers = array();

        $period = new \DatePeriod(
            new \DateTime($start),
            new \DateInterval($this->interval['period']),
            (new \DateTime($end))->modify($this->interval['next']) // Include End Date (flag is only available in PHP8)
        );

        /** @var \DateTime $date */
        foreach ($period as $date) {
            if ($this->skipWeekends && (int)$date->format('N') >= 6) {
                continue;
            } else {
                $ident = $this->intervalFormat($date, 'header');
                if (!isset($headers[$ident])) {
                    $headers[$ident] = 1;
                } else {
                    $headers[$ident]++;
                }
            }
        }

        return $headers;
    }

    /**
     * Wrapper around DateTime->format() to implement our own placeholders
     *
     * @param \DateTime $date
     * @param string $formatname
     * @return string
     */
    protected function intervalFormat(\DateTime $date, $formatname)
    {
        $format = $this->interval[$formatname];
        $label = $date->format($format);
        return str_replace(
            [
                '\Q', // quarter of the year
                '\q', // first letter of the day
            ],
            [
                'Q' . ceil($date->format('n') / 3),
                substr($date->format('l'), 0, 1),
            ],
            $label
        );
    }
}
