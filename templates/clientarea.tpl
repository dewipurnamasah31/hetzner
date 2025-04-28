{* templates/clientarea.tpl *}

<div class="moduleoutput">
    {if $error}
        <div class="alert alert-danger">{$error}</div>
    {else}
        <p><strong>Hetzner Server ID:</strong> {$serverID|default:'N/A'}</p>
        <p><strong>IPv4 Address:</strong> {$ipv4|default:'N/A'}</p>
        <p><strong>Operating System:</strong> {$os|default:'N/A'}</p>
        <p><strong>Location:</strong> {$location|default:'N/A'}</p>

        {if $serverDetails}
            <h3>Server Status</h3>
            <p>Status: {$serverDetails.status|default:'N/A'}</p>
            {* You can add more relevant server details here from the $serverDetails array *}
        {/if}
    {/if}

    {* You can add more client area specific content or action buttons here *}
</div>

