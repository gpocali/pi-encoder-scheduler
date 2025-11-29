<?php
require_once 'auth.php';
require_once '../db_connect.php';

require_role('admin');

$errors = [];
$success_message = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['defaults']) && is_array($_POST['defaults'])) {
        $defaults_to_set = $_POST['defaults'];
        $pdo->beginTransaction();
        try {
            $sql = "INSERT INTO default_assets (`tag_id`, `asset_id`) VALUES (:tag_id, :asset_id_ins) ON DUPLICATE KEY UPDATE `asset_id` = :asset_id_upd";
            $stmt_insert_update = $pdo->prepare($sql);
            $sql_delete = "DELETE FROM default_assets WHERE `tag_id` = :tag_id_del";
            $stmt_delete = $pdo->prepare($sql_delete);

            foreach ($defaults_to_set as $tag_id => $asset_id) {
                $tag_id_int = (int) $tag_id;
                $asset_id_int = (int) $asset_id;

                if ($asset_id_int > 0) {
                    $stmt_insert_update->bindValue(':tag_id', $tag_id_int, PDO::PARAM_INT);
                    $stmt_insert_update->bindValue(':asset_id_ins', $asset_id_int, PDO::PARAM_INT);
                    $stmt_insert_update->bindValue(':asset_id_upd', $asset_id_int, PDO::PARAM_INT);
                    $stmt_insert_update->execute();
                } else {
                    $stmt_delete->execute([':tag_id_del' => $tag_id_int]);
                }
            }
            $pdo->commit();
            $success_message = "Default assets updated successfully!";
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = "Database error: " . $e->getMessage();
        }
    }
}

