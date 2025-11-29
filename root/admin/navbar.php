<?php
$current_page = basename($_SERVER['PHP_SELF']);
$is_admin = is_admin();
?>
<nav>
    <h1><a href="index.php" style="color:#fff; text-decoration:none;">WRHU Encoder Scheduler</a></h1>
    <ul>
        <li><a href="index.php" class="<?php echo $current_page == 'index.php' ? 'active' : ''; ?>">Dashboard</a></li>
        <li><a href="#" id="global-upload-btn" style="color:var(--accent-color);">+ Upload Asset</a></li>
        <li><a href="create_event.php" class="<?php echo $current_page == 'create_event.php' ? 'active' : ''; ?>">Create
                Event</a></li>
        <li><a href="manage_assets.php"
                class="<?php echo $current_page == 'manage_assets.php' ? 'active' : ''; ?>">Assets</a></li>
        <?php if ($is_admin): ?>
            <li><a href="manage_users.php"
                    class="<?php echo ($current_page == 'manage_users.php' || $current_page == 'edit_user.php') ? 'active' : ''; ?>">Users</a>
            </li>
            <li><a href="manage_tags.php" class="<?php echo $current_page == 'manage_tags.php' ? 'active' : ''; ?>">Tags</a>
            </li>
            <li><a href="default_assets.php"
                    class="<?php echo $current_page == 'default_assets.php' ? 'active' : ''; ?>">Defaults</a></li>
        <?php endif; ?>
        <li><a href="profile.php" class="<?php echo $current_page == 'profile.php' ? 'active' : ''; ?>">Profile</a></li>
        <li><a href="logout.php">Logout</a></li>
    </ul>
</nav>

<!-- Global Upload Modal -->
<div id="globalUploadModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeUploadModal()">&times;</span>
        <h2>Upload Asset</h2>
        <form id="globalUploadForm" enctype="multipart/form-data">
            <div class="form-group">
                <label>Select Tags (Hold Ctrl/Cmd to select multiple)</label>
                <div class="tag-toggle-group" id="globalTagGroup">
                    <?php
                    // Ensure tags are available
                    if (!isset($tags)) {
                        if ($is_admin || has_role('user')) {
                            $stmt = $pdo->query("SELECT id, tag_name FROM tags ORDER BY tag_name");
                            $tags_nav = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        } else {
                            $stmt = $pdo->prepare("SELECT t.id, t.tag_name FROM tags t JOIN user_tags ut ON t.id = ut.tag_id WHERE ut.user_id = ? ORDER BY t.tag_name");
                            $stmt->execute([$_SESSION['user_id']]);
                            $tags_nav = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        }
                    } else {
                        $tags_nav = $tags;
                    }

                    foreach ($tags_nav as $tag): ?>
                        <label class="tag-toggle" onclick="this.classList.toggle('active');">
                            <?php echo htmlspecialchars($tag['tag_name']); ?>
                            <input type="checkbox" name="tag_ids[]" value="<?php echo $tag['id']; ?>">
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="form-group">
                <label>File</label>
                <input type="file" name="asset" required>
            </div>
            <button type="submit" class="btn">Upload</button>
            <div id="uploadStatus" class="message" style="display:none; margin-top:10px;"></div>
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
            } else {
                cb.checked = false;
                cb.parentElement.classList.remove('active');
            }
        });
    }

    // AJAX Upload
    document.getElementById('globalUploadForm').onsubmit = function (e) {
        e.preventDefault();
        const formData = new FormData(this);
        const statusDiv = document.getElementById('uploadStatus');
        statusDiv.style.display = 'block';
        statusDiv.className = 'message';
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
                    statusDiv.innerHTML = data.message;
                    setTimeout(() => {
                        closeUploadModal();
                        statusDiv.style.display = 'none';
                        if (window.location.href.includes('manage_assets.php')) {
                            window.location.reload();
                        }
                    }, 1500);
                } else {
                    statusDiv.className = 'message error';
                    statusDiv.innerHTML = data.error;
                }
            })
            .catch(error => {
                statusDiv.className = 'message error';
                statusDiv.innerHTML = 'An error occurred.';
                console.error(error);
            });
    };
</script>