<?php
require_once 'auth.php'; 
require_once '../db_connect.php'; 
date_default_timezone_set('America/New_York');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Event Dashboard</title>
    
    <script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js'></script>
    
    <style>
        body { font-family: sans-serif; margin: 0; background: #f9f9f9; }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1em 2em;
            background: #fff;
            border-bottom: 2px solid #eee;
        }
        .header h1 { margin: 0; font-size: 1.5em; }
        .header .user-info { font-weight: bold; }
        .header nav a {
            display: inline-block;
            padding: 0.6em 1em;
            background-color: #007bff;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            margin-left: 1em;
        }
        .header nav a.logout { background-color: #dc3545; }
        .header nav a.manage-links { background-color: #6c757d; }
        .header nav a:hover { opacity: 0.8; }
        
        #calendar-container {
            padding: 2em;
            max-width: 1200px;
            margin: 0 auto;
            background: #fff;
            margin-top: 2em;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        :root {
            --fc-border-color: #ddd;
            --fc-today-bg-color: #f0f8ff;
        }
        .fc-event { cursor: pointer; font-size: 0.8em; padding: 3px; }
        .fc-event-title { white-space: normal; }
    </style>
</head>
<body>

    <header class="header">
        <h1>Event Scheduler</h1>
        <div class="user-controls">
            <span class="user-info">
                Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?>!
            </span>
            <nav>
                <a href="manage_tags.php" class="manage-links">Manage Tags</a>
                <a href="default_assets.php" class="manage-links">Set Defaults</a>
                <a href="create_event.php">Create New Event</a>
                <a href="logout.php" class="logout">Logout</a>
            </nav>
        </div>
    </header>

    <div id="calendar-container">
        <div id="calendar"></div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var calendarEl = document.getElementById('calendar');
            
            var calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                themeSystem: 'standard',
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek'
                },
                events: 'api_events.php',
                editable: false, 
                selectable: true,
                navLinks: true, 
                eventClick: function(info) {
                    window.location.href = 'edit_event.php?id=' + info.event.id;
                }
            });
            
            calendar.render();
        });
    </script>

</body>
</html>