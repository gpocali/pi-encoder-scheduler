<?php
require_once 'auth.php';
require_role(['admin', 'user']);

// graph.php
header('Content-Type: image/png');

$storage_dir = __DIR__ . '/../data';
$stream = $_GET['s'] ?? 'WRHU';
$node = $_GET['n'] ?? 'Global';
$period = $_GET['p'] ?? '-24h';

// Sanitize
$stream = preg_replace('/[^a-zA-Z0-9_-]/', '', $stream);
$node = preg_replace('/[^a-zA-Z0-9_-]/', '', $node);

// SPECIAL CASE: Network Total Stacked Graph
if ($stream === 'Network_Total') {

    // Find all stream aggregate files to stack
    $files = glob("$storage_dir/*_Global.rrd");
    $cmd_parts = [];
    $draw_parts = [];

    $colors = ['#4db8ff', '#ff5555', '#4caf50', '#ffeb3b', '#9c27b0']; // Color palette
    $i = 0;

    $cmd_def = "rrdtool graph - --start $period --width 500 --height 150 " .
        "--color CANVAS#1e1e1e --color BACK#1e1e1e --color FONT#cccccc --border 0 ";

    foreach ($files as $file) {
        $basename = basename($file, '_Global.rrd');
        // Filter out the Network_Total file itself to avoid double counting
        if ($basename === 'Network_Total')
            continue;

        $color = $colors[$i % count($colors)];

        // DEF:stream1=...
        $cmd_parts[] = "DEF:val$i=$file:listeners:AVERAGE";

        // CDEF to calculate stack (if i>0, add previous)
        // This is complex in RRD, so we use the AREA:STACK command which is easier
        $draw_parts[] = "AREA:val$i$color:\"$basename\":STACK";

        $i++;
    }

    $full_cmd = $cmd_def . implode(" ", $cmd_parts) . " " . implode(" ", $draw_parts);
    passthru($full_cmd);
    exit;
}

// STANDARD CASE (Single Node or Single Stream Aggregate)
$rrd_file = "$storage_dir/{$stream}_{$node}.rrd";

if (!file_exists($rrd_file)) {
    $im = imagecreate(1, 1);
    imagecolorallocate($im, 29, 29, 29); // Grey placeholder
    imagepng($im);
    exit;
}

$cmd = "rrdtool graph - " .
    "--start $period " .
    "--width 400 --height 120 " .
    "--color CANVAS#1e1e1e --color BACK#1e1e1e --color FONT#cccccc " .
    "--border 0 " .
    "DEF:avg_listeners=$rrd_file:listeners:AVERAGE " .
    "DEF:max_listeners=$rrd_file:listeners:MAX " .
    "AREA:avg_listeners#4db8ffaa:\"Average\" " .
    "LINE1:max_listeners#ff5555:\"Peak\" ";

passthru($cmd);
?>