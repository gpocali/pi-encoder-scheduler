<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$current_page = basename($_SERVER['PHP_SELF']);
$user_role = $_SESSION['role'] ?? 'guest';
$user_id_nav = $_SESSION['user_id'] ?? 0;
?>
<!-- Bootstrap Icons -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<nav class="navbar">
    <div class="container navbar-content" style="display:flex; justify-content:space-between; align-items:center;">
        <div style="display:flex; align-items:center; gap:20px;">
            <a href="index.php" class="navbar-brand" style="font-size:1.5em; font-weight:bold; color:var(--primary-color); text-decoration:none;">
                <i class="bi bi-broadcast"></i> WRHU Scheduler
            </a>
            <ul class="navbar-nav" style="display:flex; gap:15px; list-style:none; margin:0; padding:0;">
                <li>
                    <a href="index.php" class="<?php echo $current_page == 'index.php' ? 'active' : ''; ?>" style="color:#fff; text-decoration:none;">
                        <i class="bi bi-speedometer2"></i> Dashboard
                    </a>
                </li>
                <li>
                    <a href="manage_assets.php" class="<?php echo $current_page == 'manage_assets.php' ? 'active' : ''; ?>" style="color:#fff; text-decoration:none;">
                        <i class="bi bi-collection-play"></i> Assets
                    </a>
                </li>
                <li>
                    <a href="create_event.php" class="<?php echo $current_page == 'create_event.php' ? 'active' : ''; ?>" style="color:#fff; text-decoration:none;">
                        <i class="bi bi-calendar-plus"></i> Create Event
                    </a>
                </li>
                <?php if ($user_role === 'admin'): ?>
                    <li>
                        <a href="manage_users.php" class="<?php echo $current_page == 'manage_users.php' ? 'active' : ''; ?>" style="color:#fff; text-decoration:none;">
                            <i class="bi bi-people"></i> Users
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
        <div class="navbar-user" style="display:flex; align-items:center; gap:15px;">
            <button id="global-upload-btn" class="btn btn-sm" style="background:var(--accent-color); border:none; color:#000; cursor:pointer; padding:5px 10px; border-radius:4px; font-weight:bold;">
                <i class="bi bi-cloud-upload"></i> Upload Asset
            </button>
            
            <div class="dropdown">
                <a href="#" style="color:#aaa; text-decoration:none; display:flex; align-items:center; gap:5px;">
                    <i class="bi bi-person-circle"></i> <?php echo htmlspecialchars($_SESSION['username'] ?? 'User'); ?> <i class="bi bi-caret-down-fill" style="font-size:0.8em;"></i>
                </a>
                <div class="dropdown-content">
                    <a href="profile.php"><i class="bi bi-gear"></i> Profile Settings</a>
                    <div class="dropdown-divider"></div>
                    <a href="logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a>
                </div>
            </div>
        </div>
    </div>
</nav>

<!-- Global Upload Modal -->
<div id="globalUploadModal" class="modal" style="display:none; position:fixed; z-index:1000; left:0; top:0; width:100%; height:100%; overflow:auto; background-color:rgba(0,0,0,0.8);">
    <div class="modal-content" style="background-color:#222; margin:10% auto; padding:20px; border:1px solid #444; width:80%; max-width:600px; color:#fff; border-radius:8px;">
        <span class="close" onclick="closeUploadModal()" style="color:#aaa; float:right; font-size:28px; font-weight:bold; cursor:pointer;">&times;</span>
        <h2><i class="bi bi-cloud-upload"></i> Upload Asset</h2>
        <form id="globalUploadForm" enctype="multipart/form-data">
            <div class="form-group" style="margin-bottom:15px;">
                <label style="display:block; margin-bottom:5px;">Select Tags</label>
                
                <!-- Tag Buttons Container -->
                <div class="tag-button-group" id="globalTagButtons" style="display:flex; flex-wrap:wrap; gap:8px; max-height:150px; overflow-y:auto; padding:5px; border:1px solid #444; border-radius:4px; background:#1a1a1a;">
                    <?php
                    // Ensure tags are available
                    if (!isset($tags)) {
                        require_once '../db_connect.php'; // Ensure DB connection
                        if ($user_role === 'admin' || $user_role === 'user') {
                            $stmt = $pdo->query("SELECT id, tag_name FROM tags ORDER BY tag_name");
                            $tags_nav = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        } else {
                            $stmt = $pdo->prepare("SELECT t.id, t.tag_name FROM tags t JOIN user_tags ut ON t.id = ut.tag_id WHERE ut.user_id = ? ORDER BY t.tag_name");
                            $stmt->execute([$user_id_nav]);
                            $tags_nav = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        }
                    } else {
                        $tags_nav = $tags;
                    }

                    foreach ($tags_nav as $tag): ?>
                        <button type="button" class="tag-btn" data-id="<?php echo $tag['id']; ?>" onclick="toggleGlobalTag(this)" style="padding:6px 12px; background:#333; border:1px solid #555; color:#ccc; border-radius:20px; cursor:pointer; font-size:0.9em;">
                            <?php echo htmlspecialchars($tag['tag_name']); ?>
                        </button>
                    <?php endforeach; ?>
                </div>
                
                <!-- Hidden Inputs Container -->
                <div id="globalTagInputs"></div>
            </div>

            <div class="form-group" style="margin-bottom:15px;">
                <label style="display:block; margin-bottom:5px;">File</label>
                <input type="file" name="asset" required style="width:100%; padding:8px; background:#333; border:1px solid #444; color:#fff; border-radius:4px;">
            </div>
            <button type="submit" class="btn" style="background:var(--accent-color); color:#000; border:none; padding:10px 20px; border-radius:4px; cursor:pointer; font-weight:bold;">
                <i class="bi bi-upload"></i> Upload
            </button>
            <div id="uploadStatus" class="message" style="display:none; margin-top:10px; padding:10px; border-radius:4px;"></div>
        </form>
    </div>
