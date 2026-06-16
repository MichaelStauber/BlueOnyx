<?php
/**
 * Table
 *
 * Reusable UIFC2 table component for compact Elmer-compatible read-only data
 * presentation. Supports optional headers, responsive wrapper, row-header
 * columns, no-wrap columns, captions, custom classes and per-cell rendering
 * options.
 *
 * Cell values may be provided as:
 * - scalar: escaped by default
 * - HtmlComponent object: rendered via toHtml()
 * - array with keys:
 *   - value
 *   - escape (bool)
 *   - class
 *   - align (left|center|right)
 *   - attributes (assoc array of html attrs)
 *   - colspan / rowspan
 */

global $isTableDefined;
if ($isTableDefined) return;
$isTableDefined = true;

include_once("uifc2/FormField.php");

class Table extends FormField {
    var $headers;
    var $rows;
    var $caption;
    var $emptyMsg;
    var $responsive;
    var $striped;
    var $hover;
    var $bordered;
    var $compact;
    var $rowHeaderColumn;
    var $noWrapColumns;
    var $columnClasses;
    var $tableClasses;
    var $wrapperClasses;

    public function __construct(&$page, $id, $headers = array(), $rows = array(), $i18n = "") {
        parent::__construct($page, $id, "", $i18n);
        $this->headers = $headers;
        $this->rows = $rows;
        $this->caption = "";
        $this->emptyMsg = "";
        $this->responsive = true;
        $this->striped = true;
        $this->hover = false;
        $this->bordered = false;
        $this->compact = false;
        $this->rowHeaderColumn = null;
        $this->noWrapColumns = array();
        $this->columnClasses = array();
        $this->tableClasses = array();
        $this->wrapperClasses = array();
        $this->setAccess("r");
    }

    function setHeaders($headers) {
        $this->headers = is_array($headers) ? $headers : array();
    }

    function addRow($row) {
        $this->rows[] = $row;
    }

    function setRows($rows) {
        $this->rows = is_array($rows) ? $rows : array();
    }

    function setCaption($caption) {
        $this->caption = $caption;
    }

    function setEmptyMessage($msg) {
        $this->emptyMsg = $msg;
    }

    function setResponsive($responsive) {
        $this->responsive = (bool) $responsive;
    }

    function setStriped($striped) {
        $this->striped = (bool) $striped;
    }

    function setHover($hover) {
        $this->hover = (bool) $hover;
    }

    function setBordered($bordered) {
        $this->bordered = (bool) $bordered;
    }

    function setCompact($compact) {
        $this->compact = (bool) $compact;
    }

    function setRowHeaderColumn($columnIndex = null) {
        $this->rowHeaderColumn = $columnIndex;
    }

    function setNoWrapColumns($columns) {
        $this->noWrapColumns = is_array($columns) ? $columns : array();
    }

    function setColumnClasses($classes) {
        $this->columnClasses = is_array($classes) ? $classes : array();
    }

    function addTableClass($className) {
        if ($className !== "") {
            $this->tableClasses[] = $className;
        }
    }

    function addWrapperClass($className) {
        if ($className !== "") {
            $this->wrapperClasses[] = $className;
        }
    }

    function makeHeaderLabelCell($text, $helptext = '', $for = '', $class = 'control-label mb-10') {
        $html = '<label';

        if ($for !== '') {
            $html .= ' for="' . htmlspecialchars($for, ENT_QUOTES, 'UTF-8') . '"';
        }

        $html .= ' class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '"';

        if ($helptext !== '') {
            $escapedHelp = htmlspecialchars($helptext, ENT_QUOTES, 'UTF-8');
            $html .= ' data-toggle="tooltip" data-placement="right" title="" data-original-title="' . $escapedHelp . '" data-container="body"';
        }

        $html .= '>' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</label>';

        return array(
            'value' => $html,
            'escape' => false
        );
    }

