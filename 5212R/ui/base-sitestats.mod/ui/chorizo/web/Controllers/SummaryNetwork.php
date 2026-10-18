<?php
namespace Sitestats\Controllers;
use App\Controllers\BaseController;
include_once("I18n.php");
include_once("BxPage.php");
use I18n;
use BxPage;

class SummaryNetwork extends BaseController {

    public function __construct() {
    }

    /**
     * Server network accounting (nft table inet bxacct).
     * Live counters plus hourly history from /var/log/ipacct.
     * Analog is not used.
     */
    public function index() {

        $CI = get_instance();

        if ((!$CI->getAllowed('adminUser')) && (!$CI->getAllowed('serverStatsServerNetwork'))) {
            $CI->cceClient->bye();
            $CI->serverScriptHelper->destructor();
            Log403Error("/gui/Forbidden403");
        }

        $factory = $CI->serverScriptHelper->getHtmlComponentFactory("base-sitestats", "/sitestats/summaryNetwork");
        $BxPage = $factory->getPage();
        $i18n = $factory->getI18n();
        $BxPage->setGETPOST(array('FORM_GET' => $this->request->getGet(), 'FORM_POST' => $this->request->getPost(), 'AGENT' => $this->request->getUserAgent()));
        $errors = $BxPage->getErrors();

        $live = $this->readLiveCounters($CI);
        $history = $this->readHourlyHistory();

        $BxPage->setFormUrl("/sitestats/summaryNetwork");
        $BxPage->setErrors($errors);
        $BxPage->setVerticalMenu('base_serverusage');
        $BxPage->setVerticalMenuChild('base_server_netusage');
        $page_module = 'base_sysmanage';

        $pages = array('netLiveTitle');
        if (count($history['in']) > 0) {
            $pages[] = 'netHistoryTitle';
        }
        $block = $factory->getPagedBlock("netusage", $pages);
        $block->setToggle("#");
        $block->setSideTabs(FALSE);
        $block->setShowAllTabs('#');
        $block->setDefaultPage('netLiveTitle');

        $this->addLiveMetricsChart($factory, $BxPage, $i18n, $block);

        $loopNote = $factory->getRawHTML("netLoopbackNoteHtml", '<p>' . $i18n->getHtml("[[base-sitestats.netLoopbackNote]]") . '</p>');
        $block->addFormField($loopNote, $factory->getLabel(" "), 'netLiveTitle');

        if (count($live) === 0) {
            $emptyLive = $factory->getRawHTML("netNoDataLiveHtml", '<p>' . $i18n->getHtml("[[base-sitestats.netNoData]]") . '</p>');
            $block->addFormField($emptyLive, $factory->getLabel(" "), 'netLiveTitle');
        }
        else {
            // Live counters are a snapshot per IP, not a time series.
            // A line chart across IP addresses is meaningless; show the
            // inbound/outbound share since the last hourly reset instead.
            $sumIn = 0;
            $sumOut = 0;
            foreach ($live as $row) {
                $sumIn += $row['in'];
                $sumOut += $row['out'];
            }
            if (($sumIn + $sumOut) > 0) {
                $pieData = array(
                    $i18n->get("[[base-sitestats.netIn]]") => $sumIn,
                    $i18n->get("[[base-sitestats.netOut]]") => $sumOut,
                );
                $pie = $factory->getPieChart("netLivePie", $pieData);
                $pie->setSize("590", "320");
                $pie->setShowSliceLabels(true);
                $block->addFormField($pie, "", 'netLiveTitle');
            }

            $list = $factory->getScrollList('netLiveList', array('netIp', 'netIn', 'netOut', 'netTotal'), array());
            $list->setAlignments(array('left', 'right', 'right', 'right'));
            $list->setDefaultSortedIndex('3');
            $list->setSortOrder('descending');
            $list->setPaginateDisabled(TRUE);
            $list->setSearchDisabled(TRUE);
            $list->setSelectorDisabled(TRUE);
            $list->setInfoDisabled(TRUE);
            $list->enableAutoWidth(FALSE);
            $i = 0;
            foreach ($live as $ip => $row) {
                $cIp = $factory->getRawHTML("liveIp$i", htmlspecialchars($ip, ENT_QUOTES, 'UTF-8'));
                $cIn = $factory->getRawHTML("liveIn$i", htmlspecialchars($this->formatBytes($row['in']), ENT_QUOTES, 'UTF-8'));
                $cOut = $factory->getRawHTML("liveOut$i", htmlspecialchars($this->formatBytes($row['out']), ENT_QUOTES, 'UTF-8'));
                $cTot = $factory->getRawHTML("liveTot$i", htmlspecialchars($this->formatBytes($row['in'] + $row['out']), ENT_QUOTES, 'UTF-8'));
                $list->addEntry(array($cIp, $cIn, $cOut, $cTot), "", false, $i);
                $i++;
            }
            $listHtml = $factory->getRawHTML("netLiveListHtml", $list->toHtml());
            $block->addFormField($listHtml, $factory->getLabel(" "), 'netLiveTitle');
        }

        if (count($history['in']) > 0) {
            $histData = array(
                $i18n->get("[[base-sitestats.netIn]]") => $history['in'],
                $i18n->get("[[base-sitestats.netOut]]") => $history['out'],
            );
            $histGraph = $factory->getBarGraph("netHistGraph", $histData, $history['labels']);
            $histGraph->setXLabel("MiB");
            $block->addFormField($histGraph, "", 'netHistoryTitle');
        }

        $page_body[] = $block->toHtml();
        return $BxPage->render($page_module, $page_body);
    }

