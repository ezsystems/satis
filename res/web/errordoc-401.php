<?php
// HTTP 401: This is shown when a support user logs in, and the token or password is wrong.
header( "Content-type: text/html" );
header( "Status: Your authentication token or password is wrong." );
?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <title>Your authentication token or password is wrong</title>
    </head>
    <body>
        <p>Your authentication token or password is wrong. Please try again with different data.
           If you have lost your password it cannot be recovered, but you can create a new token
           in your support portal.</p>
    </body>
</html>
