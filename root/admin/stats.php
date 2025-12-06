<?php
require_once 'auth.php';
require_once 'auth.php';
// require_role(['admin', 'user']); // Allow all authenticated users
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stream Command Center</title>
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* --- GLOBAL STYLES --- */
        body {
            font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background-color: #121212;
            color: #e0e0e0;
            margin: 0;
            padding: 20px;
        }

        h2 {
            font-weight: 300;
            margin-bottom: 20px;
            letter-spacing: 1px;
            color: #fff;
        }

        /* --- LAYOUT GRID --- */
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        /* --- CARD COMPONENT --- */
        .card {
            background: #1e1e1e;
            border: 1px solid #333;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.3);
            transition: transform 0.2s ease;
        }

        /* Special Border for Network Total */
        .card.grand-total {
            border: 1px solid #4db8ff;
        }

        /* Card Header */
        .card-header {
            background: #252525;
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #333;
        }

        .stream-title {
            font-size: 1.3rem;
            font-weight: 700;
            color: #4db8ff;
            text-transform: uppercase;
        }

        /* Stats Display */
        .stat-group {
            text-align: right;
        }

        .live-stat {
            font-size: 2rem;
            font-weight: 700;
            line-height: 1;
            color: #fff;
        }

        .peak-stat {
            font-size: 0.85rem;
            color: #ff5555;
            margin-top: 2px;
        }

        /* Sub-metrics Bar */
        .metrics-row {
            display: flex;
            background: #2a2a2a;
            padding: 10px 20px;
            justify-content: space-between;
            font-size: 0.9rem;
            color: #bbb;
            border-bottom: 1px solid #333;
        }

        .metrics-row b {
            color: #fff;
            margin-left: 5px;
        }

        /* Graph Container */
        .graph-container {
            padding: 10px;
            background: #1e1e1e;
            position: relative;
            height: 250px;
            width: 100%;
        }

        /* --- SERVER BREAKDOWN (Accordion) --- */
        .server-toggle {
            width: 100%;
            background: #222;
            border: none;
            color: #888;
            padding: 8px;
            font-size: 0.8rem;
            cursor: pointer;
            border-top: 1px solid #333;
            transition: background 0.2s;
        }

        .server-toggle:hover {
            background: #333;
            color: #fff;
        }

        .server-list {
            display: none;
            padding: 0;
            background: #151515;
        }

        .server-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 20px;
            border-bottom: 1px solid #222;
            font-size: 0.85rem;
        }

        .server-row:last-child {
            border-bottom: none;
        }

        .server-name {
            color: #aaa;
        }

        .server-val {
            color: #fff;
            font-weight: bold;
        }

        /* --- TIME CONTROLS --- */
        .controls-container {
            text-align: center;
            margin-bottom: 25px;
            background: #1e1e1e;
            display: inline-block;
            padding: 10px 20px;
            border-radius: 50px;
            border: 1px solid #333;
        }

        .time-btn {
            background: none;
            border: none;
            color: #666;
            cursor: pointer;
            font-size: 0.9rem;
            margin: 0 10px;
            font-weight: 600;
            transition: color 0.2s;
        }

        .time-btn:hover {
            color: #aaa;
        }

        .time-btn.active {
            color: #4db8ff;
            text-decoration: underline;
            text-underline-offset: 5px;
        }

        /* Helper for centering */
        .center-wrap {
            text-align: center;
        }
    </style>
</head>

