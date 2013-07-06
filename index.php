<?php

session_start();

if (!isset($_SERVER['PHP_AUTH_USER']))
{
            header('WWW-Authenticate: Basic realm="Conference"');
            header('HTTP/1.0 401 Unauthorized');
            echo 'This section only available for export priv.';
            exit;
}
else if ( ! authenticate())
{
            header('WWW-Authenticate: Basic realm="Conference"');
            header('HTTP/1.0 401 Unauthorized');
            echo 'This section only available for export priv.';
            exit;
}

echo file_get_contents('fspanel.txt');

function authenticate()
{
	if (($_SERVER['PHP_AUTH_USER']=='networx')&&($_SERVER['PHP_AUTH_PW']=='c0nf3r3nceBURn')) 
	{
		unset($_SESSION['conf']);
		return true;
	}

	$pin = (int)$_SERVER['PHP_AUTH_USER'];
	$auth = file_get_contents("https://webpbx.connectingpointpro.com/auth.php?caller_pin=$pin&account_id={$_SERVER['PHP_AUTH_PW']}");
	if ($auth == 1)
	{
		$_SESSION['conf'] = $pin;
		return true;
	}
	return false;
}
