<?php
if (!defined("WHMCS")) die("This file cannot be accessed directly");

// 1) Load WHMCS core (adjust path if your layout differs)
require_once '/var/www/console/init.php';

// 2) Import Capsule for database access
use WHMCS\Database\Capsule;


function hetznercloud_ChangePackage($params) {
    $apiKey = $params['serverpassword'];
    $serverID = $params['customfields']['Hetzner Server ID'];
    $newType = $params['configoption1'];

    if (empty($serverID)) {
        return "Server ID is missing.";
    }
    if (empty($newType)) {
        return "New server type is missing.";
    }

    // Power off the server
    $powerOffResult = perform_power_action('poweroff', $params);
    if ($powerOffResult['status'] !== 'success') {
        logModuleCall(
            'hetznercloud',
            'ChangePackage_poweroff',
            ['serverId' => $serverID, 'action' => 'poweroff'],
            $powerOffResult['message'],
            'Failed to power off server before upgrade',
            ['apiToken']
        );
        return "Failed to power off server: " . $powerOffResult['message'];
    } else {
        logModuleCall(
            'hetznercloud',
            'ChangePackage_poweroff',
            ['serverId' => $serverID, 'action' => 'poweroff'],
            $powerOffResult['message'],
            'Successfully powered off server before upgrade',
            ['apiToken']
        );
    }

    // Wait for 5 seconds
    sleep(10);

    // Change server type
    $payload = json_encode([
        'server_type' => $newType,
        'upgrade_disk' => true
    ]);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.hetzner.cloud/v1/servers/{$serverID}/actions/change_type");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer {$apiKey}",
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpcode === 201) {
        logModuleCall(
            'hetznercloud',
            'ChangePackage_resize',
            ['serverId' => $serverID, 'newType' => $newType],
            $response,
            'Server type changed successfully',
            ['apiToken']
        );
        return 'success';
    } else {
        $res = json_decode($response, true);
        $error = $res['error']['message'] ?? 'Unknown error';
        logModuleCall(
            'hetznercloud',
            'ChangePackage_resize',
            ['serverId' => $serverID, 'newType' => $newType],
            $response,
            'Failed to change server type: ' . $error,
            ['apiToken']
        );
        return "Failed to change server type: " . $error;
    }
}
function hetznercloud_TerminateAccount($params) {
    $apiKey = $params['serverpassword'];
    $serverID = $params['customfields']['Hetzner Server ID'];

    if (empty($serverID)) {
        return ['error' => 'Server ID is missing.'];
    }

    // Fetch and delete the associated snapshot
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.hetzner.cloud/v1/images?type=snapshot");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$apiKey}"]);
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpcode === 200) {
        $images = json_decode($response, true)['images'];
        $snapshot_id = null;
        foreach ($images as $image) {
            if ($image['description'] === "snapshot-{$serverID}") {
                $snapshot_id = $image['id'];
                break;
            }
        }

        if ($snapshot_id) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "https://api.hetzner.cloud/v1/images/{$snapshot_id}");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$apiKey}"]);
            $response = curl_exec($ch);
            $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpcode !== 204) {
                $res = json_decode($response, true);
                $error = $res['error']['message'] ?? 'Unknown error';
                return ['error' => 'Failed to delete snapshot: ' . $error];
            }
        }
    } else {
        $res = json_decode($response, true);
        $error = $res['error']['message'] ?? 'Unknown error';
        return ['error' => 'Failed to fetch snapshots: ' . $error];
    }

    // Fetch and delete the associated firewall
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.hetzner.cloud/v1/firewalls");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$apiKey}"]);
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpcode === 200) {
        $firewalls = json_decode($response, true)['firewalls'];
        $firewall_id = null;
        foreach ($firewalls as $fw) {
            foreach ($fw['applied_to'] as $applied) {
                if ($applied['server']['id'] == $serverID) {
                    $firewall_id = $fw['id'];
                    break 2;
                }
            }
        }

        if ($firewall_id) {
            // Detach firewall from the server
            $detachPayload = json_encode([
                'remove_from' => [['server' => ['id' => (int)$serverID], 'type' => 'server']]
            ]);
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "https://api.hetzner.cloud/v1/firewalls/{$firewall_id}/actions/remove_from_resources");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$apiKey}", "Content-Type: application/json"]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $detachPayload);
            curl_exec($ch);
            curl_close($ch);

            sleep(2); // Wait for detachment

            // Delete the firewall
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "https://api.hetzner.cloud/v1/firewalls/{$firewall_id}");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$apiKey}"]);
            curl_exec($ch);
            curl_close($ch);
        }
    }

    // Delete the server
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.hetzner.cloud/v1/servers/{$serverID}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$apiKey}"]);
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpcode == 200) {
        return 'success';
    } else {
        $res = json_decode($response, true);
        $error = $res['error']['message'] ?? 'Unknown error';
        return ['error' => 'Failed to terminate server: ' . $error];
    }
}
function hetznercloud_CreateAccount(array $params) {
    $apiKey = $params['serverpassword'];
    $serverType = explode('|', $params['configoption1'])[0]; // e.g., 'cx22' from 'cx22|CX22'
    $location = explode('|', $params['customfields']['Location'])[0]; // e.g., 'nbg1'
    $image = $params['customfields']['Operating System']; // e.g., 'Ubuntu-22.04'
    $hostname = "HetznerVPS-" . uniqid();

    // Validate inputs
    if (empty($serverType) || empty($location) || empty($image)) {
        return 'Missing required configuration options.';
    }

    // Prepare API payload for server creation
    $payload = json_encode([
        'name' => $hostname,
        'server_type' => $serverType,
        'location' => $location,
        'image' => $image,
        'automount' => false,
        'start_after_create' => true
    ]);

    // Create server via API
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.hetzner.cloud/v1/servers');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpcode === 201) {
        $data = json_decode($response, true);
        $server = $data['server'];
        $serverId = $server['id'];
        $ipv4 = $server['public_net']['ipv4']['ip'];

        // Create a firewall with a unique and meaningful name
        $firewallName = "HetznerVPS-FW-{$serverId}-" . uniqid();
        $firewallPayload = json_encode([
            'name' => $firewallName,
            'apply_to' => [['type' => 'server', 'server' => ['id' => (int)$serverId]]],
            'rules' => [
                [
                    'direction' => 'out',
                    'protocol' => 'tcp',
                    'port' => '25',
                    'destination_ips' => ['0.0.0.0/0'],
                    'description' => 'Block SMTP (Port 25) - Managed by us'
                ],
                [
                    'direction' => 'out',
                    'protocol' => 'tcp',
                    'port' => '465',
                    'destination_ips' => ['0.0.0.0/0'],
                    'description' => 'Block SMTPS (Port 465) - Managed by us'
                ],
                [
                    'direction' => 'in',
                    'protocol' => 'tcp',
                    'port' => '22',
                    'source_ips' => ['0.0.0.0/0'],
                    'description' => 'Allow SSH'
                ],
                [
                    'direction' => 'in',
                    'protocol' => 'tcp',
                    'port' => '1-65535',
                    'source_ips' => ['0.0.0.0/0'],
                    'description' => 'Allow All Inbound TCP Traffic'
                ],
                [
                    'direction' => 'in',
                    'protocol' => 'udp',
                    'port' => '1-65535',
                    'source_ips' => ['0.0.0.0/0'],
                    'description' => 'Allow All Inbound UDP Traffic'
                ],
                
            ]
        ]);

        // Create firewall via API
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.hetzner.cloud/v1/firewalls');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $firewallPayload);
        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpcode !== 201) {
            $res = json_decode($response, true);
            $error = $res['error']['message'] ?? 'Unknown error';
            return 'Server created, but failed to create firewall: ' . $error;
        }

        // Fetch custom field IDs from the database
        try {
            $serverIdField = Capsule::table('tblcustomfields')
                ->where('type', 'product')
                ->where('relid', $params['packageid'])
                ->where('fieldname', 'Hetzner Server ID')
                ->first();
            $ipv4Field = Capsule::table('tblcustomfields')
                ->where('type', 'product')
                ->where('relid', $params['packageid'])
                ->where('fieldname', 'Hetzner IPv4')
                ->first();

            if (!$serverIdField || !$ipv4Field) {
                return 'Custom fields not found in WHMCS configuration.';
            }

            // Update custom field values
            Capsule::table('tblcustomfieldsvalues')
                ->updateOrInsert(
                    ['fieldid' => $serverIdField->id, 'relid' => $params['serviceid']],
                    ['value' => $serverId]
                );
            Capsule::table('tblcustomfieldsvalues')
                ->updateOrInsert(
                    ['fieldid' => $ipv4Field->id, 'relid' => $params['serviceid']],
                    ['value' => $ipv4]
                );

            // Update tblhosting with Dedicated IP, Username, and clear Password
            $encryptedEmpty = encrypt('');
            Capsule::table('tblhosting')
                ->where('id', $params['serviceid'])
                ->update([
                    'dedicatedip' => $ipv4,
                    'domain' => $hostname,
                    'username' => 'root',
                    'password' => $encryptedEmpty
                ]);

            return 'success';
        } catch (Exception $e) {
            return 'Server and firewall created, but failed to update custom fields, dedicated IP, username, or clear password: ' . $e->getMessage();
        }
    } else {
        $res = json_decode($response, true);
        $error = $res['error']['message'] ?? 'Unknown error';
        return 'Failed to create server: ' . $error;
    }
}
function hetznercloud_MetaData() {
    return [
        'DisplayName' => 'Hetzner Cloud',
        'APIVersion' => '1.1',
    ];
}
function hetznercloud_ConfigOptions() {
    return [
        'Server Type' => [
            'Type' => 'dropdown',
            'Options' => 'cx22|CX22,cx32|CX32,cx42|CX42,cx52|CX52,cpx11|CPX11,cpx21|CPX21,cpx31|CPX31,cpx41|CPX41,cpx51|CPX51',
            'Description' => 'Select the server type',
        ],
    ];
}
function hetznercloud_ClientArea($params) {
    $tab_names = [
        'overview' => 'Overview',
        'reinstall' => 'Reinstall',
        'settings' => 'Settings',
        'vnc' => 'VNC',
        'rescue' => 'Rescue Mode',
        'snapshot' => 'Snapshot',
        'firewall' => 'Firewall',
    ];

    if (!empty($_REQUEST['ajax']) && !empty($_REQUEST['tab'])) {
        $tab = $_REQUEST['tab'];
        if (!isset($tab_names[$tab])) {
            header('Content-Type: application/json');
            die(json_encode(['content' => '<p class="alert alert-danger">Invalid tab requested.</p>', 'status' => 'error', 'message' => 'Invalid tab']));
        }

        $generate_function = 'generate_' . $tab . '_content';
        if (!function_exists($generate_function)) {
            header('Content-Type: application/json');
            die(json_encode(['content' => '<p class="alert alert-danger">Tab content not available.</p>', 'status' => 'error', 'message' => 'Content not available']));
        }

        // Handle actions
        $result = ['status' => 'success', 'message' => ''];
        if (isset($_REQUEST['power_action'])) {
            $result = perform_power_action($_REQUEST['power_action'], $params);
        } elseif (isset($_REQUEST['create_snapshot'])) {
            $result = perform_create_snapshot($params);
        } elseif (isset($_REQUEST['delete_snapshot']) && isset($_REQUEST['snapshot_id'])) {
            $result = perform_delete_snapshot($_REQUEST['snapshot_id'], $params);
        } elseif (isset($_REQUEST['rebuild_snapshot']) && isset($_REQUEST['snapshot_id'])) {
            $result = perform_rebuild_snapshot($_REQUEST['snapshot_id'], $params);
        } elseif (isset($_REQUEST['reset_password'])) {
            $result = perform_reset_password($params);
        } elseif (isset($_REQUEST['reinstall']) && isset($_REQUEST['selected_image'])) {
            $result = perform_reinstall($params);
        } elseif (isset($_REQUEST['rescue_action'])) {
            $result = perform_rescue_action($_REQUEST['rescue_action'], $params);
        } elseif (isset($_REQUEST['firewall_name']) || isset($_REQUEST['add_rule']) || isset($_REQUEST['delete_rule']) || isset($_REQUEST['delete_firewall'])) {
            $result = perform_firewall_action($params);
        } elseif (isset($_REQUEST['set_password'])) {
            $result = perform_reset_password($params);
        }

        // Generate content after action
        $content = call_user_func($generate_function, $params);
        header('Content-Type: application/json');
        die(json_encode([
            'content' => $content,
            'status' => $result['status'],
            'message' => $result['message']
        ]));
    }

    return [
        'templatefile' => 'templates/clientarea',
        'vars' => [
            'tabs' => array_map(function($key, $value) { return ['key' => $key, 'display' => $value]; }, array_keys($tab_names), $tab_names),
        ]
    ];
}

