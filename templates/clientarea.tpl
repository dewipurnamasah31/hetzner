<div class="hetznercloud-tabs">
    <!-- Modal for confirmations and alerts -->
    <div id="actionModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center;">
        <div style="background: white; padding: 20px; border-radius: 5px; max-width: 500px; width: 90%; box-shadow: 0 2px 10px rgba(0,0,0,0.3);">
            <h4 id="modalTitle" style="margin-top: 0;">Confirmation</h4>
            <p id="modalMessage"></p>
            <div id="modalButtons" style="text-align: right; margin-top: 20px;">
                <button id="modalCancel" style="padding: 8px 16px; margin-right: 10px; background: #ccc; border: none; border-radius: 3px; cursor: pointer;">Cancel</button>
                <button id="modalConfirm" style="padding: 8px 16px; background: #007bff; color: white; border: none; border-radius: 3px; cursor: pointer;">Confirm</button>
                <button id="modalOk" style="display: none; padding: 8px 16px; background: #007bff; color: white; border: none; border-radius: 3px; cursor: pointer;">OK</button>
            </div>
        </div>
    </div>
    <ul class="nav nav-tabs" role="tablist">
        {foreach from=$tabs item=tab}
        <li role="presentation">
            <a class="tab-link" href="#" data-tab="{$tab.key}">{$tab.display}</a>
        </li>
        {/foreach}
    </ul>
    
    <div class="tab-content" id="hetznercloudContent">
        <!-- AJAX content will load here -->
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const contentDiv = document.getElementById('hetznercloudContent');
    let lastClickedButton = null;
    let currentTab = null;
    let refreshInterval = null;

    // Modal elements
    const actionModal = document.getElementById('actionModal');
    const modalTitle = document.getElementById('modalTitle');
    const modalMessage = document.getElementById('modalMessage');
    const modalCancel = document.getElementById('modalCancel');
    const modalConfirm = document.getElementById('modalConfirm');
    const modalOk = document.getElementById('modalOk');

    // Function to show a confirmation modal and return a Promise
    function showConfirmModal(message) {
        return new Promise((resolve) => {
            modalTitle.textContent = 'Confirmation';
            modalMessage.textContent = message;
            modalCancel.style.display = 'inline-block';
            modalConfirm.style.display = 'inline-block';
            modalOk.style.display = 'none';
            actionModal.style.display = 'flex';

            modalConfirm.onclick = () => {
                actionModal.style.display = 'none';
                resolve(true);
            };
            modalCancel.onclick = () => {
                actionModal.style.display = 'none';
                resolve(false);
            };
        });
    }

    // Function to show an alert modal
    function showAlertModal(message, isError = false) {
        modalTitle.textContent = isError ? 'Error' : 'Success';
        modalMessage.textContent = message;
        modalCancel.style.display = 'none';
        modalConfirm.style.display = 'none';
        modalOk.style.display = 'inline-block';
        actionModal.style.display = 'flex';

        modalOk.onclick = () => {
            actionModal.style.display = 'none';
        };
    }

    // Track the clicked submit button
    contentDiv.addEventListener('click', function(e) {
        if (e.target.type === 'submit') {
            lastClickedButton = e.target;
        }
    });

    // Handle form submissions
    contentDiv.addEventListener('submit', async function(e) {
        if (e.target.tagName === 'FORM') {
            e.preventDefault();
            let confirmMessage = 'Are you sure you want to perform this action?';
            // Customize confirmation message based on action
            if (lastClickedButton) {
                if (lastClickedButton.name === 'reinstall') {
                    confirmMessage = 'This will wipe your current OS. Proceed?';
                } else if (lastClickedButton.name === 'reset_password' || lastClickedButton.name === 'set_password') {
                    confirmMessage = 'Are you sure you want to reset the root password?';
                } else if (lastClickedButton.name === 'rescue_action') {
                    confirmMessage = lastClickedButton.value === 'enable' 
                        ? 'Are you sure you want to enable rescue mode?' 
                        : 'Are you sure you want to disable rescue mode?';
                } else if (lastClickedButton.name === 'create_snapshot') {
                    confirmMessage = 'Are you sure you want to create a snapshot?';
                } else if (lastClickedButton.name === 'delete_snapshot') {
                    confirmMessage = 'Are you sure you want to delete the snapshot?';
                } else if (lastClickedButton.name === 'rebuild_snapshot') {
                    confirmMessage = 'Are you sure you want to rebuild from snapshot? This will wipe your current OS.';
                } else if (lastClickedButton.name === 'delete_rule') {
                    confirmMessage = 'Are you sure you want to delete this rule?';
                } else if (lastClickedButton.name === 'power_action') {
                    if (lastClickedButton.value === 'poweron') {
                        confirmMessage = 'Are you sure you want to power on the server?';
                    } else if (lastClickedButton.value === 'shutdown') {
                        confirmMessage = 'Are you sure you want to shut down the server?';
                    } else if (lastClickedButton.value === 'reboot') {
                        confirmMessage = 'Are you sure you want to reboot the server?';
                    }
                } else if (lastClickedButton.name === 'add_rule') {
                    confirmMessage = 'Are you sure you want to add this firewall rule?';
                }
            }

            // Show the confirmation modal and wait for user response
            const confirmed = await showConfirmModal(confirmMessage);
            if (confirmed) {
                const form = e.target;
                const formData = new FormData(form);
                if (lastClickedButton) {
                    formData.append(lastClickedButton.name, lastClickedButton.value);
                }
                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    contentDiv.innerHTML = data.content;
                    if (data.status === 'success' && data.message) {
                        showAlertModal(data.message, false);
                    } else if (data.status === 'error') {
                        showAlertModal('Error: ' + data.message, true);
                    }
                    // Execute any scripts in the loaded content
                    const scripts = contentDiv.querySelectorAll('script');
                    scripts.forEach(script => {
                        const newScript = document.createElement('script');
                        newScript.textContent = script.textContent;
                        document.body.appendChild(newScript).parentNode.removeChild(newScript);
                    });
                    // Dispatch custom event to rebind listeners
                    contentDiv.dispatchEvent(new CustomEvent('ajaxContentLoaded'));
                })
                .catch(error => {
                    console.error('Error:', error);
                    showAlertModal('An error occurred: ' + error.message, true);
                });
            }
        }
    });

    // Handle tab clicks
    document.querySelectorAll('.tab-link').forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            const tab = this.dataset.tab;
            currentTab = tab; // Update the current tab
            loadTabContent(tab);
            startAutoRefresh(tab); // Start auto-refresh for the new tab
        });
    });

    // Function to load tab content
    function loadTabContent(tab) {
        fetch(window.location.href, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                ajax: true,
                tab: tab
            })
        })
        .then(response => response.json())
        .then(data => {
            contentDiv.innerHTML = data.content;
            // Execute any scripts in the loaded content
            const scripts = contentDiv.querySelectorAll('script');
            scripts.forEach(script => {
                const newScript = document.createElement('script');
                newScript.textContent = script.textContent;
                document.body.appendChild(newScript).parentNode.removeChild(newScript);
            });
            // Dispatch custom event to rebind listeners
            contentDiv.dispatchEvent(new CustomEvent('ajaxContentLoaded'));
        })
        .catch(error => {
            console.error('Error:', error);
            showAlertModal('Failed to load tab content: ' + error.message, true);
        });
    }

    // Function to start auto-refresh for the current tab
    function startAutoRefresh(tab) {
        // Clear any existing interval
        if (refreshInterval) {
            clearInterval(refreshInterval);
            refreshInterval = null;
        }

        // Skip auto-refresh for VNC and Firewall tabs
        if (tab === 'vnc' || tab === 'firewall' || tab === 'reinstall') {
                return;
            }

        // Start a new interval to refresh the current tab every 2 seconds
        refreshInterval = setInterval(() => {
            if (currentTab === tab) { // Only refresh if the tab hasn't changed
                loadTabContent(tab);
            }
        }, 2000); // 2000 ms = 2 seconds
    }

    // Load the first tab on page load
    const firstTab = document.querySelector('.tab-link');
    if (firstTab) {
        const initialTab = firstTab.dataset.tab;
        currentTab = initialTab;
        firstTab.click();
        startAutoRefresh(initialTab);
    }
});
</script>
