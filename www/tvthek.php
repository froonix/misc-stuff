<?php

if(isset($_GET['source']))
{
	header('Content-Type: text/html; charset=UTF-8');
	highlight_file(__file__);
	exit;
}

###################################################################################
# API-USERNAME & API-PASSWORD: https://github.com/s0faking/plugin.video.orftvthek #
#  /blob/69090e449e84346ff2a4fe47e359053eb44823db/resources/lib/serviceapi.py#L36 #
###################################################################################
define('API_USER', '<API-USERNAME>');
define('API_PASS', '<API-PASSWORD>');
define('API_HOST', 'api-tvthek.orf.at');
define('API_PATH', '/api/v3/');

define('API_BASE', sprintf('https://%s:%s@%s%s', API_USER, API_PASS, API_HOST, API_PATH));
define('API_CACHE', 300);

function API($r)
{
	$c = sprintf('%s/tvthek_%s.json', sys_get_temp_dir(), base64_encode($r));

	if(!API_CACHE || !file_exists($c) || filemtime($c) < (time() - API_CACHE) || !filesize($c) || ($data = file_get_contents($c)) === false)
	{
		$r = sprintf('%s%s', API_BASE, $r);

		if(($data = file_get_contents($r)) === false)
		{
			throw new RuntimeException(sprintf('API request failed: %s', $r));
		}

		if(API_CACHE)
		{
			file_put_contents($c, $data, LOCK_EX);
		}
	}

	if(($data = json_decode($data, true)) === null)
	{
		throw new RuntimeException(sprintf('Invalid JSON data received: [%d] %s', json_last_error(), json_last_error_msg()));
	}
	else if(!is_array($data))
	{
		throw new RuntimeException('Invalid JSON data received: Array excepected');
	}

	return $data;
}

function getEpisode($id, &$gapless = null, &$youth_protection = null)
{
	$data = API(sprintf('episode/%u', $id));

	if(!($result = getSegments($data)))
	{
		throw new RuntimeException(sprintf('No segments found: %u', $id));
	}

	$gapless = getGapless($data);
	$youth_protection = getYouthProtection($data);

	return $result;
}

function getYouthProtection($data)
{
	if(!empty($data['has_active_youth_protection']))
	{
		return !empty($data['youth_protection_type']) ? (string) $data['youth_protection_type'] : 'TRUE';
	}

	return false;
}

function getGapless($data)
{
	if(isset($data['is_gapless']) && $data['is_gapless'])
	{
		return $data;
	}

	return false;
}

function getSegments($data, $return = [])
{
	if(isset($data['_embedded']['segments']) && is_array($data['_embedded']['segments']))
	{
		foreach($data['_embedded']['segments'] as $key => $value)
		{
			if(isset($value['id']))
			{
				$return[] = $value;
			}
			else if(isset($value['_embedded']['segments']))
			{
				getSegments($value['_embedded']['segments'], $return);
			}
		}
	}

	return $return;
}

function getEpisodes($id)
{
	$data = API(sprintf('profile/%u/episodes', $id));

	if(!($result = getItems($data)))
	{
		throw new RuntimeException(sprintf('No episodes found: %u', $id));
	}

	return $result;
}