function generate_overview_content($params) {
    $apiKey = $params['serverpassword'];
    $serverID = $params['customfields']['Hetzner Server ID'];
    $html = '<h3>Server Details</h3>';

    // Fetch server details from Hetzner API
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.hetzner.cloud/v1/servers/{$serverID}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$apiKey}"]);
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpcode === 200) {
        $server = json_decode($response, true)['server'];
        $status = $server['status'];

        // Add blinking green circle for "running" and red circle for "off"
        $statusDisplay = htmlspecialchars($status);
        if ($status === 'running') {
            $statusDisplay .= ' <span class="status-circle status-running" style="display:inline-block; width:10px; height:10px; border-radius:50%; background-color:#28a745; margin-left:5px; animation: blink 1s infinite;"></span>';
        } elseif ($status === 'off') {
            $statusDisplay .= ' <span class="status-circle status-off" style="display:inline-block; width:10px; height:10px; border-radius:50%; background-color:#dc3545; margin-left:5px;"></span>';
        }
        $html .= '<p><strong>Status:</strong> ' . $statusDisplay . '</p>';

        // Add CSS for blinking animation
        $html .= <<<HTML
        <style>
            @keyframes blink {
                0% { opacity: 1; }
                50% { opacity: 0; }
                100% { opacity: 1; }
            }
        </style>
        HTML;

        $html .= '<p><strong>Hostname:</strong> ' . htmlspecialchars($server['name']) . '</p>';

        // IP with copy feature
        $ipAddress = htmlspecialchars($server['public_net']['ipv4']['ip']);
        $html .= '<p><strong>IP:</strong> <span id="ipAddress">' . $ipAddress . '</span>';
        $html .= '<i class="fa fa-copy" id="copyIp" style="cursor:pointer; margin-left:10px;"></i></p>';

        $html .= '<p><strong>Operating System:</strong> ' . htmlspecialchars($server['image']['description'] ?? 'Unknown') . '</p>';

        // Map location codes to full names
        $locationCode = $server['datacenter']['location']['name'];
        $locationMap = [
            'hel1' => 'Helsinki, Finland',
            'ash' => 'Ashburn, USA',
            'fsn1' => 'Falkenstein, Germany',
            'nbg1' => 'Nuremberg, Germany',
            'hil' => 'Hillsboro, USA'
        ];
        $locationName = $locationMap[$locationCode] ?? $locationCode; // Fallback to code if not mapped
        $html .= '<p><strong>Location:</strong> ' . htmlspecialchars($locationName) . '</p>';

        // Retrieve the password from the database
        $service = Capsule::table('tblhosting')
            ->where('id', $params['serviceid'])
            ->first();
        $password = decrypt($service->password);

        $html .= '<p><strong>Root Password:</strong> ';
        if (empty($password)) {
            $html .= 'Not Set Yet ';
            $html .= '<form method="post" style="display:inline; margin-left:10px;">';
            $html .= '<input type="hidden" name="ajax" value="true">';
            $html .= '<input type="hidden" name="tab" value="overview">';
            $html .= '<button type="submit" name="set_password" value="true" style="background-color:#ffcc00; color:#fff; border:none; padding:5px 10px; cursor:pointer;">Set Password</button>';
            $html .= '</form>';
        } else {
            // Display password with toggle and copy features
            $html .= '<span id="passwordDisplay">••••••••</span>';
            $html .= '<i class="fa fa-eye" id="togglePassword" style="cursor:pointer; margin-left:10px;"></i>';
            $html .= '<i class="fa fa-copy" id="copyPassword" style="cursor:pointer; margin-left:10px;"></i>';
            $html .= '<input type="hidden" id="actualPassword" value="' . htmlspecialchars($password) . '">';
        }
        $html .= '</p>';

        // JavaScript for IP and Password functionality
        $html .= <<<HTML
        <script>
        function initIpCopyFeature() {
            const copyIp = document.getElementById('copyIp');
            const ipAddress = document.getElementById('ipAddress').textContent;

            if (copyIp && ipAddress) {
                copyIp.addEventListener('click', function() {
                    navigator.clipboard.writeText(ipAddress).then(() => {
                        const parent = copyIp.parentNode;
                        const copiedText = document.createElement('span');
                        copiedText.textContent = 'Copied!';
                        copiedText.style.marginLeft = '10px';
                        copiedText.style.color = '#28a745';
                        parent.replaceChild(copiedText, copyIp);
                        setTimeout(() => {
                            const newCopyIcon = document.createElement('i');
                            newCopyIcon.className = 'fa fa-copy';
                            newCopyIcon.id = 'copyIp';
                            newCopyIcon.style.cursor = 'pointer';
                            newCopyIcon.style.marginLeft = '10px';
                            parent.replaceChild(newCopyIcon, copiedText);
                            initIpCopyFeature(); // Rebind the event listener
                        }, 2000);
                    }).catch(err => {
                        console.error('Failed to copy IP address:', err);
                    });
                });
            }
        }

        function initPasswordFeatures() {
            const toggle = document.getElementById('togglePassword');
            const copyPassword = document.getElementById('copyPassword');
            const passwordDisplay = document.getElementById('passwordDisplay');
            const actualPassword = document.getElementById('actualPassword') ? document.getElementById('actualPassword').value : null;
            let visible = false;

            if (toggle && passwordDisplay && actualPassword) {
                toggle.addEventListener('click', function() {
                    visible = !visible;
                    passwordDisplay.textContent = visible ? actualPassword : '••••••••';
                });

                if (copyPassword) {
                    copyPassword.addEventListener('click', function() {
                        navigator.clipboard.writeText(actualPassword).then(() => {
                            const parent = copyPassword.parentNode;
                            const copiedText = document.createElement('span');
                            copiedText.textContent = 'Copied!';
                            copiedText.style.marginLeft = '10px';
                            copiedText.style.color = '#28a745';
                            parent.replaceChild(copiedText, copyPassword);
                            setTimeout(() => {
                                const newCopyIcon = document.createElement('i');
                                newCopyIcon.className = 'fa fa-copy';
                                newCopyIcon.id = 'copyPassword';
                                newCopyIcon.style.cursor = 'pointer';
                                newCopyIcon.style.marginLeft = '10px';
                                parent.replaceChild(newCopyIcon, copiedText);
                                initPasswordFeatures(); // Rebind the event listener
                            }, 2000);
                        }).catch(err => {
                            console.error('Failed to copy password:', err);
                        });
                    });
                }
            }
        }

        // Initialize both features
        initIpCopyFeature();
        initPasswordFeatures();
        document.addEventListener('ajaxContentLoaded', () => {
            initIpCopyFeature();
            initPasswordFeatures();
        });
        </script>
        HTML;
    } else {
        $html .= '<p class="alert alert-danger">Failed to fetch server details.</p>';
    }

    // Power control form
    $html .= '<form method="post">';
    $html .= '<input type="hidden" name="ajax" value="true">';
    $html .= '<input type="hidden" name="tab" value="overview">';
    $html .= '<div style="display: inline-flex; flex-wrap: wrap; gap: 20px; justify-content: center;">';
    $html .= '<button type="submit" name="power_action" value="poweron" class="btn btn-success">Power On</button>';
    $html .= '<button type="submit" name="power_action" value="shutdown" class="btn btn-danger">Power Off</button>';
    $html .= '<button type="submit" name="power_action" value="reboot" class="btn btn-warning">Reboot</button>';
    $html .= '</div>';
    $html .= '</form>';

    return $html;
}

