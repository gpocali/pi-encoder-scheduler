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

// Parse XML using Regex (fallback since simplexml might be missing)
$xml_string = implode("\n", $output);

$labels = [];
$datasets = [];

// Initialize datasets
foreach ($legends as $index => $legend) {
    $datasets[$index] = [
        'label' => $legend,
        'data' => [],
    ];
}

// Regex to find rows: <row><t>TIMESTAMP</t><v>VALUE</v><v>VALUE</v>...</row>
if (preg_match_all('/<row>(.*?)<\/row>/s', $xml_string, $row_matches)) {
    foreach ($row_matches[1] as $row_content) {
        // Extract timestamp
        if (preg_match('/<t>(\d+)<\/t>/', $row_content, $t_match)) {
            $t = (int) $t_match[1];
            $labels[] = $t * 1000;

            // Extract values
            if (preg_match_all('/<v>(.*?)<\/v>/', $row_content, $v_matches)) {
                foreach ($v_matches[1] as $i => $val) {
                    if ($val === 'NaN') {
                        $v = null;
                    } else {
                        $v = (float) $val;
                    }

                    if (isset($datasets[$i])) {
                        $datasets[$i]['data'][] = $v;
                    }
                }
            }
        }
    }
}

echo json_encode([
    'labels' => $labels,
    'datasets' => $datasets
]);
?>