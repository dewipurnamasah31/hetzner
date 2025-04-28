<?php

//require_once ROOTDIR . '/includes/functions.php';

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

//require_once '/var/www/console/init.php';

// 1) Load WHMCS core (adjust path if your layout differs)

use WHMCS\Database\Capsule;


$GLOBALS['ch'] = null;
/**
 * Hetzner Cloud Module Metadata
 *
 * Defines the module's display name, type, and supported features.
 *
 * @return array
 */
function hetznercloud_MetaData()
{
    return [
        'DisplayName' => 'Hetzner Cloud Server',
        'Type' => 'server',
        'SupportedFeatures' => [
            'CreateAccount',
            'TerminateAccount',
            'SuspendAccount',
            'UnsuspendAccount',
            'ChangePackage',
            'AdminCustomButtonArray',
            'ClientAreaCustomButtonArray',
        ],
    ];
}

function hetznercloud_ConfigOptions()
{
    return [
        'api_key' => [
            'Type' => 'text',
            'FriendlyName' => 'Hetzner API Key',
            'Description' => 'Enter your Hetzner API Key',
        ],
        'server_type' => [
            'Type' => 'dropdown',
            'FriendlyName' => 'Server Type',
            'Description' => 'Select the server type',
            'Options' => 'cx22|CX22,cx32|CX32,cx42|CX42,cx52|CX52,cpx11|CPX11,cpx21|CPX21,cpx31|CPX31,cpx41|CPX41,cpx51|CPX51', // Added static server types
        ],
    ];
}

/**
 * Hetzner Cloud API Request Helper Function
 *
 * Handles communication with the Hetzner Cloud API.
 *
 * @param string $apiKey The Hetzner API key.
 * @param string $command The API endpoint to call.
 * @param string $method The HTTP method (GET, POST, PUT, DELETE).
 * @param array $postfields An array of data to send with the request.
 * @return string The API response as a JSON string.
 * @throws \Exception If a cURL error occurs.
 */
function hetznercloud_api_request($apiKey, $command, $method = 'GET', $postfields = [])
{
    $url = 'https://api.hetzner.cloud/v1' . $command;
    $ch = curl_init();
    $GLOBALS['ch'] = $ch; // Store the curl handle
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
        'Accept: application/json',
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

    if ($method === 'POST' || $method === 'PUT') {
        $encodedPostfields = json_encode($postfields);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $encodedPostfields);
        logModuleCall('hetznercloud', 'API Request Body (' . $method . ' ' . $command . ')', [], $encodedPostfields); // Log the request body
    }

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        throw new \Exception('cURL Error: ' . $error);
    }

    return $response;
}