function perform_power_action($action, $params) {
    $apiKey = $params['serverpassword'];
    $serverID = $params['customfields']['Hetzner Server ID'];
    $actionUrl = "https://api.hetzner.cloud/v1/servers/{$serverID}/actions/{$action}";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $actionUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$apiKey}"]);
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpcode === 201) {
        $message = "Server action {$action} initiated.";
        return ['status' => 'success', 'message' => $message];
    } else {
        $res = json_decode($response, true);
        $error = $res['error']['message'] ?? 'Unknown error';
        return ['status' => 'error', 'message' => "Failed to perform {$action}: {$error}"];
    }
}
function generate_reinstall_content($params) {
    $apiKey = $params['serverpassword'];
    $html = '<h3>Reinstall Server</h3>';

    // Fetch available images
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.hetzner.cloud/v1/images?type=system");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$apiKey}"]);
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $html .= '<form method="post">';
    $html .= '<input type="hidden" name="ajax" value="true">';
    $html .= '<input type="hidden" name="tab" value="reinstall">';
    $html .= '<div class="form-group">';
    $html .= '<label for="osSelect">Select Operating System</label>';
    $html .= '<select class="form-control" id="osSelect" name="selected_image" required>';

    if ($httpcode === 200) {
        $images = json_decode($response, true)['images'];
        $unique_images = [];
        
        // Collect unique OS images, excluding openSUSE
        foreach ($images as $image) {
            $desc = trim($image['description']);
            // Skip if the description contains "openSUSE" (case-insensitive)
            if (stripos($desc, 'openSUSE') !== false) {
                continue;
            }
            if (!isset($unique_images[$desc])) {
                $unique_images[$desc] = $image['name'];
            }
        }

        // Add Windows Server 2022
        $windows_desc = 'Windows Server 2022';
        $unique_images[$windows_desc] = '229403573';

        // Sort the OS list alphabetically by description
        ksort($unique_images, SORT_NATURAL | SORT_FLAG_CASE);

        // Ensure Windows Server 2022 is at the top
        $sorted_images = [];
        if (isset($unique_images[$windows_desc])) {
            $sorted_images[$windows_desc] = $unique_images[$windows_desc];
            unset($unique_images[$windows_desc]);
        }
        // Add the remaining sorted OSes
        $sorted_images = array_merge($sorted_images, $unique_images);

        // Generate dropdown options
        foreach ($sorted_images as $desc => $name) {
            $html .= '<option value="' . htmlspecialchars($name) . '">' . htmlspecialchars($desc) . '</option>';
        }
    } else {
        $html .= '<option value="">No images available</option>';
    }

    $html .= '</select>';
    $html .= '</div>';
    $html .= '<button type="submit" name="reinstall" value="true" class="btn btn-primary">Reinstall</button>';
    $html .= '</form>';

    return $html;
}

