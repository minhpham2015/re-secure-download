/**
 * R2 Secure Download JavaScript
 * Handles download progress, chunked downloads, pause/resume functionality
 */

document.addEventListener('DOMContentLoaded', function() {
    const btn = document.getElementById('r2-download-btn');
    const progressFill = document.getElementById('r2-progress-fill');
    const status = document.getElementById('r2-status');
    const sizeText = document.getElementById('r2-size');
    const deleteBtn = document.getElementById('r2-delete-btn');
    const nonce = r2_download_vars.nonce;
    const ajaxurl = r2_download_vars.ajaxurl;

    let isDownloading = false;
    let downloadStartTime = null;
    let isPaused = false;

    let chunkCount = 0;
    const MAX_CHUNKS = 1000; // Safety limit

    function downloadChunk() {
        if (!isDownloading || isPaused) {
            console.log('Download stopped or paused, not continuing');
            return;
        }

        chunkCount++;
        console.log(`Starting chunk ${chunkCount}`);

        if (chunkCount > MAX_CHUNKS) {
            if (status) status.innerHTML = '❌ Error: Download exceeded maximum chunks limit';
            isDownloading = false;
            isPaused = false;
            if (btn) btn.textContent = '🔄 Start / Resume Download';
            return;
        }

        fetch(ajaxurl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'r2_trigger_download',
                nonce: nonce
            })
        })
        .then(r => {
            if (!r.ok) {
                throw new Error(`HTTP ${r.status}: ${r.statusText}`);
            }
            return r.json();
        })
        .then(data => {
            if (!isDownloading) return; // Download might have been stopped

            console.log('Chunk response:', data); // Debug logging

            if (data.success) {
                if (data.data.complete) {
                    // Download finished
                    console.log('Download complete!');
                    chunkCount = 0; // Reset counter
                    updateProgress();
                } else {
                    // Continue downloading next chunk
                    console.log('Continuing to next chunk...');
                    setTimeout(() => {
                        console.log('Calling downloadChunk again...');
                        downloadChunk();
                    }, 300); // Reduced delay for better responsiveness

                    // Update progress after a short delay to allow the next chunk to start
                    setTimeout(updateProgress, 100);
                }
            } else {
                // Error occurred
                if (status) status.innerHTML = '❌ Error: ' + (data.data.message || 'Download failed');
                isDownloading = false;
                isPaused = false;
                chunkCount = 0;
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = '🔄 Start / Resume Download';
                }
            }
        })
        .catch(error => {
            if (isDownloading) { // Only show error if download is still active
                if (status) status.innerHTML = '❌ Network error: ' + error.message;
                isDownloading = false;
                isPaused = false;
                chunkCount = 0;
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = '🔄 Start / Resume Download';
                }
            }
        });
    }

    function updateProgress() {
        fetch(ajaxurl + '?action=r2_download_progress&nonce=' + nonce)
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    const d = data.data;
                    const mbDownloaded = (d.downloaded / 1048576).toFixed(1);
                    const mbTotal = (d.total / 1048576).toFixed(1);

                    if (progressFill) progressFill.style.width = d.percentage + '%';
                    if (sizeText) sizeText.innerHTML = `${mbDownloaded} MB / ${mbTotal} MB (${d.percentage}%)`;

                    if (d.complete) {
                        const downloadTime = downloadStartTime ? Math.round((Date.now() - downloadStartTime) / 1000) : 0;
                        const timeFormatted = downloadTime > 60 ?
                            `${Math.floor(downloadTime / 60)}m ${downloadTime % 60}s` :
                            `${downloadTime}s`;
                        if (status) status.innerHTML = `✅ Download completed successfully in ${timeFormatted}!`;
                        if (btn) {
                            btn.style.display = 'none';
                            btn.disabled = false;
                            btn.textContent = '🔄 Start / Resume Download';
                        }
                        isDownloading = false;
                        isPaused = false;
                    } else if (isDownloading && !isPaused) {
                        if (status) status.innerHTML = '⬇️ Downloading...';
                        setTimeout(updateProgress, 1500);
                    } else if (isPaused) {
                        if (status) status.innerHTML = '⏸️ Download paused';
                        if (btn) {
                            btn.disabled = false;
                            btn.textContent = '▶️ Resume Download';
                        }
                    }
                } else {
                    // Handle error messages
                    if (status) status.innerHTML = '❌ Error: ' + (data.data.message || 'Unknown error occurred');
                    isDownloading = false;
                    isPaused = false;
                    if (btn) {
                        btn.disabled = false;
                        btn.textContent = '🔄 Start / Resume Download';
                    }
                }
            })
            .catch(error => {
                if (status) status.innerHTML = '❌ Network error: ' + error.message;
                isDownloading = false;
                isPaused = false;
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = '🔄 Start / Resume Download';
                }
            });
    }

    if (btn) {
        btn.addEventListener('click', function() {
            if (isDownloading && !isPaused) {
                // Pause download
                isPaused = true;
                if (status) status.innerHTML = '⏸️ Download paused';
                btn.textContent = '▶️ Resume Download';
                return;
            }

            if (isPaused) {
                // Resume download
                isPaused = false;
                isDownloading = true;
                if (status) status.innerHTML = '🔄 Resuming download...';
                btn.textContent = '⏸️ Pause Download';
                // Continue chunked download process
                downloadChunk();
            } else {
                // Start new download
                isDownloading = true;
                isPaused = false;
                downloadStartTime = Date.now();
                if (status) status.innerHTML = '🔄 Starting download...';
                btn.textContent = '⏸️ Pause Download';
                // Start chunked download process
                downloadChunk();
            }

            updateProgress();
        });
    }

    // Handle delete button
    if (deleteBtn) {
        deleteBtn.addEventListener('click', function() {
            if (confirm('Are you sure you want to delete the downloaded file and start over?')) {
                deleteBtn.disabled = true;
                deleteBtn.textContent = '🗑️ Deleting...';

                fetch(ajaxurl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'r2_delete_file',
                        nonce: nonce
                    })
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        // Reload the page to show download interface
                        location.reload();
                    } else {
                        alert('Error: ' + (data.data.message || 'Failed to delete file'));
                        deleteBtn.disabled = false;
                        deleteBtn.textContent = '🗑️ Delete & Download Again';
                    }
                })
                .catch(error => {
                    alert('Network error: ' + error.message);
                    deleteBtn.disabled = false;
                    deleteBtn.textContent = '🗑️ Delete & Download Again';
                });
            }
        });
    }

    // Auto-check on page load
    updateProgress();

    // Initialize button state
    if (btn) btn.disabled = false;
});