function hetznercloud_CreateAccount(array $params)
{
    $apiKey = $params['configoption1'];
    $serverName = $params['domain'];
    $serverTypeWithLabel = $params['configoption2'];
    $serverType = explode('|', $serverTypeWithLabel)[0];
    $osTemplate = $params['customfields']['Operating System'];
    $location = $params['customfields']['Location'];

    // Validate server name
    if (empty($serverName)) {
        $serverName = 'server-' . time(); // Generate a unique default name
        logModuleCall('hetznercloud', 'CreateAccount', $params, 'Warning: Server name was empty. Using default name: ' . $serverName);
    } elseif (!preg_match('/^[a-zA-Z0-9.-]+$/', $serverName)) { // Check for allowed characters
        $error = 'Invalid server name.  Only alphanumeric characters, hyphens, and periods are allowed.';
        logModuleCall('hetznercloud', 'CreateAccount', $params, 'Error: ' . $error);
        return $error;
    } elseif (strlen($serverName) > 64) { //check the length
        $error = 'Invalid server name.  The maximum length is 64 characters.';
        logModuleCall('hetznercloud', 'CreateAccount', $params, 'Error: ' . $error);
        return $error;
    }

    try {
        $command = "/servers";
        $postfields = [
            'name' => $serverName,
            'server_type' => $serverType,
            'image' => $osTemplate,
            'location' => $location,
        ];

        $response = hetznercloud_api_request($apiKey, $command, 'POST', $postfields);
        $data = json_decode($response, true);

        if (isset($data['server']['id'])) {
            $serverID = $data['server']['id'];
            $ipv4 = $data['server']['public_net']['ipv4']['ip'];
            $rescuePassword = isset($data['server']['root_password']) ? $data['server']['root_password'] : '';

            // Update dedicated IP in tblhosting
            Capsule::table('tblhosting')
                ->where('id', $params['serviceid'])
                ->update(['dedicatedip' => $ipv4]);

            // Update custom field values in tblcustomfieldsvalues
            $customFields = [
                'Hetzner Server ID' => $serverID,
                'Hetzner IPv4' => $ipv4,
                'Operating System' => $osTemplate,
                'Location' => $location,
                'Rescue Password' => $rescuePassword,
            ];

            foreach ($customFields as $fieldName => $value) {
                $fieldId = Capsule::table('tblcustomfields')
                    ->where('relid', $params['packageid']) // Assuming custom fields are related to the product
                    ->where('fieldname', $fieldName)
                    ->value('id');

                if ($fieldId) {
                    Capsule::table('tblcustomfieldsvalues')
                        ->insertOrIgnore([
                            'fieldid' => $fieldId,
                            'relid' => $params['serviceid'],
                            'value' => $value,
                        ]);
                    Capsule::table('tblcustomfieldsvalues')
                        ->where('fieldid', $fieldId)
                        ->where('relid', $params['serviceid'])
                        ->update(['value' => $value]);
                }
            }

            logModuleCall('hetznercloud', 'CreateAccount', $params, 'Success - Server ID: ' . $serverID . ' - Response: ' . $response);
            return 'success';
        } else {
            $error = 'Failed to create server: ' . (isset($data['error']['message']) ? $data['error']['message'] : $response);
            logModuleCall('hetznercloud', 'CreateAccount', $params, 'Error: ' . $error);
            return $error;
        }
    } catch (\Exception $e) {
        logModuleCall('hetznercloud', 'CreateAccount', $params, 'Exception: ' . $e->getMessage());
        return 'Error: ' . $e->getMessage();
    }
}

/**
 * Hetzner Cloud Get OS Templates
 *
 * Fetches available operating system templates from the Hetzner API.
 *
 * @return array An array of OS templates for the dropdown.
 */
function hetznercloud_get_os_templates_for_config()
{
    $apiKey = get_module_setting('api_key', 'hetznercloud');
    logModuleCall('hetznercloud', 'get_os_templates_config - API Key', ['API Key' => $apiKey], ''); // Log API Key
    $templates = [];
    try {
        $response = hetznercloud_api_request($apiKey, '/images');
        logModuleCall('hetznercloud', 'get_os_templates_config - API Response', [], $response); // Log API Response
        $data = json_decode($response, true);
        logModuleCall('hetznercloud', 'get_os_templates_config - Decoded Data', [], print_r($data, true)); // Log Decoded Data
        if (isset($data['images']) && is_array($data['images'])) {
            foreach ($data['images'] as $image) {
                $templates[] = $image['id'] . '|' . $image['description'] . ' (' . $image['os_flavor'] . ' ' . $image['os_version'] . ')';
            }
        }
    } catch (\Exception $e) {
        logModuleCall('hetznercloud', 'get_os_templates_config - Error', [], 'Error: ' . $e->getMessage());
    }
    return implode(',', $templates);
}

/**
 * Hetzner Cloud Get Server Types
 *
 * Fetches available server types from the Hetzner API, optionally filtered by group.
 *
 * @return array An array of server types for the dropdown.
 */
function hetznercloud_get_server_types_for_config()
{
    $apiKey = get_module_setting('api_key', 'hetznercloud');
    $group = get_module_setting('server_type_group', 'hetznercloud');
    logModuleCall('hetznercloud', 'get_server_types_config - API Key & Group', ['API Key' => $apiKey, 'Group' => $group], ''); // Log API Key and Group
    $serverTypes = [];
    $command = '/server_types';
    if (!empty($group)) {
        $command .= '?type=' . $group;
    }
    try {
        $response = hetznercloud_api_request($apiKey, $command);
        logModuleCall('hetznercloud', 'get_server_types_config - API Response', [], $response); // Log API Response
        $data = json_decode($response, true);
        logModuleCall('hetznercloud', 'get_server_types_config - Decoded Data', [], print_r($data, true)); // Log Decoded Data
        if (isset($data['server_types']) && is_array($data['server_types'])) {
            foreach ($data['server_types'] as $type) {
                $serverTypes[] = $type['id'] . '|' . $type['name'] . ' (CPU: ' . $type['cores'] . ' Cores, RAM: ' . $type['memory'] . ' GB)';
            }
        }
    } catch (\Exception $e) {
        logModuleCall('hetznercloud', 'get_server_types_config - Error', [], 'Error: ' . $e->getMessage());
    }
    return implode(',', $serverTypes);
}

