<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$current_page = basename($_SERVER['PHP_SELF']);
$user_role = $_SESSION['role'] ?? 'guest';
$user_id_nav = $_SESSION['user_id'] ?? 0;
?>
<nav class="navbar">
    <div class="container navbar-content" style="display:flex; justify-content:space-between; align-items:center;">
        <div style="display:flex; align-items:center; gap:20px;">
            <a href="index.php" class="navbar-brand"
                style="font-size:1.5em; font-weight:bold; color:var(--primary-color); text-decoration:none;">WRHU
                Scheduler</a>
            <ul class="navbar-nav" style="display:flex; gap:15px; list-style:none; margin:0; padding:0;">
                <li><a href="index.php" class="<?php echo $current_page == 'index.php' ? 'active' : ''; ?>"
                        style="color:#fff; text-decoration:none;">Dashboard</a></li>
                <li><a href="manage_assets.php"
                        class="<?php echo $current_page == 'manage_assets.php' ? 'active' : ''; ?>"
                        style="color:#fff; text-decoration:none;">Assets</a></li>
                <li><a href="create_event.php"
                        class="<?php echo $current_page == 'create_event.php' ? 'active' : ''; ?>"
                        style="color:#fff; text-decoration:none;">Create Event</a></li>
                <?php if ($user_role === 'admin'): ?>
                    <li><a href="users.php" class="<?php echo $current_page == 'users.php' ? 'active' : ''; ?>"
                            style="color:#fff; text-decoration:none;">Users</a></li>
                <?php endif; ?>
            </ul>
        </div>
        <div class="navbar-user" style="display:flex; align-items:center; gap:10px;">
            <span>Hello, <?php echo htmlspecialchars($_SESSION['username'] ?? 'User'); ?></span>
            <button id="global-upload-btn" class="btn btn-sm"
                style="background:var(--accent-color); border:none; color:#fff; cursor:pointer; padding:5px 10px; border-radius:4px;">Upload
                Asset</button>
            <a href="logout.php" class="btn btn-sm"
                style="background:#d9534f; border:none; color:#fff; text-decoration:none; padding:5px 10px; border-radius:4px;">Logout</a>
        </div>
    </div>
</nav>

<!-- Global Upload Modal -->
<div id="globalUploadModal" class="modal"
    style="display:none; position:fixed; z-index:1000; left:0; top:0; width:100%; height:100%; overflow:auto; background-color:rgba(0,0,0,0.8);">
    <div class="modal-content"
        style="background-color:#222; margin:10% auto; padding:20px; border:1px solid #444; width:80%; max-width:600px; color:#fff; border-radius:8px;">
        <span class="close" onclick="closeUploadModal()"
            style="color:#aaa; float:right; font-size:28px; font-weight:bold; cursor:pointer;">&times;</span>
        <h2>Upload Asset</h2>
        <form id="globalUploadForm" enctype="multipart/form-data">
            <div class="form-group" style="margin-bottom:15px;">
                <label style="display:block; margin-bottom:5px;">Select Tags (Hold Ctrl/Cmd to select multiple)</label>
                <div class="tag-toggle-group" id="globalTagGroup"
                    style="display:flex; flex-wrap:wrap; gap:5px; max-height:150px; overflow-y:auto; padding:5px; border:1px solid #444; border-radius:4px;">
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
                        <label class="tag-toggle" onclick="this.classList.toggle('active');"
                            style="padding:5px 10px; background:#333; border-radius:15px; cursor:pointer; user-select:none; border:1px solid #444;">
                            <?php echo htmlspecialchars($tag['tag_name']); ?>
                            <input type="checkbox" name="tag_ids[]" value="<?php echo $tag['id']; ?>" style="display:none;">
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="form-group" style="margin-bottom:15px;">
                <label style="display:block; margin-bottom:5px;">File</label>
                <input type="file" name="asset" required
                    style="width:100%; padding:8px; background:#333; border:1px solid #444; color:#fff; border-radius:4px;">
            </div>
            <button type="submit" class="btn"
                style="background:var(--accent-color); color:#fff; border:none; padding:10px 20px; border-radius:4px; cursor:pointer;">Upload</button>
            <div id="uploadStatus" class="message"
                style="display:none; margin-top:10px; padding:10px; border-radius:4px;"></div>
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

    function loadLastTags() {
        const lastTags = JSON.parse(localStorage.getItem('lastUploadTags') || '[]');
        const checkboxes = document.querySelectorAll('#globalTagGroup input[type="checkbox"]');
        checkboxes.forEach(cb => {
            if (lastTags.includes(cb.value)) {
                cb.checked = true;
                cb.parentElement.classList.add('active');
                cb.parentElement.style.background = 'var(--accent-color)'; // Visual feedback
            } else {
                cb.checked = false;
                cb.parentElement.classList.remove('active');
                cb.parentElement.style.background = '#333';
            }
        });
    }

    // Add click listener for tag toggles to update style immediately
    document.querySelectorAll('#globalTagGroup .tag-toggle').forEach(label => {
        label.addEventListener('click', function () {
            const cb = this.querySelector('input');
            // Toggle is handled by inline onclick, but we need to update style
            // Wait for inline to fire? No, inline fires first.
            // Actually, inline onclick toggles 'active' class.
            // We can use CSS for .active, but here we used inline styles.
            if (this.classList.contains('active')) {
                this.style.background = 'var(--accent-color)';
            } else {
                this.style.background = '#333';
            }
        });
    });

    // AJAX Upload
    document.getElementById('globalUploadForm').onsubmit = function (e) {
        e.preventDefault();
        const formData = new FormData(this);
        const statusDiv = document.getElementById('uploadStatus');
        statusDiv.style.display = 'block';
        statusDiv.className = 'message';
        statusDiv.style.background = '#444';
        statusDiv.innerHTML = 'Uploading...';

        // Save tags
        const selectedTags = [];
        document.querySelectorAll('#globalTagGroup input[type="checkbox"]:checked').forEach(cb => {
            selectedTags.push(cb.value);
        });
        localStorage.setItem('lastUploadTags', JSON.stringify(selectedTags));

        fetch('api_upload.php', {
            method: 'POST',
            body: formData
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    statusDiv.className = 'message success';
                    statusDiv.style.background = 'green';
                    statusDiv.innerHTML = data.message;
                    setTimeout(() => {
                        closeUploadModal();
                        statusDiv.style.display = 'none';
                        if (window.location.href.includes('manage_assets.php') || window.location.href.includes('create_event.php') || window.location.href.includes('edit_event.php')) {
                            window.location.reload();
                        }
                    }, 1500);
                } else {
                    statusDiv.className = 'message error';
                    statusDiv.style.background = 'red';
                    statusDiv.innerHTML = data.error;
                }
            })
            .catch(error => {
                statusDiv.className = 'message error';
                statusDiv.style.background = 'red';
                statusDiv.innerHTML = 'An error occurred.';
                console.error(error);
            });
    };
</script>