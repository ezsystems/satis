<?php
// HTTP 403: This is shown when a support user logs in, and the subscription has expired
header( "Content-type: text/html" );
header( "Status: Your subscription has expired. Please contact eZ Systems Sales to renew it." );
?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <title>Your subscription has expired</title>
    </head>
    <body>
        <p>Your subscription has expired. Please contact eZ Systems Sales to renew it.</p>
    </body>
</html>