/**
 * Hetzner Cloud Get Locations
 *
 * Fetches available locations from the Hetzner API, optionally filtered by group.
 *
 * @return array An array of locations for the dropdown.
 */
function hetznercloud_get_locations_for_config()
{
    $apiKey = get_module_setting('api_key', 'hetznercloud');
    $group = get_module_setting('location_group', 'hetznercloud');
    logModuleCall('hetznercloud', 'get_locations_for_config - API Key & Group', ['API Key' => $apiKey, 'Group' => $group], ''); // Log API Key and Group
    $locations = [];
    $command = '/locations';
    if (!empty($group)) {
        $command .= '?location=' . $group;
    }
    try {
        $response = hetznercloud_api_request($apiKey, $command);
        logModuleCall('hetznercloud', 'get_locations_for_config - API Response', [], $response); // Log API Response
        $data = json_decode($response, true);
        logModuleCall('hetznercloud', 'get_locations_for_config - Decoded Data', [], print_r($data, true)); // Log Decoded Data
        if (isset($data['locations']) && is_array($data['locations'])) {
            foreach ($data['locations'] as $location) {
                $locations[] = $location['name'] . '|' . $location['name'] . ' (' . $location['description'] . ')';
            }
        }
    } catch (\Exception $e) {
        logModuleCall('hetznercloud', 'get_locations_for_config - Error', [], 'Error: ' . $e->getMessage());
    }
    return implode(',', $locations);
}

/**
 * Hetzner Cloud Terminate Account
 *
 * Terminates (deletes) a server on the Hetzner Cloud.
 *
 * @param array $params An array of module parameters.
 * @return string 'success' on success, or an error message on failure.
 */
function hetznercloud_TerminateAccount(array $params)
{
    $apiKey = $params['configoption1'];
    $serviceID = $params['serviceid'];
    $serverID = get_query_val('tblhosting', 'customfields', ['id' => $serviceID]);
    $serverID = trim(explode('|', $serverID)[0]);

    try {
        $command = "/servers/" . $serverID;
        $response = hetznercloud_api_request($apiKey, $command, 'DELETE');
        $httpCode = curl_getinfo(null, CURLINFO_HTTP_CODE);

        if ($httpCode === 204) {
            logModuleCall('hetznercloud', 'TerminateAccount', $params, 'Success - Server ID: ' . $serverID);
            return 'success';
        } else {
            $error = 'Failed to terminate server (HTTP ' . $httpCode . '): ' . $response;
            logModuleCall('hetznercloud', 'TerminateAccount', $params, 'Error: ' . $error);
            return $error;
        }
    } catch (\Exception $e) {
        logModuleCall('hetznercloud', 'TerminateAccount', $params, 'Exception: ' . $e->getMessage());
        return 'Error: ' . $e->getMessage();
    }
}

/**
 * Hetzner Cloud Suspend Account
 *
 * Suspends a server on the Hetzner Cloud.
 *
 * @param array $params An array of module parameters.
 * @return string 'success' on success, or an error message on failure.
 */
