<?php

// phpinfo() output: Hide server admin's e-mail address.
// Live demo: https://wh-nc.fnx.li/ (netcup Expert Light)

ob_start('censor');
phpinfo(INFO_ALL);

function censor($str)
{
	if(preg_match('/^(.*?)@(.*?)\.(.*?)$/', $_SERVER['SERVER_ADMIN'], $matches))
	{
		$user = str_repeat('*', strlen($matches[1]));
		$domain = str_repeat('*', strlen($matches[2]));
		$tld = str_repeat('*', strlen($matches[3]));

		$str = str_replace($_SERVER['SERVER_ADMIN'], sprintf('%s@%s.%s (hidden)', $user, $domain, $tld), $str);
	}

	return $str;
}

?>
