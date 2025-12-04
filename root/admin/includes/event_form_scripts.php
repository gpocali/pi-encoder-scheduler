<script>
    function toggleRecurrence() {
        const val = document.getElementById('recurrence').value;
        document.getElementById('recur-options').style.display = val === 'none' ? 'none' : 'block';
        document.getElementById('recur-days-group').style.display = val === 'weekly' ? 'block' : 'none';
    }

    function toggleRecurForever() {
        const forever = document.getElementById('recur_forever').checked;
        document.getElementById('recur_until').disabled = forever;
        if (forever) document.getElementById('recur_until').value = '';
    }

    // Asset Modal Logic
    let assets = <?php echo json_encode($all_assets); ?>;

    function openAssetModal() {
        document.getElementById('assetSelectionModal').style.display = 'block';
        filterAssetModal();
    }

    function closeAssetModal() {
        document.getElementById('assetSelectionModal').style.display = 'none';
    }

    function filterAssetModal() {
        const search = document.getElementById('assetModalSearch').value.toLowerCase();
        const tagFilter = document.getElementById('assetModalTagFilter').value;
        const list = document.getElementById('assetModalList');
        list.innerHTML = '';

        // Get selected tags from main form
        const selectedTags = Array.from(document.querySelectorAll('input[name="tag_ids[]"]:checked')).map(cb => cb.value);

        assets.forEach(asset => {
            const name = (asset.display_name || asset.filename_original).toLowerCase();
            const assetTags = asset.tag_ids ? asset.tag_ids.split(',') : [];

            let matchesSearch = name.includes(search);
            let matchesTag = true;
            if (tagFilter && !assetTags.includes(tagFilter)) {
                matchesTag = false;
            }

            if (matchesSearch && matchesTag) {
                const div = document.createElement('div');
                div.className = 'asset-item';
                div.style.border = '1px solid #444';
                div.style.padding = '10px';
                div.style.cursor = 'pointer';
                div.style.borderRadius = '4px';
                div.style.background = '#2a2a2a';

                // Check tag match
                let tagMatch = false;
                if (selectedTags.length === 0) tagMatch = true;
                else {
                    // If asset has ANY of the selected tags
                    selectedTags.forEach(t => {
                        if (assetTags.includes(t)) tagMatch = true;
                    });
                }

                if (!tagMatch && selectedTags.length > 0) {
                    div.style.opacity = '0.5';
                    div.title = "Tag Mismatch";
                }

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
                    selectAsset(asset.id, asset.display_name || asset.filename_original);
                };

                list.appendChild(div);
            }
        });
    }

    function selectAsset(id, name) {
        document.getElementById('selected_asset_id').value = id;
        document.getElementById('selected_asset_name').value = name;
        closeAssetModal();

        // Check tags warning
        const selectedTags = Array.from(document.querySelectorAll('input[name="tag_ids[]"]:checked')).map(cb => cb.value);
        const asset = assets.find(a => a.id == id);
        const assetTags = asset.tag_ids ? asset.tag_ids.split(',') : [];

        let match = false;
        if (selectedTags.length === 0) match = true;
        selectedTags.forEach(t => { if (assetTags.includes(t)) match = true; });

        const warning = document.getElementById('asset-warning');
        if (!match && selectedTags.length > 0) {
            warning.style.display = 'block';
            warning.innerText = "Warning: Selected asset does not share any tags with this event.";
        } else {
            warning.style.display = 'none';
        }
    }

    function toggleAssetModalUpload() {
        const div = document.getElementById('assetModalUpload');
        div.style.display = div.style.display === 'none' ? 'block' : 'none';
    }

    function uploadAssetInModal() {
        const fileInput = document.getElementById('modalAssetFile');
        const file = fileInput.files[0];
        if (!file) return;

        const formData = new FormData();
        formData.append('action', 'upload_asset');
        formData.append('asset', file);

        // Add selected tags to upload
        const selectedTags = Array.from(document.querySelectorAll('input[name="tag_ids[]"]:checked')).map(cb => cb.value);
        selectedTags.forEach(t => formData.append('tag_ids[]', t));

        const btn = event.target;
        btn.disabled = true;
        btn.innerText = 'Uploading...';

        fetch('api_upload.php', {
            method: 'POST',
            body: formData
        })
            .then(response => response.json())
            .then(data => {
                btn.disabled = false;
                btn.innerText = 'Upload';
                if (data.success) {
                    // Add to 'assets' array manually.
                    assets.unshift({
                        id: data.asset.id,
                        filename_original: data.asset.filename_original,
                        display_name: data.asset.display_name,
                        mime_type: data.asset.mime_type,
                        tag_ids: selectedTags.join(',')
                    });
                    filterAssetModal();
                    toggleAssetModalUpload();
                    selectAsset(data.asset.id, data.asset.display_name || data.asset.filename_original);
                } else {
                    alert('Upload failed: ' + (data.errors ? data.errors.join(', ') : 'Unknown error'));
                }
            })
            .catch(err => {
                btn.disabled = false;
                btn.innerText = 'Upload';
                alert('Upload error.');
                console.error(err);
            });
    }

    function toggleEventTag(btn) {
        btn.classList.toggle('active');
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
        updateEventHiddenInputs();
    }

    function updateEventHiddenInputs() {
        const container = document.getElementById('eventTagInputs');
        container.innerHTML = '';
        const activeBtns = document.querySelectorAll('#eventTagButtons .tag-btn.active');
        activeBtns.forEach(btn => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'tag_ids[]';
            input.value = btn.dataset.id;
            container.appendChild(input);
        });
    }
</script>