function hetznercloud_SuspendAccount(array $params)
{
    $apiKey = $params['configoption1'];
    $serviceID = $params['serviceid'];

    // Get the ID of the 'Hetzner Server ID' custom field
    $fieldId = Capsule::table('tblcustomfields')
        ->where('relid', $params['packageid']) // Assuming custom fields are related to the product
        ->where('fieldname', 'Hetzner Server ID')
        ->value('id');

    if (!$fieldId) {
        $error = 'Custom field "Hetzner Server ID" not found for product ID: ' . $params['packageid'] . '. Cannot suspend.';
        logModuleCall('hetznercloud', 'SuspendAccount', $params, 'Error: ' . $error);
        return $error;
    }

    // Retrieve the Hetzner Server ID from tblcustomfieldsvalues
    $serverID = Capsule::table('tblcustomfieldsvalues')
        ->where('fieldid', $fieldId)
        ->where('relid', $serviceID)
        ->value('value');

    if (empty($serverID)) {
        $error = 'Hetzner Server ID not found for service ID: ' . $serviceID . ' in custom fields. Cannot suspend.';
        logModuleCall('hetznercloud', 'SuspendAccount', $params, 'Error: ' . $error);
        return $error;
    }

    try {
        $command = "/servers/" . $serverID . "/actions/shutdown";
        $postfields = ['type' => 'shutdown'];
        $response = hetznercloud_api_request($apiKey, $command, 'POST', $postfields);
        $httpCode = curl_getinfo($GLOBALS['ch'], CURLINFO_HTTP_CODE); // Get HTTP status code
        $data = json_decode($response, true);

        logModuleCall('hetznercloud', 'SuspendAccount', $params, 'HTTP Code: ' . $httpCode . ' - Response: ' . $response);

        if ($httpCode >= 200 && $httpCode < 300) {
            return 'success'; // Consider any successful HTTP status as success for initiation
        } else {
            $error = 'Failed to initiate suspend (shutdown) on server: ' . (isset($data['error']['message']) ? $data['error']['message'] : $response);
            return $error;
        }
    } catch (\Exception $e) {
        logModuleCall('hetznercloud', 'SuspendAccount', $params, 'Exception: ' . $e->getMessage());
        return 'Error: ' . $e->getMessage();
    }
}

/**
 * Hetzner Cloud Unsuspend Account
 *
 * Unsuspends (starts) a server on the Hetzner Cloud.
 *
 * @param array $params An array of module parameters.
 * @return string 'success' on success, or an error message on failure.
 */
function hetznercloud_UnsuspendAccount(array $params)
{
    $apiKey = $params['configoption1'];
    $serviceID = $params['serviceid'];

    // Get the ID of the 'Hetzner Server ID' custom field
    $fieldId = Capsule::table('tblcustomfields')
        ->where('relid', $params['packageid']) // Assuming custom fields are related to the product
        ->where('fieldname', 'Hetzner Server ID')
        ->value('id');

    if (!$fieldId) {
        $error = 'Custom field "Hetzner Server ID" not found for product ID: ' . $params['packageid'] . '. Cannot power on.';
        logModuleCall('hetznercloud', 'UnsuspendAccount', $params, 'Error: ' . $error);
        return $error;
    }

    // Retrieve the Hetzner Server ID from tblcustomfieldsvalues
    $serverID = Capsule::table('tblcustomfieldsvalues')
        ->where('fieldid', $fieldId)
        ->where('relid', $serviceID)
        ->value('value');

    if (empty($serverID)) {
        $error = 'Hetzner Server ID not found for service ID: ' . $serviceID . ' in custom fields. Cannot power on.';
        logModuleCall('hetznercloud', 'UnsuspendAccount', $params, 'Error: ' . $error);
        return $error;
    }

    try {
        $command = "/servers/" . $serverID . "/actions/poweron";
        $postfields = []; // Explicitly set an empty array for the POST body
        $response = hetznercloud_api_request($apiKey, $command, 'POST', $postfields); // Pass the empty array
        $httpCode = curl_getinfo($GLOBALS['ch'], CURLINFO_HTTP_CODE); // Get HTTP status code
        $data = json_decode($response, true);

        logModuleCall('hetznercloud', 'UnsuspendAccount', $params, 'HTTP Code: ' . $httpCode . ' - Response: ' . $response);

        if ($httpCode >= 200 && $httpCode < 300) {
            return 'success'; // Consider successful HTTP status as success for initiation
        } else {
            $error = 'Failed to initiate power on on server: ' . (isset($data['error']['message']) ? $data['error']['message'] : $response);
            return $error;
        }
    } catch (\Exception $e) {
        logModuleCall('hetznercloud', 'UnsuspendAccount', $params, 'Exception: ' . $e->getMessage());
        return 'Error: ' . $e->getMessage();
    }
}
// Part 3 of Hetzner Cloud WHMCS Module