try {
    $tags = $pdo->query("SELECT `id`, `tag_name` FROM tags ORDER BY `tag_name`")->fetchAll(PDO::FETCH_ASSOC);
    $assets = $pdo->query("SELECT `id`, `filename_original`, `display_name`, `mime_type` FROM assets ORDER BY `created_at` DESC")->fetchAll(PDO::FETCH_ASSOC);
    $current_defaults_raw = $pdo->query("SELECT `tag_id`, `asset_id` FROM default_assets")->fetchAll(PDO::FETCH_KEY_PAIR);

    $current_defaults = [];
    foreach ($tags as $tag) {
        $current_defaults[$tag['id']] = $current_defaults_raw[$tag['id']] ?? 0;
    }
} catch (Exception $e) {
    $errors[] = "Could not fetch data: " . $e->getMessage();
    $tags = [];
    $assets = [];
    $current_defaults = [];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Manage Default Assets - WRHU Encoder Scheduler</title>
    <link rel="stylesheet" href="style.css">
    <script>
        let assets = <?php echo json_encode($assets); ?>;
        let currentTagId = null;

        function openAssetModal(tagId) {
            currentTagId = tagId;
            document.getElementById('assetSelectionModal').style.display = 'block';
            filterAssetModal();
        }

        function closeAssetModal() {
            document.getElementById('assetSelectionModal').style.display = 'none';
            currentTagId = null;
        }

        function filterAssetModal() {
            const search = document.getElementById('assetModalSearch').value.toLowerCase();
            const list = document.getElementById('assetModalList');
            list.innerHTML = '';

            // Add "None" option
            const noneDiv = document.createElement('div');
            noneDiv.className = 'asset-item';
            noneDiv.style.border = '1px solid #444';
            noneDiv.style.padding = '10px';
            noneDiv.style.cursor = 'pointer';
            noneDiv.style.borderRadius = '4px';
            noneDiv.style.background = '#2a2a2a';
            noneDiv.innerHTML = '<div style="font-weight:bold; text-align:center;">-- NONE --</div>';
            noneDiv.onclick = function () { selectAsset(0, 'None', ''); };
            list.appendChild(noneDiv);

            assets.forEach(asset => {
                const name = (asset.display_name || asset.filename_original).toLowerCase();
                if (name.includes(search)) {
                    const div = document.createElement('div');
                    div.className = 'asset-item';
                    div.style.border = '1px solid #444';
                    div.style.padding = '10px';
                    div.style.cursor = 'pointer';
                    div.style.borderRadius = '4px';
                    div.style.background = '#2a2a2a';

                    // Thumbnail
                    let thumb = '';
                    if (asset.mime_type.includes('image')) {
                        thumb = `<img src="serve_asset.php?id=${asset.id}" style="width:100%; aspect-ratio:16/9; object-fit:cover; margin-bottom:5px;">`;
                    } else if (asset.mime_type.includes('video')) {
                        thumb = `<div style="width:100%; aspect-ratio:16/9; background:#000; display:flex; align-items:center; justify-content:center; margin-bottom:5px;"><span style="font-size:20px;">▶</span></div>`;
                    }

                    div.innerHTML = `
                        ${thumb}
                        <div style="font-weight:bold; font-size:0.9em; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">${asset.display_name || asset.filename_original}</div>
                    `;

                    div.onclick = function () {
                        selectAsset(asset.id, asset.display_name || asset.filename_original, thumb);
                    };

                    list.appendChild(div);
                }
            });
        }

        function selectAsset(id, name, thumbHtml) {
            if (currentTagId !== null) {
                document.getElementById('input_' + currentTagId).value = id;
                document.getElementById('name_' + currentTagId).innerText = name;
                document.getElementById('preview_' + currentTagId).innerHTML = thumbHtml || '<div style="width:100px; height:56px; background:#333; display:flex; align-items:center; justify-content:center; color:#777;">None</div>';
            }
            closeAssetModal();
        }
    </script>
</head>

<body>

    <?php include 'navbar.php'; ?>

    <div class="container">
        <h1>Manage Default Assets</h1>
        <p style="color:#aaa;">Set the default graphic that will be shown for each tag when no event is scheduled.</p>

        <?php if (!empty($errors)): ?>
            <div class="message error">
                <ul><?php foreach ($errors as $e)
                    echo "<li>$e</li>"; ?></ul>
            </div>
        <?php endif; ?>
        <?php if ($success_message): ?>
            <div class="message success"><?php echo $success_message; ?></div>
        <?php endif; ?>

        <div class="card">
            <form action="default_assets.php" method="POST">
                <div style="display:grid; gap:15px;">
                    <?php foreach ($tags as $tag):
                        $current_asset_id = $current_defaults[$tag['id']];
                        $current_asset = null;
                        foreach ($assets as $a) {
                            if ($a['id'] == $current_asset_id) {
                                $current_asset = $a;
                                break;
                            }
                        }
                        ?>
                        <div
                            style="display:flex; align-items:center; gap:15px; background:#2a2a2a; padding:10px; border-radius:4px; border:1px solid #444;">
                            <div style="width:150px; font-weight:bold; font-size:1.1em;">
                                <?php echo htmlspecialchars($tag['tag_name']); ?></div>

                            <div id="preview_<?php echo $tag['id']; ?>" style="width:120px;">
                                <?php if ($current_asset): ?>
                                    <?php if (strpos($current_asset['mime_type'], 'image') !== false): ?>
                                        <img src="serve_asset.php?id=<?php echo $current_asset['id']; ?>"
                                            style="width:100%; aspect-ratio:16/9; object-fit:cover;">
                                    <?php else: ?>
                                        <div
                                            style="width:100%; aspect-ratio:16/9; background:#000; display:flex; align-items:center; justify-content:center;">
                                            ▶</div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <div
                                        style="width:100%; aspect-ratio:16/9; background:#333; display:flex; align-items:center; justify-content:center; color:#777;">
                                        None</div>
                                <?php endif; ?>
                            </div>

                            <div style="flex:1;">
                                <div id="name_<?php echo $tag['id']; ?>" style="font-weight:bold; margin-bottom:5px;">
                                    <?php echo $current_asset ? htmlspecialchars($current_asset['display_name'] ?: $current_asset['filename_original']) : 'None'; ?>
                                </div>
                                <input type="hidden" name="defaults[<?php echo $tag['id']; ?>]"
                                    id="input_<?php echo $tag['id']; ?>" value="<?php echo $current_asset_id; ?>">
                                <button type="button" class="btn btn-sm btn-secondary"
                                    onclick="openAssetModal(<?php echo $tag['id']; ?>)">Change Asset</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div style="margin-top:20px;">
                    <button type="submit" class="btn">Save All Defaults</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Asset Selection Modal -->
    <div id="assetSelectionModal" class="modal">
        <div class="modal-content" style="width: 80%; max-width: 800px;">
            <span class="close" onclick="closeAssetModal()">&times;</span>
            <h2>Select Default Asset</h2>
            <div style="margin-bottom: 15px;">
                <input type="text" id="assetModalSearch" placeholder="Search assets..." onkeyup="filterAssetModal()"
                    style="width:100%; padding:8px; box-sizing:border-box;">
            </div>
            <div id="assetModalList"
                style="display:grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap:10px; max-height:400px; overflow-y:auto;">
                <!-- JS populates this -->
            </div>
        </div>
    </div>

    <footer>
        &copy;<?php echo date("Y") > 2025 ? "2025-" . date("Y") : "2025"; ?> WRHU Radio Hofstra University. Written by
        Gregory Pocali for WRHU with assistance from Google Gemini 3.
    </footer>

</body>

</html>