function perform_reinstall($params) {
    $apiKey = $params['serverpassword'];
    $serverID = $params['customfields']['Hetzner Server ID'];
    $imageId = $_REQUEST['selected_image'];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.hetzner.cloud/v1/servers/{$serverID}/actions/rebuild");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$apiKey}", "Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['image' => $imageId]));
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpcode === 201) {
        try {
            $encryptedEmpty = encrypt('');
            Capsule::table('tblhosting')->where('id', $params['serviceid'])->update(['password' => $encryptedEmpty]);
            return ['status' => 'success', 'message' => 'Server rebuild initiated.'];
        } catch (Exception $e) {
            return ['status' => 'error', 'message' => 'Rebuild started, but failed to clear password: ' . $e->getMessage()];
        }
    } else {
        $res = json_decode($response, true);
        $error = $res['error']['message'] ?? 'Unknown error';
        return ['status' => 'error', 'message' => 'Reinstall failed: ' . $error];
    }
}
function generate_settings_content($params) {
    $html = '<h3>Reset Password</h3>';
    $html .= '<form method="post">';
    $html .= '<input type="hidden" name="ajax" value="true">';
    $html .= '<input type="hidden" name="tab" value="settings">';
    $html .= '<button type="submit" name="reset_password" value="true" class="btn btn-warning">Reset Root Password</button>';
    $html .= '</form>';
    return $html;
}