/**
 * Hetzner Cloud Change Package
 *
 * Allows clients to upgrade/downgrade their server's package (server type).
 *
 * @param array $params An array of module parameters.
 * @return string 'success' on success, or an error message on failure.
 */
function hetznercloud_ChangePackage(array $params)
{
    $apiKey = $params['configoption1'];
    $serviceID = $params['serviceid'];
    $serverID = get_query_val('tblhosting', 'customfields', ['id' => $serviceID]);
    $serverID = trim(explode('|', $serverID)[0]);
    $newServerType = $params['configoption2']; // The new server type selected during upgrade/downgrade

    try {
        $command = "/servers/" . $serverID;
        $postfields = [
            'server_type' => $newServerType,
        ];
        $response = hetznercloud_api_request($apiKey, $command, 'PUT', $postfields);
        $data = json_decode($response, true);

        if (isset($data['server']['id']) && $data['server']['id'] == $serverID) {
            logModuleCall('hetznercloud', 'ChangePackage', $params, 'Success - Server ID: ' . $serverID . ' - Changed to: ' . $newServerType . ' - Response: ' . $response);
            return 'success';
        } else {
            $error = 'Failed to change server type: ' . (isset($data['error']['message']) ? $data['error']['message'] : $response);
            logModuleCall('hetznercloud', 'ChangePackage', $params, 'Error: ' . $error);
            return $error;
        }
    } catch (\Exception $e) {
        logModuleCall('hetznercloud', 'ChangePackage', $params, 'Exception: ' . $e->getMessage());
        return 'Error: ' . $e->getMessage();
    }
}

/**
 * Hetzner Cloud Admin Custom Button Array
 *
 * Defines custom buttons available in the admin area for a service.
 *
 * @return array
 */
function hetznercloud_AdminCustomButtonArray()
{
    return [
        "Get Server Details" => "getServerDetailsAdmin",
        "Power On" => "powerOnServerAdmin",
        "Power Off" => "powerOffServerAdmin",
        "Reboot" => "rebootServerAdmin",
        // Add more admin buttons as needed
    ];
}

/**
 * Hetzner Cloud Client Area Custom Button Array
 *
 * Defines custom buttons available in the client area for a service.
 *
 * @param array $params An array of module parameters.
 * @return array
 */
function hetznercloud_ClientAreaCustomButtonArray(array $params)
{
    return [
        "Server Details" => "showServerDetailsClient",
        "Power On" => "powerOnServerClient",
        "Power Off" => "powerOffServerClient",
        "Reboot" => "rebootServerClient",
        // Add more client area buttons as needed
    ];
}

/**
 * Hetzner Cloud Get Server Details (Admin)
 *
 * Retrieves and displays detailed server information in the admin area.
 *
 * @param array $params An array of module parameters.
 * @return string The output to display in the admin area.
 */
function hetznercloud_getServerDetailsAdmin(array $params)
{
    $apiKey = $params['configoption1'];
    $serviceID = $params['serviceid'];
    $serverID = get_query_val('tblhosting', 'customfields', ['id' => $serviceID]);
    $serverID = trim(explode('|', $serverID)[0]);

    try {
        $command = "/servers/" . $serverID;
        $response = hetznercloud_api_request($apiKey, $command, 'GET');
        $data = json_decode($response, true);

        logModuleCall('hetznercloud', 'getServerDetailsAdmin', $params, 'Response: ' . $response);

        if (isset($data['server'])) {
            $output = '<pre>' . print_r($data['server'], true) . '</pre>';
            return $output;
        } else {
            return 'Error fetching server details: ' . (isset($data['error']['message']) ? $data['error']['message'] : $response);
        }
    } catch (\Exception $e) {
        return 'Error fetching server details: ' . $e->getMessage();
    }
}

/**
 * Hetzner Cloud Power On Server (Admin)
 *
 * Powers on a server from the admin area.
 *
 * @param array $params An array of module parameters.
 * @return string Redirect URL on success, or an error message.
 */
