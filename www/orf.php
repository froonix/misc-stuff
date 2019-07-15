<?php
// Da es vom ORF keine gemeinsamen M3U8-Playlists gibt,
// in der die Streams aller Bandbreiten aufgeführt sind,
// werden sie von diesem Script erzeugt. Eine statische
// M3U8-Playlist hätte wenig Sinn, da sich die Bitraten
// und Codecs ändern könnten. Siehe auch: HLS-LAN-PRX

define('CACHE_TIMEOUT',  30);
define('SOCKET_TIMEOUT',  5);

// --------------------------------- //
// orf1     = ORF 1                  //
// orf2     = ORF 2                  //
// orf2b    = ORF 2 Burgenland       //
// orf2k    = ORF 2 Kärnten          //
// orf2n    = ORF 2 Niederösterreich //
// orf2ooe  = ORF 2 Oberösterreich   //
// orf2s    = ORF 2 Salzburg         //
// orf2stmk = ORF 2 Steiermark       //
// orf2t    = ORF 2 Tirol            //
// orf2v    = ORF 2 Vorarlberg       //
// orf2w    = ORF 2 Wien             //
// orf3     = ORF III                //
// orfs     = ORF Sport +            //
// --------------------------------- //

if(isset($_GET['source']))
{
	header('Content-Type: text/html; charset=UTF-8');
	highlight_file(__file__);
	exit;
}

function exception_error_handler($severity, $message, $file, $line)
{
	if(!(error_reporting() & $severity))
	{
		return;
	}
	throw new ErrorException($message, 0, $severity, $file, $line);
}

set_error_handler('exception_error_handler');
error_reporting(-1);

ini_set('default_socket_timeout', SOCKET_TIMEOUT);
#ini_set('user_agent', '');

if(!($channel = (isset($_GET['c'])) ? strtolower(basename((string) $_GET['c'])) : null))
{
	throw new BadFunctionCallException();
}

$cache = sprintf('%s/orf-%s.m3u8', sys_get_temp_dir(), md5($channel));

if(!file_exists($cache) || ($m = filemtime($cache)) < (time() - CACHE_TIMEOUT) || !($s = filesize($cache)))
{
	$m3u8 = [];
	foreach(['q1a', 'q4a', 'q6a', 'q8c'] as $quality) // qxb
	{
		$host = ($channel === 'orfs') ? 'https://%1$s.mdn.ors.at' : 'http://%1$s.cdn.ors.at';
		$base = sprintf($host . '/out/u/%1$s/%2$s/', rawurlencode($channel), rawurlencode($quality));

		if(($result = file_get_contents($base . 'manifest.m3u8')) === false)
		{
			throw new Exception($quality);
		}

		$lines = explode("\n", $result);
		$c = count($lines);

		for($i = 0; $i < $c; $i++)
		{
			$line = $lines[$i];

			if(substr($line, 0, 18) === '#EXT-X-STREAM-INF:')
			{
				if(empty($lines[++$i]))
				{
					throw new Exception($i);
				}

				if(strpos($lines[$i], '://') === false)
				{
					$lines[$i] = $base . $lines[$i];
				}

				$m3u8[] = $line;
				$m3u8[] = $lines[$i];
			}
		}
	}

	if(!count($m3u8))
	{
		throw new Exception();
	}

	array_unshift($m3u8, '#EXT-X-VERSION:3');
	array_unshift($m3u8, '#EXT-X-ALLOW-CACHE:NO');
	array_unshift($m3u8, '#EXTM3U');

	$_ = implode("\n", $m3u8) . "\n";
	file_put_contents($cache, $_, LOCK_EX);

	$s = strlen($_);
	$m = time();

	if(strpos($_, '/system_clips/') !== false)
	{
		$m -= CACHE_TIMEOUT;
		touch($cache, $m);
	}
}

header('Content-Type: application/vnd.apple.mpegurl; charset=utf-8');
header(sprintf('Last-Modified: %s GMT', gmdate('D, d M Y H:i:s', $m)));
header(sprintf('Content-Length: %u', $s));
readfile($cache);

?>