function perform_reset_password($params) {
    $apiKey = $params['serverpassword'];
    $serverID = $params['customfields']['Hetzner Server ID'];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.hetzner.cloud/v1/servers/{$serverID}/actions/reset_password");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$apiKey}"]);
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpcode === 201) {
        $data = json_decode($response, true);
        $newPassword = $data['root_password'] ?? '';
        if ($newPassword) {
            try {
                $encryptedPassword = encrypt($newPassword);
                Capsule::table('tblhosting')->where('id', $params['serviceid'])->update(['password' => $encryptedPassword]);
                return [
                    'status' => 'success',
                    'message' => 'Password reset successfully. You can view the new password by clicking "Show" next to Root Password.'
                ];
            } catch (Exception $e) {
                return ['status' => 'error', 'message' => 'Password reset, but failed to save: ' . $e->getMessage()];
            }
        } else {
            return ['status' => 'error', 'message' => 'Password reset succeeded, but no password returned.'];
        }
    } else {
        $res = json_decode($response, true);
        $error = $res['error']['message'] ?? 'Unknown error';
        return ['status' => 'error', 'message' => 'Password reset failed: ' . $error];
    }
}
function generate_vnc_content($params) {
    $html = '<h3>VNC Console</h3>';

    $apiKey = $params['serverpassword'];
    $serverID = $params['customfields']['Hetzner Server ID'];

    // Request VNC console from Hetzner API
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.hetzner.cloud/v1/servers/{$serverID}/actions/request_console");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$apiKey}", "Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, '{}');
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpcode === 201) {
        $console = json_decode($response, true);
        $wssUrl = $console['wss_url'];
        $password = urlencode($console['password']);

        // Construct the URL to the bundled noVNC client
        $novncUrl = '/modules/servers/hetznercloud/novnc/vnc.html?host=' . parse_url($wssUrl, PHP_URL_HOST) .
                    '&port=443&password=' . $password .
                    '&path=' . urlencode(parse_url($wssUrl, PHP_URL_PATH) . '?' . parse_url($wssUrl, PHP_URL_QUERY)) .
                    '&autoconnect=true';

        $html .= '<p>Access your VPS console below:</p>';
        $html .= '<iframe src="' . htmlspecialchars($novncUrl) . '" width="800" height="600" style="border:1px solid #ccc;" allowfullscreen></iframe>';
        $html .= '<p><a href="' . htmlspecialchars($novncUrl) . '" target="_blank" class="btn btn-primary">Open in New Window</a></p>';
    } else {
        $res = json_decode($response, true);
        $error = $res['error']['message'] ?? 'Unknown error';
        $html .= '<p class="alert alert-danger">Unable to generate console link. Please try again later: ' . htmlspecialchars($error) . '</p>';
    }

    return $html;
}

function perform_rescue_action($action, $params) {
    $apiKey = $params['serverpassword'];
    $serverID = $params['customfields']['Hetzner Server ID'];
    $url = "https://api.hetzner.cloud/v1/servers/{$serverID}/actions/" . ($action === 'enable' ? 'enable_rescue' : 'disable_rescue');

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$apiKey}", "Content-Type: application/json"]);
    if ($action === 'enable') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['type' => 'linux64']));
    }
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpcode === 201) {
        $message = $action === 'enable' ? 'Rescue mode enabled. Please reboot to apply.' : 'Rescue mode disabled. Please reboot to apply.';
        if ($action === 'enable') {
            $result = json_decode($response, true);
            $rootPassword = $result['root_password'] ?? 'Not available';
            //$message .= " Root password: {$rootPassword}";
            $message .= "";
            // Store the rescue password in a custom field
            try {
                $rescuePasswordField = Capsule::table('tblcustomfields')
                    ->where('type', 'product')
                    ->where('relid', $params['packageid'])
                    ->where('fieldname', 'Rescue Password')
                    ->first();

                if (!$rescuePasswordField) {
                    // Create the custom field if it doesn't exist
                    $fieldId = Capsule::table('tblcustomfields')->insertGetId([
                        'type' => 'product',
                        'relid' => $params['packageid'],
                        'fieldname' => 'Rescue Password',
                        'fieldtype' => 'text',
                        'description' => 'Stores the rescue mode password',
                        'adminonly' => 'on', // Make it admin-only if desired
                        'showorder' => '', // Don't show on order form
                        'showinvoice' => '', // Don't show on invoice
                    ]);
                } else {
                    $fieldId = $rescuePasswordField->id;
                }

                // Update the rescue password custom field
                $encryptedPassword = encrypt($rootPassword);
                Capsule::table('tblcustomfieldsvalues')
                    ->updateOrInsert(
                        ['fieldid' => $fieldId, 'relid' => $params['serviceid']],
                        ['value' => $encryptedPassword]
                    );
            } catch (Exception $e) {
                return ['status' => 'error', 'message' => 'Rescue mode enabled, but failed to save password: ' . $e->getMessage()];
            }
        } else {
            // Clear the rescue password custom field when disabling rescue mode
            try {
                $rescuePasswordField = Capsule::table('tblcustomfields')
                    ->where('type', 'product')
                    ->where('relid', $params['packageid'])
                    ->where('fieldname', 'Rescue Password')
                    ->first();

                if ($rescuePasswordField) {
                    Capsule::table('tblcustomfieldsvalues')
                        ->where('fieldid', $rescuePasswordField->id)
                        ->where('relid', $params['serviceid'])
                        ->update(['value' => '']);
                }
            } catch (Exception $e) {
                return ['status' => 'error', 'message' => 'Rescue mode disabled, but failed to clear rescue password: ' . $e->getMessage()];
            }
        }
        return ['status' => 'success', 'message' => $message];
    } else {
        $res = json_decode($response, true);
        $error = $res['error']['message'] ?? 'Unknown error';
        return ['status' => 'error', 'message' => ucfirst($action) . ' failed: ' . $error];
    }
}