function hetznercloud_powerOnServerAdmin(array $params)
{
    $apiKey = $params['configoption1'];
    $serviceID = $params['serviceid'];
    $serverID = get_query_val('tblhosting', 'customfields', ['id' => $serviceID]);
    $serverID = trim(explode('|', $serverID)[0]);

    try {
        $command = "/servers/" . $serverID . "/actions/poweron";
        $postfields = ['type' => 'poweron'];
        $response = hetznercloud_api_request($apiKey, $command, 'POST', $postfields);
        $data = json_decode($response, true);

        logModuleCall('hetznercloud', 'powerOnServerAdmin', $params, 'Response: ' . $response);

        if (isset($data['action']['status']) && $data['action']['status'] === 'success') {
            return 'success';
        } else {
            return 'Error powering on server: ' . (isset($data['error']['message']) ? $data['error']['message'] : $response);
        }
    } catch (\Exception $e) {
        return 'Error powering on server: ' . $e->getMessage();
    }
}
// Part 4 of Hetzner Cloud WHMCS Module

/**
 * Hetzner Cloud Power Off Server (Admin)
 *
 * Powers off a server from the admin area.
 *
 * @param array $params An array of module parameters.
 * @return string Redirect URL on success, or an error message.
 */
function hetznercloud_powerOffServerAdmin(array $params)
{
    $apiKey = $params['configoption1'];
    $serviceID = $params['serviceid'];
    $serverID = get_query_val('tblhosting', 'customfields', ['id' => $serviceID]);
    $serverID = trim(explode('|', $serverID)[0]);

    try {
        $command = "/servers/" . $serverID . "/actions/shutdown";
        $postfields = ['type' => 'shutdown'];
        $response = hetznercloud_api_request($apiKey, $command, 'POST', $postfields);
        $data = json_decode($response, true);

        logModuleCall('hetznercloud', 'powerOffServerAdmin', $params, 'Response: ' . $response);

        if (isset($data['action']['status']) && $data['action']['status'] === 'success') {
            return 'success';
        } else {
            return 'Error powering off server: ' . (isset($data['error']['message']) ? $data['error']['message'] : $response);
        }
    } catch (\Exception $e) {
        return 'Error powering off server: ' . $e->getMessage();
    }
}

/**
 * Hetzner Cloud Reboot Server (Admin)
 *
 * Reboots a server from the admin area.
 *
 * @param array $params An array of module parameters.
 * @return string Redirect URL on success, or an error message.
 */
function hetznercloud_rebootServerAdmin(array $params)
{
    $apiKey = $params['configoption1'];
    $serviceID = $params['serviceid'];
    $serverID = get_query_val('tblhosting', 'customfields', ['id' => $serviceID]);
    $serverID = trim(explode('|', $serverID)[0]);

    try {
        $command = "/servers/" . $serverID . "/actions/reboot";
        $postfields = ['type' => 'reboot'];
        $response = hetznercloud_api_request($apiKey, $command, 'POST', $postfields);
        $data = json_decode($response, true);

        logModuleCall('hetznercloud', 'rebootServerAdmin', $params, 'Response: ' . $response);

        if (isset($data['action']['status']) && $data['action']['status'] === 'success') {
            return 'success';
        } else {
            return 'Error rebooting server: ' . (isset($data['error']['message']) ? $data['error']['message'] : $response);
        }
    } catch (\Exception $e) {
        return 'Error rebooting server: ' . $e->getMessage();
    }
}

/**
 * Hetzner Cloud Show Server Details (Client Area)
 *
 * Displays server details in the client area.
 *
 * @param array $params An array of module parameters.
 * @return array The output for the client area template.
 */
function hetznercloud_showServerDetailsClient(array $params)
{
    $apiKey = $params['configoption1'];
    $serviceID = $params['serviceid'];
    $serverID = get_query_val('tblhosting', 'customfields', ['id' => $serviceID]);
    $serverID = trim(explode('|', $serverID)[0]);

    $serverDetails = [];
    $error = '';

    try {
        $command = "/servers/" . $serverID;
        $response = hetznercloud_api_request($apiKey, $command, 'GET');
        $data = json_decode($response, true);

        logModuleCall('hetznercloud', 'showServerDetailsClient', $params, 'Response: ' . $response);

        if (isset($data['server'])) {
            $serverDetails = $data['server'];
        } else {
            $error = 'Error fetching server details: ' . (isset($data['error']['message']) ? $data['error']['message'] : $response);
        }
    } catch (\Exception $e) {
        $error = 'Error fetching server details: ' . $e->getMessage();
        logModuleCall('hetznercloud', 'showServerDetailsClient', $params, 'Exception: ' . $e->getMessage());
    }

    $smarty = new Smarty();
    $smarty->assign('serverDetails', $serverDetails);
    $smarty->assign('error', $error);
    $smarty->assign('moduleParams', $params);

    return [
        'tabOverviewOutput' => $smarty->fetch(dirname(__FILE__) . '/templates/clientarea.tpl'),
        'templatefile' => 'clientareaserverdetails', // Use a custom template for the full details
    ];
}

