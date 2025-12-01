<?php
require_once 'auth.php';
require_role(['admin', 'user']);

header('Content-Type: application/json');

$storage_dir = __DIR__ . '/../data';
$stream = $_GET['s'] ?? 'WRHU';
$node = $_GET['n'] ?? 'Global';
$period = $_GET['p'] ?? '-24h';

// Sanitize
$stream = preg_replace('/[^a-zA-Z0-9_-]/', '', $stream);
$node = preg_replace('/[^a-zA-Z0-9_-]/', '', $node);

$cmd_parts = [];
$xport_parts = [];
$legends = [];

if ($stream === 'Network_Total') {
    $files = glob("$storage_dir/*_Global.rrd");
    $i = 0;
    if ($files) {
        foreach ($files as $file) {
            $basename = basename($file, '_Global.rrd');
            if ($basename === 'Network_Total')
                continue;

            $cmd_parts[] = "DEF:val$i=$file:listeners:AVERAGE";
            $xport_parts[] = "XPORT:val$i:\"$basename\"";
            $legends[] = $basename;
            $i++;
        }
    }
} else {
    $rrd_file = "$storage_dir/{$stream}_{$node}.rrd";
    if (file_exists($rrd_file)) {
        $cmd_parts[] = "DEF:avg=$rrd_file:listeners:AVERAGE";
        $cmd_parts[] = "DEF:max=$rrd_file:listeners:MAX";
        $xport_parts[] = "XPORT:avg:\"Average\"";
        $xport_parts[] = "XPORT:max:\"Peak\"";
        $legends = ["Average", "Peak"];
    }
}

if (empty($cmd_parts)) {
    echo json_encode(['labels' => [], 'datasets' => []]);
    exit;
}

// Construct command
// Added --maxrows 400 to limit data points
$cmd = "rrdtool xport --start $period --end now --maxrows 400 " . implode(" ", $cmd_parts) . " " . implode(" ", $xport_parts);

// Execute
exec($cmd, $output, $return_var);

if ($return_var !== 0) {
    echo json_encode(['error' => 'RRDTool failed']);
    exit;
}

// Parse XML
$xml_string = implode("\n", $output);
// Suppress warnings for invalid XML just in case
libxml_use_internal_errors(true);
$xml = simplexml_load_string($xml_string);

if ($xml === false) {
    echo json_encode(['error' => 'Failed to parse XML']);
    exit;
}

$labels = [];
$datasets = [];

// Initialize datasets
foreach ($legends as $index => $legend) {
    $datasets[$index] = [
        'label' => $legend,
        'data' => [],
        // We don't set style here, frontend will handle it
    ];
}

if (isset($xml->data->row)) {
    foreach ($xml->data->row as $row) {
        $t = (string) $row->t;
        $labels[] = (int) $t * 1000; // JS timestamp

        $i = 0;
        foreach ($row->v as $val) {
            $v = (string) $val;
            if ($v === 'NaN')
                $v = null;
            else
                $v = (float) $v;

            if (isset($datasets[$i])) {
                $datasets[$i]['data'][] = $v;
            }
            $i++;
        }
    }
}

echo json_encode([
    'labels' => $labels,
    'datasets' => $datasets
]);
?>