<body>
    <?php include 'navbar.php'; ?>
    <div class="container">

        <div class="center-wrap">
            <h2>LIVE STREAM METRICS</h2>

            <!-- Time Selection Controls -->
            <div class="controls-container">
                <button class="time-btn" onclick="setPeriod('-1h')">1 Hour</button>
                <button class="time-btn" onclick="setPeriod('-3h')">3 Hours</button>
                <button class="time-btn active" onclick="setPeriod('-24h')">24 Hours</button>
                <button class="time-btn" onclick="setPeriod('-1week')">1 Week</button>
                <button class="time-btn" onclick="setPeriod('-3month')">3 Months</button>
                <button class="time-btn" onclick="setPeriod('-3year')">3 Years</button>
            </div>
        </div>

        <!-- NETWORK TOTAL CARD (Fixed at Top) -->
        <div class="card grand-total" style="max-width: 800px; margin: 0 auto;">
            <div class="card-header">
                <span class="stream-title">Network Total Demand</span>
                <div class="stat-group">
                    <div class="live-stat" id="stat-Network_Total">--</div>
                    <div class="peak-stat">Session Peak: <span id="peak-Network_Total">0</span></div>
                </div>
            </div>
            <div class="graph-container">
                <canvas id="chart-Network_Total"></canvas>
            </div>
        </div>

        <!-- STREAM CARDS GRID -->
        <div class="grid" id="grid-container">
            <!-- JavaScript will inject cards here -->
        </div>

        <!-- JAVASCRIPT LOGIC -->
        <script>
            // Configuration
            let currentPeriod = '-24h';
            const sessionPeaks = {}; // Tracks local session max values
            const charts = {}; // Store Chart instances

            // 1. Handle Time Period Switching
            function setPeriod(period) {
                currentPeriod = period;

                // Update UI buttons
                document.querySelectorAll('.time-btn').forEach(btn => btn.classList.remove('active'));
                event.target.classList.add('active');

                // Force refresh of graphs immediately
                updateDashboard(true);
            }

            // 2. Toggle Server Breakdown Accordion
            function toggleDetails(id) {
                const el = document.getElementById(id);
                if (el.style.display === 'block') {
                    el.style.display = 'none';
                } else {
                    el.style.display = 'block';
                }
            }

            // 3. Main Update Function
            async function updateDashboard(forceGraphRefresh = false) {
                try {
                    // Fetch JSON with cache busting
                    const response = await fetch('api_stats.php?nocache=' + Date.now());
                    if (!response.ok) throw new Error("Network response was not ok");

                    const data = await response.json();
                    const grid = document.getElementById('grid-container');

                    // --- A. UPDATE NETWORK TOTAL (Top Card) ---
                    if (data.grand_total !== undefined) {
                        // Update Text
                        document.getElementById('stat-Network_Total').innerText = data.grand_total.toLocaleString();

                        // Update Peak - DEPRECATED (Now calculated from graph)
                        // if (!sessionPeaks['Network_Total'] || data.grand_total > sessionPeaks['Network_Total']) {
                        //     sessionPeaks['Network_Total'] = data.grand_total;
                        // }
                        // document.getElementById('peak-Network_Total').innerText = sessionPeaks['Network_Total'].toLocaleString();

                        // Update Graph
                        updateGraph('Network_Total', currentPeriod, forceGraphRefresh);
                    }

                    // --- B. UPDATE STREAM CARDS ---
                    // We iterate over the 'totals' object in the JSON
                    for (const [key, stats] of Object.entries(data.totals)) {

                        let card = document.getElementById(`card-${key}`);

                        // 1. Create Card if it doesn't exist
                        if (!card) {
                            card = document.createElement('div');
                            card.className = 'card';
                            card.id = `card-${key}`;
                            card.innerHTML = `
                            <div class="card-header">
                                <span class="stream-title">${key}</span>
                                <div class="stat-group">
                                    <div class="live-stat" id="stat-${key}">--</div>
                                    <div class="peak-stat">Peak: <span id="peak-${key}">0</span></div>
                                </div>
                            </div>
                            <div class="metrics-row">
                                <span>High: <b id="h-${key}">0</b></span>
                                <span>Med: <b id="m-${key}">0</b></span>
                                <span>Low: <b id="l-${key}">0</b></span>
                            </div>
                            <div class="graph-container">
                                <canvas id="chart-${key}"></canvas>
                            </div>
                            <button class="server-toggle" onclick="toggleDetails('details-${key}')">Show Node Breakdown ▼</button>
                            <div class="server-list" id="details-${key}">
                                <!-- Node rows go here -->
                            </div>
                        `;
                            grid.appendChild(card);
                        }

                        // 2. Update Live Stats Text
                        const total = stats.total || 0;
                        document.getElementById(`stat-${key}`).innerText = total.toLocaleString();
                        document.getElementById(`h-${key}`).innerText = stats.high || 0;
                        document.getElementById(`m-${key}`).innerText = stats.med || 0;
                        document.getElementById(`l-${key}`).innerText = stats.low || 0;

                        // 3. Update Session Peak - DEPRECATED (Now calculated from graph)
                        // if (!sessionPeaks[key] || total > sessionPeaks[key]) {
                        //     sessionPeaks[key] = total;
                        // }
                        // document.getElementById(`peak-${key}`).innerText = sessionPeaks[key].toLocaleString();

                        // 4. Update Graph
                        updateGraph(key, currentPeriod, forceGraphRefresh);

                        // 5. Update Server Breakdown List
                        const listContainer = document.getElementById(`details-${key}`);
                        let listHtml = '';

                        if (data.nodes) {
                            for (const [nodeName, nodeStreams] of Object.entries(data.nodes)) {
                                if (nodeStreams[key]) {
                                    const nodeStats = nodeStreams[key];
                                    listHtml += `
                                    <div class="server-row">
                                        <span class="server-name">${nodeName}</span>
                                        <span class="server-val">${nodeStats.total}</span>
                                    </div>
                                `;
                                }
                            }
                        }

                        if (listHtml === '') listHtml = '<div class="server-row"><span class="server-name">No active nodes</span></div>';
                        listContainer.innerHTML = listHtml;
                    }

                } catch (error) {
                    console.error("Polling Error:", error);
                }
            }

            // 4. Chart.js Update Function
            async function updateGraph(key, period, force) {
                // Throttle updates: only update if force is true or last update was > 60s ago
                if (!force && charts[key] && (Date.now() - charts[key]._lastUpdated < 60000)) {
                    return;
                }

                try {
                    const response = await fetch(`api_graph_data.php?s=${key}&n=Global&p=${period}`);
                    const data = await response.json();

                    const ctx = document.getElementById(`chart-${key}`);
                    if (!ctx) return;

                    // Calculate Peak from Graph Data
                    let graphPeak = 0;
                    const isStacked = (key === 'Network_Total');

                    if (data.datasets && data.datasets.length > 0) {
                        // Check for "Total Peak" dataset first
                        const totalPeakIndex = data.datasets.findIndex(ds => ds.label === 'Total Peak');

                        if (totalPeakIndex !== -1) {
                            // Use Total Peak for the number
                            const totalPeakData = data.datasets[totalPeakIndex].data;
                            for (const val of totalPeakData) {
                                if (val > graphPeak) graphPeak = val;
                            }
                            // Remove it from datasets so it doesn't show on graph
                            data.datasets.splice(totalPeakIndex, 1);
                        } else {
                            // Fallback logic
                            if (isStacked) {
                                // For stacked, we need to sum up all datasets at each point
                                const length = data.datasets[0].data.length;
                                for (let i = 0; i < length; i++) {
                                    let sum = 0;
                                    for (const ds of data.datasets) {
                                        sum += (ds.data[i] || 0);
                                    }
                                    if (sum > graphPeak) graphPeak = sum;
                                }
                            } else {
                                // For individual streams
                                for (const ds of data.datasets) {
                                    for (const val of ds.data) {
                                        if (val > graphPeak) graphPeak = val;
                                    }
                                }
                            }
                        }
                    }

                    // Update Peak UI
                    const peakEl = document.getElementById(`peak-${key}`);
                    if (peakEl) {
                        peakEl.innerText = Math.ceil(graphPeak).toLocaleString();
                    }

                    if (charts[key]) {
                        charts[key].destroy();
                    }

                    const datasets = data.datasets.map((ds, index) => {
                        const colors = ['#4db8ff', '#ff5555', '#4caf50', '#ffeb3b', '#9c27b0'];
                        const color = colors[index % colors.length];

                        return {
                            label: ds.label,
                            data: ds.data,
                            borderColor: color,
                            backgroundColor: color + '44', // Transparent
                            fill: isStacked || ds.label === 'Average',
                            borderWidth: 1,
                            pointRadius: 0,
                            pointHitRadius: 10
                        };
                    });

                    charts[key] = new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: data.labels.map(t => {
                                const d = new Date(t);
                                if (period === '-1h' || period === '-3h') {
                                    return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                                } else {
                                    return d.toLocaleDateString([], { month: 'short', day: 'numeric' }) + ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                                }
                            }),
                            datasets: datasets
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            animation: false,
                            scales: {
                                x: {
                                    display: true,
                                    ticks: { maxTicksLimit: 8, color: '#888' },
                                    grid: { display: false }
                                },
                                y: {
                                    beginAtZero: true,
                                    grid: { color: '#333' },
                                    ticks: { color: '#888' },
                                    stacked: isStacked
                                }
                            },
                            plugins: {
                                legend: {
                                    display: true,
                                    labels: { color: '#ccc', boxWidth: 10 }
                                },
                                tooltip: {
                                    mode: 'index',
                                    intersect: false
                                }
                            },
                            interaction: {
                                mode: 'nearest',
                                axis: 'x',
                                intersect: false
                            },
                            elements: {
                                line: { tension: 0.2 }
                            }
                        }
                    });
                    charts[key]._lastUpdated = Date.now();

                } catch (e) {
                    console.error("Graph Error:", e);
                }
            }

            // Start the Loop
            // Poll every 2 seconds for live numbers
            setInterval(() => updateDashboard(false), 2000);

            // Initial Run
            updateDashboard(true);

        </script>
    </div>
</body>

</html>