/**
 * Hetzner Cloud Power On Server (Client Area)
 *
 * Powers on a server from the client area.
 *
 * @param array $params An array of module parameters.
 * @return string Redirect URL.
 */
function hetznercloud_powerOnServerClient(array $params)
{
    $apiKey = $params['configoption1'];
    $serviceID = $params['serviceid'];
    $serverID = get_query_val('tblhosting', 'customfields', ['id' => $serviceID]);
    $serverID = trim(explode('|', $serverID)[0]);

    try {
        $command = "/servers/" . $serverID . "/actions/poweron";
        $postfields = ['type' => 'poweron'];
        $response = hetznercloud_api_request($apiKey, $command, 'POST', $postfields);
        $data = json_decode($response, true);

        logModuleCall('hetznercloud', 'powerOnServerClient', $params, 'Response: ' . $response);

        if (isset($data['action']['status']) && $data['action']['status'] === 'success') {
            return 'success'; // Or a message for the client
        } else {
            return 'Error powering on server: ' . (isset($data['error']['message']) ? $data['error']['message'] : $response);
        }
    } catch (\Exception $e) {
        return 'Error powering on server: ' . $e->getMessage();
    }
}
// Part 5 of Hetzner Cloud WHMCS Module

/**
 * Hetzner Cloud Power Off Server (Client Area)
 *
 * Powers off a server from the client area.
 *
 * @param array $params An array of module parameters.
 * @return string Redirect URL.
 */
function hetznercloud_powerOffServerClient(array $params)
{
    $apiKey = $params['configoption1'];
    $serviceID = $params['serviceid'];
    $serverID = get_query_val('tblhosting', 'customfields', ['id' => $serviceID]);
    $serverID = trim(explode('|', $serverID)[0]);

    try {
        $command = "/servers/" . $serverID . "/actions/shutdown";
        $postfields = ['type' => 'shutdown'];
        $response = hetznercloud_api_request($apiKey, $command, 'POST', $postfields);
        $data = json_decode($response, true);

        logModuleCall('hetznercloud', 'powerOffServerClient', $params, 'Response: ' . $response);

        if (isset($data['action']['status']) && $data['action']['status'] === 'success') {
            return 'success'; // Or a message for the client
        } else {
            return 'Error powering off server: ' . (isset($data['error']['message']) ? $data['error']['message'] : $response);
        }
    } catch (\Exception $e) {
        return 'Error powering off server: ' . $e->getMessage();
    }
}

/**
 * Hetzner Cloud Reboot Server (Client Area)
 *
 * Reboots a server from the client area.
 *
 * @param array $params An array of module parameters.
 * @return string Redirect URL.
 */
function hetznercloud_rebootServerClient(array $params)
{
    $apiKey = $params['configoption1'];
    $serviceID = $params['serviceid'];
    $serverID = get_query_val('tblhosting', 'customfields', ['id' => $serviceID]);
    $serverID = trim(explode('|', $serverID)[0]);

    try {
        $command = "/servers/" . $serverID . "/actions/reboot";
        $postfields = ['type' => 'reboot'];
        $response = hetznercloud_api_request($apiKey, $command, 'POST', $postfields);
        $data = json_decode($response, true);

        logModuleCall('hetznercloud', 'rebootServerClient', $params, 'Response: ' . $response);

        if (isset($data['action']['status']) && $data['action']['status'] === 'success') {
            return 'success'; // Or a message for the client
        } else {
            return 'Error rebooting server: ' . (isset($data['error']['message']) ? $data['error']['message'] : $response);
        }
    } catch (\Exception $e) {
        return 'Error rebooting server: ' . $e->getMessage();
    }
}