    /**
     * Realtime RX/TX rates from CCEd-API /v2/metrics via /gui/metrics.
     * Same source as swupdate/news "Network Usage", shown larger here.
     * renderSingleFlotChartDeltas() dumps the plot into extraFooters;
     * we host a slot in the Live tab and move the container into it.
     */
    private function addLiveMetricsChart($factory, $BxPage, $i18n, $block) {
        $title = htmlspecialchars($i18n->get("[[palette.NetworkUsage]]"), ENT_QUOTES, 'UTF-8');
        $maximize = htmlspecialchars($i18n->getWrapped("[[palette.icon_maximize]]"), ENT_QUOTES, 'UTF-8');
        $host = <<<HTML
                                <div class="panel panel-default card-view">
                                    <div class="panel-heading">
                                        <div class="pull-left">
                                            <h6 class="panel-title txt-dark">$title</h6>
                                        </div>
                                        <div class="pull-right">
                                            <a href="#" class="pull-left inline-block full-screen mr-15">
                                                <span data-toggle="tooltip" data-placement="bottom" title="$maximize" data-original-title="$maximize" data-container="body"><i class="zmdi zmdi-fullscreen"></i></span>
                                            </a>
                                        </div>
                                        <div class="clearfix"></div>
                                    </div>
                                    <div class="panel-wrapper collapse in">
                                        <div class="panel-body">
                                            <div id="netLiveTrafficHost"></div>
                                        </div>
                                    </div>
                                </div>
HTML;
        $hostHtml = $factory->getRawHTML("netLiveTrafficHostHtml", $host);
        $block->addFormField($hostHtml, "", 'netLiveTitle');

        $extraFooters = <<<HTML

                <style>
                    .flot-tooltip {
                        color: #fff !important;
                        background-color: #000 !important;
                        border: 1px solid #fff !important;
                        font-size: 10px !important;
                        padding: 8px !important;
                    }
                </style>

                <!-- Flot Charts JavaScript -->
                <script src="/.elm/vendors/bower_components/Flot/excanvas.min.js"></script>
                <script src="/.elm/vendors/bower_components/Flot/jquery.flot.js"></script>
                <script src="/.elm/vendors/bower_components/Flot/jquery.flot.categories.js"></script>
                <script src="/.elm/vendors/bower_components/Flot/jquery.flot.pie.js"></script>
                <script src="/.elm/vendors/bower_components/Flot/jquery.flot.resize.js"></script>
                <script src="/.elm/vendors/bower_components/Flot/jquery.flot.time.js"></script>
                <script src="/.elm/vendors/bower_components/Flot/jquery.flot.stack.js"></script>
                <script src="/.elm/vendors/bower_components/Flot/jquery.flot.crosshair.js"></script>
                <script src="/.elm/vendors/bower_components/flot.tooltip/js/jquery.flot.tooltip.min.js"></script>
HTML;
        $BxPage->setExtraFooters($extraFooters);

        $labels = json_encode(aggnet_chart_labels($i18n), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $chartId = 'netLiveTraffic';
        $metricKey = 'aggnet';
        $height = '420px';
        $theme = 'light';
        $spacing = '2.5';
        renderSingleFlotChartDeltas($BxPage, $chartId, $metricKey, $labels, $i18n, $height, $theme, $spacing);

        $BxPage->setExtraFooters(<<<HTML
                <script>
                (function () {
                    var host = document.getElementById("netLiveTrafficHost");
                    var el = document.getElementById("netLiveTraffic");
                    if (host && el) {
                        var box = el.closest ? el.closest(".flot-container") : el.parentNode;
                        if (box) {
                            host.appendChild(box);
                        }
                    }
                })();
                </script>
HTML
        );
    }

    /**
     * Parse `nft list table inet bxacct` via the root helper.
     * Loopback addresses are dropped.
     */
    private function readLiveCounters($CI) {
        $raw = '';
        $sess = $CI->getBX_SESSION();
        $CI->serverScriptHelper->shell("/usr/sausalito/sbin/bxacct_dump.sh", $raw, 'root', $sess['sessionId']);
        $live = array();
        foreach (preg_split("/\r\n|\n|\r/", (string)$raw) as $line) {
            $line = trim($line);
            if (preg_match('/ip daddr ([0-9.]+) counter packets [0-9]+ bytes ([0-9]+)/', $line, $m)) {
                if ($this->isLoopback($m[1])) {
                    continue;
                }
                if (!isset($live[$m[1]])) {
                    $live[$m[1]] = array('in' => 0, 'out' => 0);
                }
                $live[$m[1]]['in'] = intval($m[2]);
            }
            elseif (preg_match('/ip saddr ([0-9.]+) counter packets [0-9]+ bytes ([0-9]+)/', $line, $m)) {
                if ($this->isLoopback($m[1])) {
                    continue;
                }
                if (!isset($live[$m[1]])) {
                    $live[$m[1]] = array('in' => 0, 'out' => 0);
                }
                $live[$m[1]]['out'] = intval($m[2]);
            }
        }
        uasort($live, function ($a, $b) {
            return (($b['in'] + $b['out']) - ($a['in'] + $a['out']));
        });
        return $live;
    }

    /**
     * Hourly inbound/outbound totals from /var/log/ipacct for the last 24 hours.
     */
    private function readHourlyHistory() {
        $path = '/var/log/ipacct';
        $result = array('labels' => array(), 'in' => array(), 'out' => array());
        if (!is_readable($path)) {
            return $result;
        }
        $now = new \DateTime();
        $buckets = array();
        for ($h = 23; $h >= 0; $h--) {
            $slot = clone $now;
            $slot->modify('-' . $h . ' hours');
            $key = $slot->format('Y-m-d-H');
            $buckets[$key] = array('in' => 0, 'out' => 0, 'label' => $slot->format('d/M H') . ':00');
        }
        $fh = fopen($path, 'r');
        if ($fh === false) {
            return $result;
        }
        while (($line = fgets($fh)) !== false) {
            if (!preg_match('/^([0-9.]+) - - \[([^\]]+)\] "GET \/(acctin|acctout) HTTP\/1\.1" 200 ([0-9]+)/', $line, $m)) {
                continue;
            }
            $ip = $m[1];
            if ($this->isLoopback($ip)) {
                continue;
            }
            $when = $m[2];
            $dir = $m[3];
            $bytes = intval($m[4]);
            $key = $this->acctHourKey($when);
            if ($key === false || !isset($buckets[$key])) {
                continue;
            }
            if ($dir === 'acctin') {
                $buckets[$key]['in'] += $bytes;
            }
            else {
                $buckets[$key]['out'] += $bytes;
            }
        }
        fclose($fh);

        $i = 0;
        $any = 0;
        foreach ($buckets as $row) {
            $result['labels'][$i] = $row['label'];
            $result['in'][$i] = $this->bytesToMib($row['in']);
            $result['out'][$i] = $this->bytesToMib($row['out']);
            $any += $row['in'] + $row['out'];
            $i++;
        }
        if ($any === 0) {
            return array('labels' => array(), 'in' => array(), 'out' => array());
        }
        return $result;
    }

    private function isLoopback($ip) {
        return (strpos($ip, '127.') === 0);
    }

    /**
     * Parse log_traffic stamp "17/Oct/2026:11:17:47 -0500" to Y-m-d-H.
     */
    private function acctHourKey($when) {
        if (!preg_match('/^(\d{2})\/([A-Za-z]{3})\/(\d{4}):(\d{2}):/', $when, $m)) {
            return false;
        }
        $months = array(
            'Jan' => 1, 'Feb' => 2, 'Mar' => 3, 'Apr' => 4,
            'May' => 5, 'Jun' => 6, 'Jul' => 7, 'Aug' => 8,
            'Sep' => 9, 'Oct' => 10, 'Nov' => 11, 'Dec' => 12,
        );
        if (!isset($months[$m[2]])) {
            return false;
        }
        return sprintf('%04d-%02d-%02d-%02d', intval($m[3]), $months[$m[2]], intval($m[1]), intval($m[4]));
    }

    private function bytesToMib($bytes) {
        return round($bytes / 1048576, 3);
    }

    private function formatBytes($bytes) {
        $bytes = floatval($bytes);
        if ($bytes < 1024) {
            return sprintf('%d B', $bytes);
        }
        if ($bytes < 1048576) {
            return sprintf('%.1f KiB', $bytes / 1024);
        }
        if ($bytes < 1073741824) {
            return sprintf('%.1f MiB', $bytes / 1048576);
        }
        return sprintf('%.2f GiB', $bytes / 1073741824);
    }
}
/*
Copyright (c) 2026 Michael Stauber, SOLARSPEED.NET
Copyright (c) 2026 Team BlueOnyx, BLUEONYX.IT
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
