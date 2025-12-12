<?php
// poller.php
// Run in background: php poller.php &

// 1. CONFIGURATION
$storage_dir = __DIR__ . '/data';
$json_file = $storage_dir . '/live_stats.json';
$ssh_user = 'root'; // The user allowed via Tailscale ACLs

// DEFINE NODES
// Use Tailscale IPs or MagicDNS names
$nodes = [
    'WRHU-Icecast01' => [
        'host' => '100.109.186.28',
        'map' => ['stream1-1.mp3' => 'WRHU', 'stream1-2.mp3' => 'WRHU', 'stream1-3.mp3' => 'WRHU', 'stream1-4.mp3' => 'WRHU', 'stream1-1-medium.mp3' => 'WRHU', 'stream1-2-medium.mp3' => 'WRHU', 'stream1-3-medium.mp3' => 'WRHU', 'stream1-4-medium.mp3' => 'WRHU', 'stream1-1-low.mp3' => 'WRHU', 'stream1-2-low.mp3' => 'WRHU', 'stream1-3-low.mp3' => 'WRHU', 'stream1-4-low.mp3' => 'WRHU', 'stream2-1.mp3' => 'HAWC', 'stream2-2.mp3' => 'HAWC', 'stream2-3.mp3' => 'HAWC', 'stream2-4.mp3' => 'HAWC', 'stream2-1-medium.mp3' => 'HAWC', 'stream2-2-medium.mp3' => 'HAWC', 'stream2-3-medium.mp3' => 'HAWC', 'stream2-4-medium.mp3' => 'HAWC', 'stream2-1-low.mp3' => 'HAWC', 'stream2-2-low.mp3' => 'HAWC', 'stream2-3-low.mp3' => 'HAWC', 'stream2-4-low.mp3' => 'HAWC', 'stream3-1.mp3' => 'SPEV', 'stream3-2.mp3' => 'SPEV', 'stream3-3.mp3' => 'SPEV', 'stream3-4.mp3' => 'SPEV', 'stream3-1-medium.mp3' => 'SPEV', 'stream3-2-medium.mp3' => 'SPEV', 'stream3-3-medium.mp3' => 'SPEV', 'stream3-4-medium.mp3' => 'SPEV', 'stream3-1-low.mp3' => 'SPEV', 'stream3-2-low.mp3' => 'SPEV', 'stream3-3-low.mp3' => 'SPEV', 'stream3-4-low.mp3' => 'SPEV']
    ],
    'WRHU-Server01' => [
        'host' => '100.100.63.43',
        'map' => ['stream1' => 'WRHU', 'stream2' => 'HAWC', 'stream3' => 'SPEV']
    ],
    'WRHU-Server03' => [
        'host' => '100.76.127.104',
        'map' => ['stream1' => 'WRHU', 'stream2' => 'HAWC', 'stream3' => 'SPEV']
    ],
    'WRHU-Server04' => [
        'host' => '100.97.65.29',
        'map' => ['stream1' => 'WRHU', 'stream2' => 'HAWC', 'stream3' => 'SPEV']
    ]
];

// 2. SETUP STORAGE
if (!is_dir($storage_dir))
    mkdir($storage_dir, 0777, true);

// 3. RRD DEFINITIONS

// Definition A: Node Metrics (Short Term / High Res)
// 1m resolution for 24h
// 1h resolution for 3 Months (2160 hours)
function create_node_rrd($filename)
{
    $cmd = "rrdtool create $filename --step 60 " .
        "DS:listeners:GAUGE:120:0:U " .
        "RRA:AVERAGE:0.5:1:1440 " .
        "RRA:MAX:0.5:1:1440 " .
        "RRA:AVERAGE:0.5:60:2160 " .
        "RRA:MAX:0.5:60:2160";
    shell_exec($cmd);
}

// Definition B: Global Aggregates (Long Term / Capacity Planning)
// 1m resolution for 24h
// 1h resolution for 3 Months
// 1d resolution for 3 Years (1095 days)
function create_aggregate_rrd($filename)
{
    $cmd = "rrdtool create $filename --step 60 " .
        "DS:listeners:GAUGE:120:0:U " .
        "RRA:AVERAGE:0.5:1:1440 " .
        "RRA:MAX:0.5:1:1440 " .
        "RRA:AVERAGE:0.5:60:2160 " .
        "RRA:MAX:0.5:60:2160 " .
        "RRA:AVERAGE:0.5:1440:1095 " .
        "RRA:MAX:0.5:1440:1095";
    shell_exec($cmd);
}