/**
 * Hetzner Cloud Output Client Area
 *
 * Generates the client area output for the product overview tab.
 *
 * @param array $params An array of module parameters.
 * @return array An array containing the tab overview output and tabs array.
 */
function hetznercloud_outputClientArea(array $params)
{
    $apiKey = $params['configoption1'];
    $serviceID = $params['serviceid'];
    $serverID = get_query_val('tblhosting', 'customfields', ['id' => $serviceID]);
    $serverID = trim(explode('|', $serverID)[0]);
    $ipv4 = get_query_val('tblhosting', 'customfields', ['id' => $serviceID], 2); // Hetzner IPv4
    $ipv4 = trim(explode('|', $ipv4)[0]);
    $os = get_query_val('tblhosting', 'customfields', ['id' => $serviceID], 3); // Operating System
    $os = trim(explode('|', $os)[0]);
    $location = get_query_val('tblhosting', 'customfields', ['id' => $serviceID], 4); // Location
    $location = trim(explode('|', $location)[0]);

    $serverDetails = [];
    $error = '';

    try {
        $command = "/servers/" . $serverID;
        $response = hetznercloud_api_request($apiKey, $command, 'GET');
        $data = json_decode($response, true);

        logModuleCall('hetznercloud', 'outputClientArea', $params, 'Response: ' . $response);

        if (isset($data['server'])) {
            $serverDetails = $data['server'];
        } else {
            $error = 'Error fetching server details: ' . (isset($data['error']['message']) ? $data['error']['message'] : $response);
        }
    } catch (\Exception $e) {
        $error = 'Error fetching server details: ' . $e->getMessage();
        logModuleCall('hetznercloud', 'outputClientArea', $params, 'Exception: ' . $e->getMessage());
    }

    $smarty = new Smarty();
    $smarty->assign('serverID', $serverID);
    $smarty->assign('ipv4', $ipv4);
    $smarty->assign('os', $os);
    $smarty->assign('location', $location);
    $smarty->assign('serverDetails', $serverDetails);
    $smarty->assign('error', $error);
    $smarty->assign('moduleParams', $params);

    return [
        'tabOverviewOutput' => $smarty->fetch(dirname(__FILE__) . '/templates/clientarea.tpl'),
        'tabsArray' => [
            'details' => 'hetznercloud_getServerDetailsTab',
            // Add more tabs here if needed
        ],
    ];
}

/**
 * Hetzner Cloud Get Server Details Tab (Client Area)
 *
 * Generates the content for the "Server Details" tab in the client area.
 *
 * @param array $params An array of module parameters.
 * @return array The output for the tab content.
 */
function hetznercloud_getServerDetailsTab(array $params)
{
    $apiKey = $params['configoption1'];
    $serviceID = $params['serviceid'];
    $serverID = get_query_val('tblhosting', 'customfields', ['id' => $serviceID]);
    $serverID = trim(explode('|', $serverID)[0]);

    $serverDetails = [];
    $error = '';

    try {
        $command = "/servers/" . $serverID;
        $response = hetznercloud_api_request($apiKey, $command, 'GET');
        $data = json_decode($response, true);

        logModuleCall('hetznercloud', 'getServerDetailsTab', $params, 'Response: ' . $response);

        if (isset($data['server'])) {
            $serverDetails = $data['server'];
            $smarty = new Smarty();
            $smarty->assign('serverDetails', $serverDetails);
            $smarty->assign('error', $error);
            return $smarty->fetch(dirname(__FILE__) . '/templates/clientareaserverdetails.tpl');
        } else {
            $error = 'Error fetching detailed server details: ' . (isset($data['error']['message']) ? $data['error']['message'] : $response);
            $smarty = new Smarty();
            $smarty->assign('error', $error);
            return $smarty->fetch(dirname(__FILE__) . '/templates/clientareaserverdetails.tpl');
        }
    } catch (\Exception $e) {
        $error = 'Error fetching detailed server details: ' . $e->getMessage();
        logModuleCall('hetznercloud', 'getServerDetailsTab', $params, 'Exception: ' . $e->getMessage());
        $smarty = new Smarty();
        $smarty->assign('error', $error);
        return $smarty->fetch(dirname(__FILE__) . '/templates/clientareaserverdetails.tpl');
    }
}
