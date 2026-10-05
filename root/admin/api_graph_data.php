<?php
require_once 'auth.php';
require_once 'auth.php';
// require_role(['admin', 'user']); // Allow all authenticated users

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
    $sum_parts = [];
    if ($files) {
        foreach ($files as $file) {
            $basename = basename($file, '_Global.rrd');
            if ($basename === 'Network_Total')
                continue;

            $cmd_parts[] = "DEF:val$i=$file:listeners:AVERAGE";
            $cmd_parts[] = "DEF:max$i=$file:listeners:MAX";
            $xport_parts[] = "XPORT:val$i:\"$basename\"";
            $legends[] = $basename;

            $sum_parts[] = "max$i";
            $i++;
        }

        if (!empty($sum_parts)) {
            // RPN: val1,val2,+,val3,+...
            $cdef_expr = $sum_parts[0];
            for ($k = 1; $k < count($sum_parts); $k++) {
                $cdef_expr .= "," . $sum_parts[$k] . ",+";
            }
            $cmd_parts[] = "CDEF:totalMax=$cdef_expr";
            $cmd_parts[] = "CDEF:totalMaxCeil=totalMax,CEIL";
            $xport_parts[] = "XPORT:totalMaxCeil:\"Total Peak\"";
            $legends[] = "Total Peak";
        }
    }
} else {
    $rrd_file = "$storage_dir/{$stream}_{$node}.rrd";
    if (file_exists($rrd_file)) {
        $cmd_parts[] = "DEF:avg=$rrd_file:listeners:AVERAGE";
        $cmd_parts[] = "DEF:max=$rrd_file:listeners:MAX";
        $cmd_parts[] = "CDEF:maxCeil=max,CEIL";
        $xport_parts[] = "XPORT:avg:\"Average\"";
        $xport_parts[] = "XPORT:maxCeil:\"Peak\"";
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

// Debug logging
error_log("RRDTool Output Length: " . strlen($xml_string));
if (strlen($xml_string) < 100) {
    error_log("RRDTool Output Snippet: " . $xml_string);
}

$labels = [];
$datasets = [];

// Initialize datasets
foreach ($legends as $index => $legend) {
    $datasets[$index] = [
        'label' => $legend,
        'data' => [],
    ];
}

// Parse Meta for Start and Step
$start = 0;
$step = 300; // Default

if (preg_match('/<start>(\d+)<\/start>/', $xml_string, $m)) {
    $start = (int) $m[1];
}
if (preg_match('/<step>(\d+)<\/step>/', $xml_string, $m)) {
    $step = (int) $m[1];
}

// Regex to find rows: <row><v>VALUE</v><v>VALUE</v>...</row>
// Note: RRDTool xport XML might NOT have <t> tags inside <row>
if (preg_match_all('/<row>(.*?)<\/row>/si', $xml_string, $row_matches)) {
    error_log("Found " . count($row_matches[1]) . " rows.");

    $currentRow = 0;
    foreach ($row_matches[1] as $row_content) {
        // Calculate timestamp
        // Timestamp for row i is start + step * (i + 1)
        $t = $start + ($step * ($currentRow + 1));
        $labels[] = $t * 1000;

        // Extract values
        if (preg_match_all('/<v>\s*(.*?)\s*<\/v>/i', $row_content, $v_matches)) {
            foreach ($v_matches[1] as $i => $val) {
                if (strcasecmp($val, 'NaN') === 0) {
                    $v = null;
                } else {
                    $v = (float) $val;
                }

                if (isset($datasets[$i])) {
                    $datasets[$i]['data'][] = $v;
                }
            }
        }
        $currentRow++;
    }
} else {
    error_log("No rows matched regex in RRDTool output.");
    // Log a snippet of the XML to see what it looks like
    error_log("XML Snippet: " . substr($xml_string, 0, 500));
}

echo json_encode([
    'labels' => $labels,
    'datasets' => $datasets
]);
?>