function getItems($data, $return = [])
{
	if(isset($data['_embedded']['items']) && is_array($data['_embedded']['items']))
	{
		foreach($data['_embedded']['items'] as $key => $value)
		{
			if(isset($value['id']))
			{
				$return[] = $value;
			}
			else if(isset($value['_embedded']['items']))
			{
				getItems($value['_embedded']['items'], $return);
			}
		}
	}

	return $return;
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
error_reporting(-1); ini_set('display_errors', 1);

$glt     = false;
$error   = null;
$matches = null;
$episode = null;
$result  = null;
$url     = null;

try
{
	if(isset($_GET['url']))
	{
		throw new RuntimeException("Service API locked! Please use youtube-dl.");

		$url = (string) $_GET['url'];
		$url = str_replace('/topic/', '/profile/', $url);
		$url = str_replace('/history/', '/profile/', $url);

		if(is_numeric($url))
		{
			$episode = abs((int) $url);
		}
		else if(preg_match('#/profile/[^/]+/([0-9]+)/[^/]+/([0-9]+)/[^/]+/([0-9]+)#', $url, $matches))
		{
			$episode = (int) $matches[2];
		}
		else if(preg_match('#/profile/[^/]+/([0-9]+)/[^/]+/([0-9]+)#', $url, $matches))
		{
			$episode = (int) $matches[2];
		}
		else if(preg_match('#/profile/[^/]+/([0-9]+)#', $url, $matches))
		{
			$results = getEpisodes((int) $matches[1]);

			$result = [];
			foreach($results as $key => $value)
			{
				if(!empty($value['title']))
				{
					$title = $value['title'];

					if(!empty($value['teaser_title']) && strpos($value['title'], $value['teaser_title']) === false)
					{
						$title .= ' - ' . $value['teaser_title'];
					}

					if(!empty($value['date']))
					{
						$_ = strtotime($value['date']);
						$title .= sprintf(' (vom %s um %s Uhr)', date('d.m.Y', $_), date('H:i', $_));
					}
				}
				else
				{
					$title = $value['share_subject'];
				}

				$result[] = [
					'duration'    => isset($value['duration'])      ? $value['duration']                : null,
					'datetime'    => isset($value['date'])          ? strtotime($value['date'])         : null,
					'killdate'    => isset($value['killdate'])      ? strtotime($value['killdate'])     : null,
					'description' => isset($value['description'])   ? $value['description']             : null,
					'link'        => isset($value['id'])            ? sprintf('?url=%u', $value['id'])  : null,
					'title'       => $title,
					'selection'   => true,
				];
			}
		}
	}

	if($episode)
	{
		$result = [];
		$gapless = null;
		$youth_protection = null;

		$data = getEpisode($episode, $gapless, $youth_protection);

		if($gapless && count($data) > 1)
		{
			$progressive = [];
			if(isset($gapless['sources']['progressive_download']) && is_array($gapless['sources']['progressive_download']))
			{
				foreach($gapless['sources']['progressive_download'] as $_key => $_value)
				{
					if(isset($_value['quality_key'], $_value['src']))
					{
						$progressive[$_value['quality_key']] = $_value['src'];
					}
				}
			}

			$subtitles = [];
			if(isset($gapless['playlist']['gapless_video']['subtitles']) && is_array($gapless['playlist']['gapless_video']['subtitles']))
			{
				foreach($gapless['playlist']['gapless_video']['subtitles'] as $_key => $_value)
				{
					if(isset($_value['src'], $_value['type'], $_value['lang']) && $_value['lang'] === 'de-AT')
					{
						$subtitles[$_value['type']] = $_value['src'];
					}
				}
			}

			if(count($progressive) || count($subtitles))
			{
				if(!empty($gapless['title']))
				{
					$title = $gapless['title'];

					if(!empty($gapless['teaser_title']) && strpos($gapless['title'], $gapless['teaser_title']) === false)
					{
						$title .= ' - ' . $gapless['teaser_title'];
					}
				}
				else
				{
					$title = $gapless['share_subject'];
				}

				$glt = true;
				$result[] = [
					'duration'         => isset($gapless['duration'])      ? $gapless['duration']                : null,
					'datetime'         => isset($gapless['date'])          ? strtotime($gapless['date'])         : null,
					'killdate'         => isset($gapless['killdate'])      ? strtotime($gapless['killdate'])     : null,
					'description'      => isset($gapless['description'])   ? $gapless['description']             : null,
					'link'             => isset($gapless['share_body'])    ? $gapless['share_body']              : null,
					'youth_protection' => $youth_protection,
					'progressive'      => $progressive,
					'subtitles'        => $subtitles,
					'title'            => $title,
					'gapless'          => true,
				];
			}
		}

		foreach($data as $key => $value)
		{
			$progressive = [];
			if(isset($value['sources']['progressive_download']) && is_array($value['sources']['progressive_download']))
			{
				foreach($value['sources']['progressive_download'] as $_key => $_value)
				{
					if(isset($_value['quality_key'], $_value['src']))
					{
						$progressive[$_value['quality_key']] = $_value['src'];
					}
				}
			}

			$subtitles = [];
			if(isset($value['playlist']['subtitles']) && is_array($value['playlist']['subtitles']))
			{
				foreach($value['playlist']['subtitles'] as $_key => $_value)
				{
					if(isset($_value['src'], $_value['type'], $_value['lang']) && $_value['lang'] === 'de-AT')
					{
						$subtitles[$_value['type']] = $_value['src'];
					}
				}
			}

			if(!empty($value['title']))
			{
				$title = $value['title'];

				if(!empty($value['teaser_title']) && strpos($value['title'], $value['teaser_title']) === false)
				{
					$title .= sprintf(' - %s', $value['teaser_title']);
				}

				if(!$glt && !empty($value['_embedded']['profile']['title']) && strpos($value['title'], $value['_embedded']['profile']['title']) === false)
				{
					$title = sprintf('%s: %s', $value['_embedded']['profile']['title'], $title);
				}
			}
			else
			{
				$title = $value['share_subject'];
			}

			$result[] = [
				'duration'         => isset($value['duration'])      ? $value['duration']                : null,
				'datetime'         => isset($value['episode_date'])  ? strtotime($value['episode_date']) : null,
				'killdate'         => isset($value['killdate'])      ? strtotime($value['killdate'])     : null,
				'description'      => isset($value['description'])   ? $value['description']             : null,
				'link'             => isset($value['share_body'])    ? $value['share_body']              : null,
				'youth_protection' => $youth_protection,
				'progressive'      => $progressive,
				'subtitles'        => $subtitles,
				'title'            => $title,
			];
		}
	}
}
catch(Exception $e)
{
	$error = sprintf('%s: [%d] %s', get_class($e), $e->getCode(), $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="de">
	<head>
		<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
		<title>ORF TVThek</title>
		<style>

			* {
				font-family: sans;
				font-size: 13px;
				padding: 0px;
				margin: 0px;
			}

			#header {
				background: #ccc;
				border-bottom: 1px solid black;
			}

			#header, #content, #error, footer {
				padding: 10px;
			}

			.url {
				width: 50%;
			}

			.submit {
				width: 25%;
			}

			ul {
				list-style-type: none;
			}

			li {
				position: relative;
				border-left: 5px solid yellow;
				background: #eee;
				padding: 5px;
				margin: 15px;
			}

			li:hover {
				background: #ccc;
				border-left-color: orange;
			}

			li.info {
				border-left-color: green;
			}

			li.info:hover {
				border-left-color: lightgreen;
			}

			.downloads {
				position: absolute;
				bottom: 5px;
				right: 5px;
			}

			.killdate {
				cursor: help;
			}

			.youth_protection {
				font-weight: bold;
				color: red;
			}

			a {
				font-weight: bold;
				text-decoration: none;
			}

			a.headline {
				font-weight: normal;
			}

			a.downloads, .killdate {
				color: #555;
				border-bottom: 1px dotted;
			}

			a:hover {
				text-decoration: underline;
			}

			.description {
				font-style: italic;
				color: #888;
			}

			p {
				margin-top: 10px;
			}

			footer {
				background: #ccc;
				border-top: 1px solid black;
				font-stretch: ultra-condensed;
				text-align: right;
			}

		</style>
	</head>
	<body>
		<div id="header">
			<form action="<?php echo htmlentities($_SERVER['PHP_SELF'], null, 'UTF-8') ?>" method="get">
				TVThek URL:
				<input type="text" name="url" class="url" value="<?php echo htmlentities($url, null, 'UTF-8'); ?>" />
				<input type="submit" class="submit" value="Absenden" />
			</form>
		</div>

<?php

if($error !== null)
{

?>
		<div id="error"><?php echo htmlentities($error, null, 'UTF-8') ?></div>

<?php

}
else if($result !== null)
{

?>
		<div id="content">
			<ul>
<?php

$files = [];
foreach($result as $item)
{

?>
				<li <?php echo ((isset($item['gapless']) && $item['gapless']) ? 'class="info"' : ''); ?>>
					<a href="<?php echo htmlentities($item['link'], null, 'UTF-8') ?>" class="headline"><?php echo htmlentities($item['title'], null, 'UTF-8') ?></a>
<?php

	if($item['description'])
	{

?>
					<p class="description"><?php echo nl2br(htmlentities($item['description'], null, 'UTF-8')); ?></p>
<?php

	}

	if(!isset($item['selection']) || !$item['selection'])
	{
		$progressive = $subtitles = $downloads = [];

		if(count($item['progressive']))
		{
			foreach($item['progressive'] as $key => $value)
			{
				$progressive[] = sprintf('<a href="%2$s">%1$s</a>', htmlentities($key, null, 'UTF-8'), htmlentities($value, null, 'UTF-8'));
			}

			$downloads[] = $_ = addslashes(htmlentities(array_pop($item['progressive']), null, 'UTF-8'));

			if(!isset($item['gapless']) || !$item['gapless'])
			{

				$files[] = $_;
			}
			unset($_);
		}

		foreach($item['subtitles'] as $key => $value)
		{
			$subtitles[] = sprintf('<a href="%2$s">%1$s</a>', htmlentities($key, null, 'UTF-8'), htmlentities($value, null, 'UTF-8'));

			if(!isset($item['gapless']) || !$item['gapless'])
			{
				$downloads[] = addslashes(htmlentities($value, null, 'UTF-8'));
			}
		}

?>
					<br /><br />
					<p>Dauer: <?php echo $item['duration'] ? sprintf('<b>%s</b>', gmdate('H:i:s', $item['duration'] / 1000)) : '-'; ?></p>
					<?php if(!$glt || (isset($item['gapless']) && $item['gapless'])) { ?><p>Datum: <?php echo $item['datetime'] ? sprintf('%s', date('d.m.Y, H:i', $item['datetime'])) : '-'; ?></p><?php } ?>
					<?php echo $item['youth_protection'] ? sprintf('<p>Jugendschutz: <span class="youth_protection">%s</span></p>', htmlentities($item['youth_protection'])) : ''; ?>
					<p>Untertitel: <?php echo implode(' &bull; ', $subtitles); ?></p>
					<p>Videodatei: <?php echo  implode(' &bull; ', $progressive); ?></p>
					<?php echo $item['killdate'] ? sprintf('<p><span class="killdate" title="Verfügbar bis %s">Noch <b>%d</b> Stunden verfügbar</span></p>', date('d.m.Y, H:i', $item['killdate']), ($item['killdate'] - time()) / 3600) : ''; ?>
					<a href="javascript:void prompt('Alle Untertitel und die beste Videodatei f&uuml;r Copy &amp; Paste:', &quot;<?php echo implode('\n', $downloads) . '\n'; ?>&quot;);" class="downloads">Downloadlinks</a>
<?php

	}

?>
				</li>
<?php

}

if(count($files) > 1)
{

?>
				<li class="info">
					<b>Alle Videodateien dieser Episode in bester Qualität:</b><br /><br />
					<textarea id="files" cols="10" rows="3" style="width: 100%; height: 100%; cursor: pointer;" readonly="readonly" onclick="this.focus(); this.select()"><?php echo stripslashes(implode("\n", $files)); ?></textarea>
					<script> var i = document.getElementById('files'); if(i.scrollHeight > i.clientHeight) { i.style.height = i.scrollHeight + 'px'; } </script> <!-- https://stackoverflow.com/a/17259991/3747688 -->
					<br /><br />Direkter Downloads mittels Bash und Wget:<br /><br />
					<textarea id="console" cols="10" rows="3" style="width: 100%; height: 100%; cursor: pointer;" readonly="readonly" onclick="this.focus(); this.select()">tvthek=( "<?php echo implode('" "', $files); ?>" ); for key in ${!tvthek[@]}; do wget -q --show-progress -O "$(printf %02d "$(( $key + 1 ))").mp4" "${tvthek[$key]}" || break; done</textarea>
					<script> var i = document.getElementById('console'); if(i.scrollHeight > i.clientHeight) { i.style.height = i.scrollHeight + 'px'; } </script> <!-- https://stackoverflow.com/a/17259991/3747688 -->
					<?php if($glt) { ?><br /><br /><i>Als bessere Alternative gibt es das sogenannte Gapless-Video, siehe erster (grüner) Eintrag.</i><?php } ?>
				</li>
<?php

}

?>
			</ul>
		</div>

<?php

}

?>
		<footer>
			Version vom <?php echo date('d.m.Y \u\m H:i:s', filemtime(__file__)); ?>

			&bull;
			<a href="?source=true">Quellcode anzeigen</a>
			&bull;
			<a href="javascript:(function(){ window.open('<?php echo addslashes(htmlentities(sprintf('https://%s%s', $_SERVER['HTTP_HOST'], $_SERVER['PHP_SELF']), null, 'UTF-8')); ?>?url='+encodeURI(location.href)); })();" title="Dieser Link kann direkt in die Lesezeichenleiste gezogen werden!">Interaktives Lesezeichen</a>
		</footer>
	</body>
</html>