</div>

<script>
    // Modal Logic
    const uploadModal = document.getElementById('globalUploadModal');
    const uploadBtn = document.getElementById('global-upload-btn');

    if (uploadBtn) {
        uploadBtn.onclick = function (e) {
            e.preventDefault();
            uploadModal.style.display = "block";
            loadLastTags();
        }
    }

    function closeUploadModal() {
        uploadModal.style.display = "none";
    }

    window.onclick = function (event) {
        if (event.target == uploadModal) {
            uploadModal.style.display = "none";
        }
    }

    // Tag Selection Logic
    function toggleGlobalTag(btn) {
        btn.classList.toggle('active');
        updateGlobalTagStyles(btn);
        updateGlobalHiddenInputs();
    }

    function updateGlobalTagStyles(btn) {
        if (btn.classList.contains('active')) {
            btn.style.background = 'var(--accent-color)';
            btn.style.color = '#000';
            btn.style.borderColor = 'var(--accent-color)';
            btn.style.fontWeight = 'bold';
        } else {
            btn.style.background = '#333';
            btn.style.color = '#ccc';
            btn.style.borderColor = '#555';
            btn.style.fontWeight = 'normal';
        }
    }

    function updateGlobalHiddenInputs() {
        const container = document.getElementById('globalTagInputs');
        container.innerHTML = '';
        const activeBtns = document.querySelectorAll('#globalTagButtons .tag-btn.active');
        activeBtns.forEach(btn => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'tag_ids[]';
            input.value = btn.dataset.id;
            container.appendChild(input);
        });
        
        // Save to local storage
        const selectedIds = Array.from(activeBtns).map(btn => btn.dataset.id);
        localStorage.setItem('lastUploadTags', JSON.stringify(selectedIds));
    }

    function loadLastTags() {
        const lastTags = JSON.parse(localStorage.getItem('lastUploadTags') || '[]');
        const buttons = document.querySelectorAll('#globalTagButtons .tag-btn');
        buttons.forEach(btn => {
            if (lastTags.includes(btn.dataset.id)) {
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
            updateGlobalTagStyles(btn);
        });
        updateGlobalHiddenInputs();
    }

    // AJAX Upload
    document.getElementById('globalUploadForm').onsubmit = function (e) {
        e.preventDefault();
        const formData = new FormData(this);
        const statusDiv = document.getElementById('uploadStatus');
        statusDiv.style.display = 'block';
        statusDiv.className = 'message';
        statusDiv.style.background = '#444';
        statusDiv.innerHTML = '<i class="bi bi-hourglass-split"></i> Uploading...';

        fetch('api_upload.php', {
            method: 'POST',
            body: formData
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    statusDiv.className = 'message success';
                    statusDiv.style.background = 'rgba(3, 218, 198, 0.2)';
                    statusDiv.style.color = 'var(--secondary-color)';
                    statusDiv.style.border = '1px solid var(--secondary-color)';
                    statusDiv.innerHTML = '<i class="bi bi-check-circle"></i> ' + data.message;
                    setTimeout(() => {
                        closeUploadModal();
                        statusDiv.style.display = 'none';
                        if (window.location.href.includes('manage_assets.php') || window.location.href.includes('create_event.php') || window.location.href.includes('edit_event.php')) {
                            window.location.reload();
                        }
                    }, 1500);
                } else {
                    statusDiv.className = 'message error';
                    statusDiv.style.background = 'rgba(207, 102, 121, 0.2)';
                    statusDiv.style.color = 'var(--error-color)';
                    statusDiv.style.border = '1px solid var(--error-color)';
                    statusDiv.innerHTML = '<i class="bi bi-exclamation-triangle"></i> ' + data.error;
                }
            })
            .catch(error => {
                statusDiv.className = 'message error';
                statusDiv.innerHTML = 'An error occurred.';
                console.error(error);
            });
    };
</script>