// 4. MAIN POLLER LOOP
echo "Starting Poller...\n";
$last_rrd_update = 0;

while (true) {
    $start_time = time();

    // Structure for JSON output
    $live_data = [
        'grand_total' => 0,
        'totals' => [],
        'nodes' => []
    ];

    // Structure for RRD Queuing
    $rrd_updates = [
        'nodes' => [],     // Key: WRHU_Origin-NY
        'aggregates' => [] // Key: WRHU
    ];

    // --- A. FETCH DATA ---
    foreach ($nodes as $node_name => $config) {

        // SSH Command (Tailscale optimized)
        // grep -r . outputs: filename:value
        $cmd = sprintf(
            "ssh -o StrictHostKeyChecking=no -o ConnectTimeout=2 %s@%s 'grep -r . /tmp/hls_stats'",
            $ssh_user,
            $config['host']
        );
        $output = shell_exec($cmd);

        if ($output) {
            $lines = explode("\n", trim($output));

            // Prepare node array in JSON
            $live_data['nodes'][$node_name] = [];

            foreach ($lines as $line) {
                // Parse: /path/stream1_total:50
                if (preg_match('/\/([^\/]+)_(total|high|medium|low|max):(\d+)$/', $line, $matches)) {
                    $raw_stream = $matches[1];
                    $metric = $matches[2];
                    $val = intval($matches[3]);

                    // Only process if mapped in config
                    if (isset($config['map'][$raw_stream])) {
                        $k = $config['map'][$raw_stream]; // e.g., WRHU

                        // 1. Initialize Objects if needed
                        if (!isset($live_data['totals'][$k])) {
                            $live_data['totals'][$k] = ['total' => 0, 'high' => 0, 'med' => 0, 'low' => 0];
                        }
                        if (!isset($live_data['nodes'][$node_name][$k])) {
                            $live_data['nodes'][$node_name][$k] = ['total' => 0, 'high' => 0, 'low' => 0];
                        }

                        // 2. Populate JSON Data
                        if ($metric == 'total') {
                            $live_data['totals'][$k]['total'] += $val;
                            $live_data['nodes'][$node_name][$k]['total'] += $val;
                            $live_data['grand_total'] += $val;
							echo $node_name.": ".$val."\n;";

                            // Queue RRD Updates
                            $rrd_updates['nodes']["{$k}_{$node_name}"] = $val;
                        } elseif ($metric == 'high') {
                            $live_data['totals'][$k]['high'] += $val;
                            $live_data['nodes'][$node_name][$k]['high'] += $val;
                        } elseif ($metric == 'medium') {
                            $live_data['totals'][$k]['med'] += $val;
                        } elseif ($metric == 'low') {
                            $live_data['totals'][$k]['low'] += $val;
                            $live_data['nodes'][$node_name][$k]['low'] += $val;
                        }
                    }
                }
            }
        } else {
			echo "No output from ".$node_name.".\n";
		}
    }

    // --- B. SAVE JSON (Instant Live View) ---
    file_put_contents($json_file, json_encode($live_data));

    // --- C. UPDATE RRDs (Every 60s) ---
    if (time() - $last_rrd_update >= 60) {
        echo "Updating RRDs... ";

        // 1. Node Specific RRDs (Short Retention)
        foreach ($rrd_updates['nodes'] as $key => $val) {
            $f = "$storage_dir/{$key}.rrd";
            if (!file_exists($f))
                create_node_rrd($f);
            shell_exec("rrdtool update $f N:$val");
        }

        // 2. Stream Aggregate RRDs (Long Retention)
        foreach ($live_data['totals'] as $key => $stats) {
            $f = "$storage_dir/{$key}_Global.rrd";
            if (!file_exists($f))
                create_aggregate_rrd($f);
            shell_exec("rrdtool update $f N:" . $stats['total']);
        }

        // 3. Network Total RRD (Long Retention)
        $f = "$storage_dir/Network_Total_Global.rrd";
        if (!file_exists($f))
            create_aggregate_rrd($f);
        shell_exec("rrdtool update $f N:" . $live_data['grand_total']);

        echo "Done.\n";
        $last_rrd_update = time();
    }

    // Smart Sleep (aim for ~3s cycle)
    $elapsed = time() - $start_time;
    $sleep = max(1, 3 - $elapsed);
    sleep($sleep);
}
?>