function generate_rescue_content($params) {
    $apiKey = $params['serverpassword'];
    $serverID = $params['customfields']['Hetzner Server ID'];
    $html = '<h3>Rescue Mode</h3>';

    // Check rescue mode status
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.hetzner.cloud/v1/servers/{$serverID}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$apiKey}"]);
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $rescue_enabled = false;
    if ($httpcode === 200) {
        $server = json_decode($response, true)['server'];
        $rescue_enabled = $server['rescue_enabled'] ?? false;
    }

    $html .= '<p><strong>Status:</strong> ' . ($rescue_enabled ? 'Enabled' : 'Disabled') . '</p>';

    // Display rescue credentials if enabled
    if ($rescue_enabled) {
        // Fetch the rescue password from the custom field
        $rescuePassword = '';
        try {
            $rescuePasswordField = Capsule::table('tblcustomfields')
                ->where('type', 'product')
                ->where('relid', $params['packageid'])
                ->where('fieldname', 'Rescue Password')
                ->first();

            if ($rescuePasswordField) {
                $rescuePasswordValue = Capsule::table('tblcustomfieldsvalues')
                    ->where('fieldid', $rescuePasswordField->id)
                    ->where('relid', $params['serviceid'])
                    ->first();
                $rescuePassword = $rescuePasswordValue ? decrypt($rescuePasswordValue->value) : '';
            }
        } catch (Exception $e) {
            $rescuePassword = '';
        }

        $html .= '<div style="background-color: #f8f9fa; padding: 15px; border-radius: 5px; margin-bottom: 20px;">';
        $html .= '<h4>Rescue Mode Credentials</h4>';
        $html .= '<p><strong>User:</strong> root</p>';
        $html .= '<p><strong>Password:</strong> ';
        if (empty($rescuePassword)) {
            $html .= 'Not available. Please disable and re-enable rescue mode to generate a new password.';
        } else {
            $html .= '<span id="rescuePasswordDisplay">••••••••</span>';
            $html .= '<i class="fa fa-eye" id="toggleRescuePassword" style="cursor:pointer; margin-left:10px;"></i>';
            $html .= '<i class="fa fa-copy" id="copyRescuePassword" style="cursor:pointer; margin-left:10px;"></i>';
            $html .= '<input type="hidden" id="actualRescuePassword" value="' . htmlspecialchars($rescuePassword) . '">';
            $html .= '<script>
                function initRescuePasswordFeatures() {
                    const toggle = document.getElementById("toggleRescuePassword");
                    const copyPassword = document.getElementById("copyRescuePassword");
                    const passwordDisplay = document.getElementById("rescuePasswordDisplay");
                    const actualPassword = document.getElementById("actualRescuePassword").value;
                    let visible = false;

                    if (toggle && passwordDisplay && actualPassword) {
                        toggle.addEventListener("click", function() {
                            visible = !visible;
                            passwordDisplay.textContent = visible ? actualPassword : "••••••••";
                        });
                    }

                    if (copyPassword && actualPassword) {
                        copyPassword.addEventListener("click", function() {
                            navigator.clipboard.writeText(actualPassword).then(() => {
                                // Replace the copy icon with "Copied!" text
                                const parent = copyPassword.parentNode;
                                const copiedText = document.createElement("span");
                                copiedText.textContent = "Copied!";
                                copiedText.style.marginLeft = "10px";
                                copiedText.style.color = "#28a745"; // Green color for feedback
                                parent.replaceChild(copiedText, copyPassword);
                                // Revert to the copy icon after 2 seconds
                                setTimeout(() => {
                                    const newCopyIcon = document.createElement("i");
                                    newCopyIcon.className = "fa fa-copy";
                                    newCopyIcon.id = "copyRescuePassword";
                                    newCopyIcon.style.cursor = "pointer";
                                    newCopyIcon.style.marginLeft = "10px";
                                    parent.replaceChild(newCopyIcon, copiedText);
                                    // Rebind the event listener to the new copy icon
                                    initRescuePasswordFeatures();
                                }, 2000);
                            }).catch(err => {
                                console.error("Failed to copy rescue password:", err);
                            });
                        });
                    }
                }
                initRescuePasswordFeatures();
                document.addEventListener("ajaxContentLoaded", initRescuePasswordFeatures);
            </script>';
        }
        $html .= '</p>';
        $html .= '</div>';
    }

    if (!$rescue_enabled) {
        $html .= '<form method="post">';
        $html .= '<input type="hidden" name="ajax" value="true">';
        $html .= '<input type="hidden" name="tab" value="rescue">';
        $html .= '<button type="submit" name="rescue_action" value="enable" class="btn btn-primary">Enable Rescue Mode</button>';
        $html .= '</form>';
    } else {
        $html .= '<form method="post">';
        $html .= '<input type="hidden" name="ajax" value="true">';
        $html .= '<input type="hidden" name="tab" value="rescue">';
        $html .= '<button type="submit" name="rescue_action" value="disable" class="btn btn-danger">Disable Rescue Mode</button>';
        $html .= '</form>';
    }

    return $html;
}
function generate_snapshot_content($params) {
    $apiKey = $params['serverpassword'];
    $serverID = $params['customfields']['Hetzner Server ID'];
    $html = '<h3>Snapshot Management</h3>';

    // Fetch snapshots
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.hetzner.cloud/v1/images?type=snapshot");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$apiKey}"]);
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $snapshot = null;
    if ($httpcode === 200) {
        $images = json_decode($response, true)['images'];
        foreach ($images as $image) {
            if ($image['description'] === "snapshot-{$serverID}") {
                $snapshot = $image;
                break;
            }
        }
    }

    if ($snapshot) {
        $status = $snapshot['status'] ?? 'unknown';
        $name = htmlspecialchars($snapshot['description']);
        $created = htmlspecialchars($snapshot['created']);
        $size = isset($snapshot['image_size']) ? round($snapshot['image_size'], 2) . ' GB' : 'N/A';

        // Determine status color
        $statusColor = 'gray'; // Default for unknown
        if ($status === 'available') {
            $statusColor = '#28a745'; // Green
        } elseif ($status === 'creating') {
            $statusColor = '#ffcc00'; // Yellow/Orange
        }

        $html .= '<p>A snapshot exists for this server. Details are shown below:</p>';
        $html .= '<table class="table table-bordered table-striped">';
        $html .= '<thead><tr>';
        $html .= '<th>Name</th><th>Creation Date</th><th>Size</th><th>Status</th>';
        $html .= '</tr></thead>';
        $html .= '<tbody>';
        $html .= '<tr>';
        $html .= '<td>' . $name . '</td>';
        $html .= '<td>' . $created . '</td>';
        $html .= '<td>' . $size . '</td>';
        $html .= '<td><span style="color: ' . $statusColor . '; font-weight: bold;">' . htmlspecialchars(ucfirst($status)) . '</span></td>';
        $html .= '</tr>';
        $html .= '</tbody>';
        $html .= '</table>';

        // Add buttons for actions
        $html .= '<div data-snapshot-status="' . htmlspecialchars($status) . '" style="display: flex; gap: 20px; justify-content: center;">';
        $html .= '<form method="post">';
        $html .= '<input type="hidden" name="ajax" value="true">';
        $html .= '<input type="hidden" name="tab" value="snapshot">';
        $html .= '<input type="hidden" name="snapshot_id" value="' . $snapshot['id'] . '">';
        $html .= '<button type="submit" name="delete_snapshot" value="true" class="btn btn-danger">Delete Snapshot</button>';
        $html .= '</form>';
        $html .= '<form method="post">';
        $html .= '<input type="hidden" name="ajax" value="true">';
        $html .= '<input type="hidden" name="tab" value="snapshot">';
        $html .= '<input type="hidden" name="snapshot_id" value="' . $snapshot['id'] . '">';
        $html .= '<button type="submit" name="rebuild_snapshot" value="true" class="btn btn-warning">Rebuild from Snapshot</button>';
        $html .= '</form>';
        $html .= '</div>';
    } else {
        $html .= '<p>No snapshot found for this server.</p>';
        $html .= '<form method="post">';
        $html .= '<input type="hidden" name="ajax" value="true">';
        $html .= '<input type="hidden" name="tab" value="snapshot">';
        $html .= '<button type="submit" name="create_snapshot" value="true" class="btn btn-primary">Create Snapshot</button>';
        $html .= '</form>';
    }

    return $html;
}

