{* templates/clientareaserverdetails.tpl *}

<div class="moduleoutput">
    {if $error}
        <div class="alert alert-danger">{$error}</div>
    {else}
        {if $serverDetails}
            <h3>Server Details</h3>
            <p><strong>ID:</strong> {$serverDetails.id|default:'N/A'}</p>
            <p><strong>Name:</strong> {$serverDetails.name|default:'N/A'}</p>
            <p><strong>Status:</strong> {$serverDetails.status|default:'N/A'}</p>
            <p><strong>Created:</strong> {$serverDetails.created|default:'N/A'}</p>

            <h4>Public Network</h4>
            {if $serverDetails.public_net.ipv4}
                <p><strong>IPv4:</strong> {$serverDetails.public_net.ipv4.ip|default:'N/A'}</p>
            {/if}
            {if $serverDetails.public_net.ipv6}
                <p><strong>IPv6:</strong> {$serverDetails.public_net.ipv6.ip|default:'N/A'} / {$serverDetails.publ>
            {/if}

            <h4>Location</h4>
            <p><strong>Name:</strong> {$serverDetails.datacenter.location.name|default:'N/A'}</p>
            <p><strong>Description:</strong> {$serverDetails.datacenter.location.description|default:'N/A'}</p>
            <p><strong>Datacenter:</strong> {$serverDetails.datacenter.name|default:'N/A'}</p>

            <h4>Server Type</h4>
            <p><strong>Name:</strong> {$serverDetails.server_type.name|default:'N/A'}</p>
            <p><strong>Cores:</strong> {$serverDetails.server_type.cores|default:'N/A'}</p>
            <p><strong>Memory:</strong> {$serverDetails.server_type.memory|default:'N/A'} GB</p>
            <p><strong>Disk:</strong> {$serverDetails.server_type.disk|default:'N/A'} GB</p>

            <h4>Image</h4>
            <p><strong>Name:</strong> {$serverDetails.image.name|default:'N/A'}</p>
            <p><strong>OS Flavor:</strong> {$serverDetails.image.os_flavor|default:'N/A'}</p>
            <p><strong>OS Version:</strong> {$serverDetails.image.os_version|default:'N/A'}</p>

            {* Add other server details you want to display here *}
        {else}
            <p>No server details available.</p>
        {/if}
    {/if}
</div>