    function toHtml($style = "") {
        $tableClasses = array('table');
        if ($this->striped) {
            $tableClasses[] = 'table-striped';
        }
        if ($this->hover) {
            $tableClasses[] = 'table-hover';
        }
        if ($this->bordered) {
            $tableClasses[] = 'table-bordered';
        }
        if ($this->compact) {
            $tableClasses[] = 'table-condensed';
        }
        $tableClasses = array_merge($tableClasses, $this->tableClasses);

        $html = '';
        if ($this->responsive) {
            $wrapperClasses = trim(implode(' ', array_merge(array('table-responsive'), $this->wrapperClasses)));
            $html .= '<div class="' . htmlspecialchars($wrapperClasses, ENT_QUOTES, 'UTF-8') . '">';
        }

        $html .= '<table id="' . htmlspecialchars($this->getId(), ENT_QUOTES, 'UTF-8') . '" class="' . htmlspecialchars(trim(implode(' ', $tableClasses)), ENT_QUOTES, 'UTF-8') . '">';

        if ($this->caption !== "") {
            $html .= '<caption>' . htmlspecialchars($this->caption, ENT_QUOTES, 'UTF-8') . '</caption>';
        }

        if (count($this->headers) > 0) {
            $html .= '<thead><tr>';
            foreach ($this->headers as $columnIndex => $header) {
                $html .= $this->renderCell($header, 'th', $columnIndex, false, true);
            }
            $html .= '</tr></thead>';
        }

        $html .= '<tbody>';
        if (count($this->rows) === 0) {
            $colspan = max(1, count($this->headers));
            $message = ($this->emptyMsg !== '') ? $this->emptyMsg : '&nbsp;';
            $html .= '<tr><td colspan="' . (int) $colspan . '">' . $message . '</td></tr>';
        }
        else {
            foreach ($this->rows as $row) {
                $html .= '<tr>';
                foreach ($row as $columnIndex => $cell) {
                    $isRowHeader = ($this->rowHeaderColumn !== null && (int) $this->rowHeaderColumn === (int) $columnIndex);
                    $tag = $isRowHeader ? 'th' : 'td';
                    $html .= $this->renderCell($cell, $tag, $columnIndex, $isRowHeader, false);
                }
                $html .= '</tr>';
            }
        }
        $html .= '</tbody></table>';

        if ($this->responsive) {
            $html .= '</div>';
        }

        return $html;
    }

    function renderCell($cell, $tag, $columnIndex, $isRowHeader = false, $isHeader = false) {
        $value = $cell;
        $escape = true;
        $classes = array();
        $attributes = array();

        if (isset($this->columnClasses[$columnIndex]) && $this->columnClasses[$columnIndex] !== '') {
            $classes[] = $this->columnClasses[$columnIndex];
        }
        if (in_array($columnIndex, $this->noWrapColumns)) {
            $classes[] = 'text-nowrap';
        }

        if (is_array($cell)) {
            $value = isset($cell['value']) ? $cell['value'] : '';
            if (isset($cell['escape'])) {
                $escape = (bool) $cell['escape'];
            }
            if (!empty($cell['class'])) {
                $classes[] = $cell['class'];
            }
            if (!empty($cell['align'])) {
                $classes[] = 'text-' . $cell['align'];
            }
            if (isset($cell['colspan'])) {
                $attributes['colspan'] = (int) $cell['colspan'];
            }
            if (isset($cell['rowspan'])) {
                $attributes['rowspan'] = (int) $cell['rowspan'];
            }
            if (!empty($cell['attributes']) && is_array($cell['attributes'])) {
                foreach ($cell['attributes'] as $attributeName => $attributeValue) {
                    $attributes[$attributeName] = $attributeValue;
                }
            }
        }

        if ($isRowHeader) {
            $attributes['scope'] = 'row';
        }
        elseif ($isHeader) {
            $attributes['scope'] = 'col';
        }

        if (count($classes) > 0) {
            $attributes['class'] = trim(implode(' ', $classes));
        }

        $attrHtml = '';
        foreach ($attributes as $attributeName => $attributeValue) {
            $attrHtml .= ' ' . htmlspecialchars($attributeName, ENT_QUOTES, 'UTF-8') . '="' . htmlspecialchars((string) $attributeValue, ENT_QUOTES, 'UTF-8') . '"';
        }

        if (is_object($value) && method_exists($value, 'toHtml')) {
            $content = $value->toHtml();
        }
        else {
            $content = (string) $value;
            if ($escape) {
                $content = htmlspecialchars($content, ENT_QUOTES, 'UTF-8');
            }
        }

        return '<' . $tag . $attrHtml . '>' . $content . '</' . $tag . '>';
    }
}
/*
Copyright (c) 2008-2026 Michael Stauber, SOLARSPEED.NET
Copyright (c) 2008-2026 Team BlueOnyx, BLUEONYX.IT
All Rights Reserved.

1. Redistributions of source code must retain the above copyright 
   notice, this list of conditions and the following disclaimer.

2. Redistributions in binary form must reproduce the above copyright 
   notice, this list of conditions and the following disclaimer in 
   the documentation and/or other materials provided with the 
   distribution.

3. Neither the name of the copyright holder nor the names of its 
   contributors may be used to endorse or promote products derived 
   from this software without specific prior written permission.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS 
"AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT 
LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS 
FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE 
COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, 
INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, 
BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; 
LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER 
CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT 
LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN 
ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE 
POSSIBILITY OF SUCH DAMAGE.

You acknowledge that this software is not designed or intended for 
use in the design, construction, operation or maintenance of any 
nuclear facility.

*/
?>