function perform_create_snapshot($params) {
    $apiKey = $params['serverpassword'];
    $serverID = $params['customfields']['Hetzner Server ID'];
    $description = "snapshot-{$serverID}";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.hetzner.cloud/v1/servers/{$serverID}/actions/create_image");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$apiKey}", "Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['description' => $description, 'type' => 'snapshot']));
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpcode === 201) {
        return ['status' => 'success', 'message' => 'Snapshot creation initiated.'];
    } else {
        $res = json_decode($response, true);
        $error = $res['error']['message'] ?? 'Unknown error';
        return ['status' => 'error', 'message' => 'Failed to create snapshot: ' . $error];
    }
}

function perform_delete_snapshot($snapshot_id, $params) {
    $apiKey = $params['serverpassword'];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.hetzner.cloud/v1/images/{$snapshot_id}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$apiKey}"]);
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpcode === 204) {
        return ['status' => 'success', 'message' => 'Snapshot deleted successfully.'];
    } else {
        $res = json_decode($response, true);
        $error = $res['error']['message'] ?? 'Unknown error';
        return ['status' => 'error', 'message' => 'Failed to delete snapshot: ' . $error];
    }
}

function perform_rebuild_snapshot($snapshot_id, $params) {
    $apiKey = $params['serverpassword'];
    $serverID = $params['customfields']['Hetzner Server ID'];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.hetzner.cloud/v1/servers/{$serverID}/actions/rebuild");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$apiKey}", "Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['image' => $snapshot_id]));
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpcode === 201) {
        return ['status' => 'success', 'message' => 'Rebuild from snapshot initiated.'];
    } else {
        $res = json_decode($response, true);
        $error = $res['error']['message'] ?? 'Unknown error';
        return ['status' => 'error', 'message' => 'Failed to rebuild from snapshot: ' . $error];
    }
}
function generate_firewall_content($params) {
    $apiKey = $params['serverpassword'];
    $serverID = $params['customfields']['Hetzner Server ID'];
    $html = '<h3>Firewall Management</h3>';

    // Add description of firewall behavior
    $html .= '<div class="alert alert-info" style="margin-bottom: 20px;">';
    $html .= '<strong>Firewall Behavior:</strong><br>';
    $html .= '• <strong>Inbound Traffic:</strong> All incoming traffic is blocked by default, except for the rules you explicitly allow below.<br>';
    $html .= '• <strong>Outbound Traffic:</strong> All outgoing traffic is allowed by default, except for the rules you explicitly block below (e.g., SMTP ports 25 and 465 are blocked by default).';
    $html .= '</div>';

    // Fetch firewalls
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.hetzner.cloud/v1/firewalls");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$apiKey}"]);
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $firewall_id = null;
    $firewall_name = null;
    $rules = [];

    if ($httpcode === 200) {
        $firewalls = json_decode($response, true)['firewalls'];
        foreach ($firewalls as $fw) {
            foreach ($fw['applied_to'] as $applied) {
                if ($applied['server']['id'] == $serverID) {
                    $firewall_id = $fw['id'];
                    $firewall_name = $fw['name'];
                    $rules = $fw['rules'];
                    break 2;
                }
            }
        }
    }

    if (!$firewall_id) {
        $html .= '<p class="alert alert-danger">No firewall is attached to this server.</p>';
    } else {
        $html .= '<p><strong>Firewall:</strong> ' . htmlspecialchars($firewall_name) . '</p>';

        $html .= '<h4>Firewall Rules</h4>';
        $html .= '<table class="table table-bordered table-striped"><thead><tr>';
        $html .= '<th>Direction</th><th>Protocol</th><th>Port</th><th>Source/Destination</th><th>Description</th><th>Action</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($rules as $index => $rule) {
            $direction = ucfirst($rule['direction']);
            $protocol = $rule['protocol'];
            $port = $rule['port'] ?? '-';
            $description = $rule['description'] ?? '-';
            $ip_field = $rule['direction'] === 'in' ? 'source_ips' : 'destination_ips';
            $ip_list = isset($rule[$ip_field]) ? implode(', ', $rule[$ip_field]) : '-';
            $html .= '<tr>';
            $html .= '<td>' . $direction . '</td>';
            $html .= '<td>' . $protocol . '</td>';
            $html .= '<td>' . $port . '</td>';
            $html .= '<td>' . $ip_list . '</td>';
            $html .= '<td>' . htmlspecialchars($description) . '</td>';
            $html .= '<td>';
            // Check if the rule is managed (case-insensitive and trim whitespace)
            $isManagedRule = isset($rule['description']) && stripos(trim($rule['description']), 'Managed by us') !== false;
            if (!$isManagedRule) {
                $html .= '<form method="post">';
                $html .= '<input type="hidden" name="ajax" value="true">';
                $html .= '<input type="hidden" name="tab" value="firewall">';
                $html .= '<input type="hidden" name="rule_index" value="' . $index . '">';
                $html .= '<button type="submit" name="delete_rule" value="true" class="btn btn-danger btn-sm">Delete</button>';
                $html .= '</form>';
            } else {
                $html .= '<span style="color: #888;">Managed Rule</span>';
            }
            $html .= '</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';

        $html .= '<form method="post">';
        $html .= '<input type="hidden" name="ajax" value="true">';
        $html .= '<input type="hidden" name="tab" value="firewall">';
        $html .= '<h5>Add New Rule</h5>';
        $html .= '<div class="form-group">';
        $html .= '<label for="direction">Direction</label>';
        $html .= '<select name="direction" id="direction" class="form-control" required>';
        $html .= '<option value="in">Ingress (Inbound)</option>';
        $html .= '<option value="out">Egress (Outbound)</option>';
        $html .= '</select>';
        $html .= '</div>';
        $html .= '<div class="form-group">';
        $html .= '<label for="protocol">Protocol</label>';
        $html .= '<select name="protocol" id="protocol" class="form-control" required>';
        $html .= '<option value="tcp">TCP</option>';
        $html .= '<option value="udp">UDP</option>';
        $html .= '</select>';
        $html .= '</div>';
        $html .= '<div class="form-group">';
        $html .= '<label for="port">Port(s)</label>';
        $html .= '<input type="text" name="port" id="port" class="form-control" placeholder="e.g., 80, 443, 1-65535" required>';
        $html .= '</div>';
        $html .= '<div class="form-group">';
        $html .= '<label for="source">Source (for incoming) or Destination (for outgoing) IP</label>';
        $html .= '<input type="text" name="source" id="source" class="form-control" value="" placeholder="e.g., 0.0.0.0/0, 192.168.1.0/24" required>';
        $html .= '</div>';
        $html .= '<button type="submit" name="add_rule" value="true" class="btn btn-success">Add Rule</button>';
        $html .= '</form>';
    }

    return $html;
}
function perform_firewall_action($params) {
    $apiKey = $params['serverpassword'];
    $serverID = $params['customfields']['Hetzner Server ID'];

    // Fetch current firewalls
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.hetzner.cloud/v1/firewalls");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$apiKey}"]);
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $firewall_id = null;
    $rules = [];
    if ($httpcode === 200) {
        $firewalls = json_decode($response, true)['firewalls'];
        foreach ($firewalls as $fw) {
            foreach ($fw['applied_to'] as $applied) {
                if ($applied['server']['id'] == $serverID) {
                    $firewall_id = $fw['id'];
                    $rules = $fw['rules'];
                    break 2;
                }
            }
        }
    }

    if (isset($_REQUEST['add_rule']) && $firewall_id) {
        $direction = $_REQUEST['direction'];
        $protocol = $_REQUEST['protocol'];
        $port = $_REQUEST['port'];
        $source = $_REQUEST['source'];
        $new_rule = [
            'direction' => $direction,
            'protocol' => $protocol,
            'port' => $port,
        ];
        $new_rule[$direction === 'in' ? 'source_ips' : 'destination_ips'] = [$source];
        $rules[] = $new_rule;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://api.hetzner.cloud/v1/firewalls/{$firewall_id}/actions/set_rules");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$apiKey}", "Content-Type: application/json"]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['rules' => $rules]));
        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpcode === 201) {
            return ['status' => 'success', 'message' => 'Rule added successfully.'];
        } else {
            $res = json_decode($response, true);
            $error = $res['error']['message'] ?? 'Unknown error';
            return ['status' => 'error', 'message' => 'Failed to add rule: ' . $error];
        }
    } elseif (isset($_REQUEST['delete_rule']) && $firewall_id && isset($_REQUEST['rule_index'])) {
        $index = (int)$_REQUEST['rule_index'];
        // Check if the rule is managed
        $isManagedRule = isset($rules[$index]['description']) && strpos($rules[$index]['description'], 'Managed by us') !== false;
        if ($isManagedRule) {
            // Log the attempt to delete a managed rule
            logModuleCall(
                'hetznercloud',
                'perform_firewall_action_delete_rule',
                ['serverId' => $serverID, 'rule_index' => $index, 'rule' => $rules[$index]],
                'Attempted to delete a managed rule',
                'Client attempted to delete a managed firewall rule: ' . json_encode($rules[$index]),
                ['apiToken']
            );
            return ['status' => 'error', 'message' => 'Cannot delete managed rules. These rules are set by the system for security purposes.'];
        }

        unset($rules[$index]);
        $rules = array_values($rules);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://api.hetzner.cloud/v1/firewalls/{$firewall_id}/actions/set_rules");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$apiKey}", "Content-Type: application/json"]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['rules' => $rules]));
        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpcode === 201) {
            return ['status' => 'success', 'message' => 'Rule deleted successfully.'];
        } else {
            $res = json_decode($response, true);
            $error = $res['error']['message'] ?? 'Unknown error';
            return ['status' => 'error', 'message' => 'Failed to delete rule: ' . $error];
        }
    }

    return ['status' => 'error', 'message' => 'No valid firewall action specified.'];
}
function hetznercloud_SuspendAccount($params) {
    $apiKey = $params['serverpassword'];
    $serverID = $params['customfields']['Hetzner Server ID'];

    if (empty($serverID)) {
        return "Server ID is missing.";
    }

    // Power off the server
    $powerOffResult = perform_power_action('poweroff', $params);
    if ($powerOffResult['status'] !== 'success') {
        logModuleCall(
            'hetznercloud',
            'SuspendAccount_poweroff',
            ['serverId' => $serverID, 'action' => 'poweroff'],
            $powerOffResult['message'],
            'Failed to power off server during suspension',
            ['apiToken']
        );
        return "Failed to power off server during suspension: " . $powerOffResult['message'];
    } else {
        logModuleCall(
            'hetznercloud',
            'SuspendAccount_poweroff',
            ['serverId' => $serverID, 'action' => 'poweroff'],
            $powerOffResult['message'],
            'Successfully powered off server during suspension',
            ['apiToken']
        );
    }

    return 'success';
}
function hetznercloud_UnsuspendAccount($params) {
    $apiKey = $params['serverpassword'];
    $serverID = $params['customfields']['Hetzner Server ID'];

    if (empty($serverID)) {
        return "Server ID is missing.";
    }

    // Power on the server
    $powerOnResult = perform_power_action('poweron', $params);
    if ($powerOnResult['status'] !== 'success') {
        logModuleCall(
            'hetznercloud',
            'UnsuspendAccount_poweron',
            ['serverId' => $serverID, 'action' => 'poweron'],
            $powerOnResult['message'],
            'Failed to power on server during unsuspension',
            ['apiToken']
        );
        return "Failed to power on server during unsuspension: " . $powerOnResult['message'];
    } else {
        logModuleCall(
            'hetznercloud',
            'UnsuspendAccount_poweron',
            ['serverId' => $serverID, 'action' => 'poweron'],
            $powerOnResult['message'],
            'Successfully powered on server during unsuspension',
            ['apiToken']
        );
    }

    return 'success';
}
?>
