<?php

if(isset($_GET['source']))
{
	header('Content-Type: text/html; charset=UTF-8');
	highlight_file(__file__);
	exit;
}

setlocale(LC_CTYPE, 'en_US.UTF-8');

###############################################################################
# API-USERNAME & API-PASSWORD: https://github.com/s0faking/plugin.video.orfon #
#   /blob/8ae2c0d6e8e346bf514b9e21b1499fcb1d87b4f7/resources/lib/OrfOn.py#L18 #
###############################################################################
define('API_USER',    '<API-USERNAME>');
define('API_PASS',    '<API-PASSWORD>');

define('API_VERSION', 'v4.3');
define('API_HOST',    'api-tvthek.orf.at');
define('API_PATH',    sprintf('/api/%s/', API_VERSION));
define('API_BASE',    sprintf('https://%s:%s@%s%s', API_USER, API_PASS, API_HOST, API_PATH));
define('API_CACHE',   300);
define('API_LIMIT',   20);

$scheduleHideAD = false;
$scheduleHideOEGS = false;
$scheduleHideGenres = [];

$curl = curl_init();
curl_setopt($curl, CURLOPT_TIMEOUT, 30);
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
//curl_setopt($curl, CURLOPT_USERAGENT, '...');
//curl_setopt($curl, CURLOPT_PROXY, '...');

function API($r)
{
	global $curl;

	$c = sprintf('%s/tvthek-%s_%s.json', sys_get_temp_dir(), rawurlencode(API_VERSION), rawurlencode(base64_encode($r)));

	if(!API_CACHE || !file_exists($c) || filemtime($c) < (time() - API_CACHE) || !filesize($c) || ($data = file_get_contents($c)) === false)
	{
		$r = sprintf('%s%s', API_BASE, $r);
		curl_setopt($curl, CURLOPT_URL, $r);

		if(($data = curl_exec($curl)) === false)
		{
			throw new RuntimeException(sprintf('API request failed: %s', curl_error($curl)));
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

function getSchedule($date = null)
{
	global $scheduleHideAD;
	global $scheduleHideOEGS;
	global $scheduleHideGenres;

	$date = !is_null($date) ? $date : date('Y-m-d');

	if(!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $date))
	{
		throw new RuntimeException(sprintf('Invalid date: %s', $date));
	}

	$result = [];
	$data = API(sprintf('schedule/%s?limit=%u', $date, 1000));

	foreach($data as $value)
	{
		if($scheduleHideOEGS && !empty($value['is_oegs']))
		{
			continue;
		}
		else if($scheduleHideAD && (isset($value['title']) && substr($value['title'], 0, 5) === 'AD | '))
		{
			continue;
		}
		else if($scheduleHideGenres && !empty($value['genre_title']) && in_array($value['genre_title'], $scheduleHideGenres))
		{
			continue;
		}

		$result[] =
		[
			'title'       => isset($value['title'])            ? $value['title']                         : null,
			'genre'       => isset($value['genre_title'])      ? $value['genre_title']                   : null,
			'description' => isset($value['description'])      ? $value['description']                   : null,
			'duration'    => isset($value['duration_seconds']) ? $value['duration_seconds']              : null,
			'datetime'    => isset($value['date'])             ? strtotime($value['date'])               : null,
			'killdate'    => isset($value['killdate'])         ? strtotime($value['killdate'])           : null,
			'link'        => isset($value['id'])               ? sprintf('?url=/video/%d', $value['id']) : null,
			'selection'   => true,
			'withtime'    => true,
		];
	}

	$i = -1;
	foreach
	(
		[
			'previous' => '← Vorheriger Tag',
			'next'     => '→ Nächster Tag',
		]
		as $key => $value
	)
	{
		$day = strtotime(sprintf('%sT12:00:00+0000 %dday', $date, $i));

		$_ =
		[
			'datetime'    => $day,
			'description' => null,
			'title'       => sprintf('%s (%s)', $value, gmdate('d.m.Y', $day)),
			'link'        => sprintf('?url=/verpasst/%s', gmdate('Y-m-d', $day)),
			'multi'       => true,
			'selection'   => true,
		];

		if($i < 0)
		{
			array_unshift($result, $_);
		}
		else
		{
			$result[] = $_;
		}

		$i *= -1;
	}

	return $result;
}

function getEpisode($id, &$gapless = null, &$youth_protection = null, &$data = null)
{
	$data = API(sprintf('episode/%u', $id));
	$result = [];

	if(!($_ = getSegments($data)))
	{
		throw new RuntimeException(sprintf('No segments found: %u', $id));
	}

	$result = array_merge($result, $_);
	$youth_protection = getYouthProtection($data);
	$gapless = getGapless($data);

	return $result;
}

function getYouthProtection($data)
{
	if(!empty($data['is_drm_protected']))
	{
		return
		[
			'active' => true,
			'type'   => 'DRM PROTECTED',
		];
	}

	if(!empty($data['has_youth_protection']))
	{
		return
		[
			'active' => !empty($data['has_active_youth_protection']),
			'type'   => !empty($data['youth_protection_type']) ? (string) $data['youth_protection_type'] : 'TRUE',
		];
	}

	return null;
}

function getGapless($data)
{
	if(!empty($data['id']) &&!empty($data['is_gapless']) && isset($data['_embedded']['segments']) && is_array($data['_embedded']['segments']) && count($data['_embedded']['segments']) > 1)
	{
		$result = API(sprintf('episode/%d/progressive-download', $data['id']));

		if(isset($result['progressive_download']) && is_array($result['progressive_download']))
		{
			$gapless =
			[
				'progressive' => [],
				'subtitles'   => [],
			];

			foreach($result['progressive_download'] as $download)
			{
				if(isset($download['quality_key'], $download['src']))
				{
					$gapless['progressive'][$download['quality_key']] = $download['src'];
				}
			}

			if(!empty($data['has_subtitle']) && isset($data['_embedded']['subtitle']['_embedded']) && is_array($data['_embedded']['subtitle']['_embedded']))
			{
				foreach($data['_embedded']['subtitle']['_embedded'] as $key => $value)
				{
					if(substr($key, -5) === '_file' && isset($value['public_urls']['reference']['url']))
					{
						$gapless['subtitles'][substr($key, 0, -5)] = $value['public_urls']['reference']['url'];
					}
				}
			}

			return $gapless;
		}
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

function getEpisodes($id, $page = 1, $limit = API_LIMIT)
{
	$data = API(sprintf('profile/%u/episodes?page=' . $page . '&limit=' . $limit, $id));
	$result = [];

	if(!empty($data['pages']) && $data['pages'] > 1)
	{
		foreach
		(
			[
				'previous' => '← Vorherige Seite',
				'next'     => '→ Nächste Seite',
			]
			as $key => $value
		)
		{
			if(!empty($data['_links'][$key]['href']) && ($_ = parse_url($data['_links'][$key]['href'], PHP_URL_QUERY)))
			{
				$args = null;
				parse_str($_, $args);

				if(!empty($args['page']))
				{
					$result[] =
					[
						'datetime'    => time(),
						'description' => sprintf('Es gibt viele Episoden, aufgeteilt auf insgesamt %d Seiten.', $data['pages']),
						'link'        => sprintf('?url=/sendereihe/%u&page=%d&limit=%d', $id, $args['page'], $limit),
						'title'       => sprintf('%s (%d)', $value, $args['page']),
						'multi'       => true,
					];
				}
			}
		}
	}

	if(!($_ = getItems($data)))
	{
		throw new RuntimeException(sprintf('No episodes found: %u', $id));
	}

	return array_merge($result, $_);
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

function htmlJSON($value, $options = 0, $depth = 512)
{
	$json = json_encode($value, $options | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS, $depth);

	return is_string($json) ? $json : 'null';
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
$all     = null;

try
{
	if(isset($_GET['url']))
	{
		$url = (string) $_GET['url'];
		$url = str_replace('/topic/', '/profile/', $url);
		$url = str_replace('/history/', '/profile/', $url);

		if(is_numeric($url))
		{
			$episode = abs((int) $url);
		}
		else if(preg_match('#/video/([0-9]+)(?:/.+)?#', $url, $matches) || preg_match('#/profile/[^/]+/(?:[0-9]+)/[^/]+/([0-9]+)(?:/[^/]+/([0-9]+))?#', $url, $matches))
		{
			$episode = (int) $matches[1];
		}
		else if(preg_match('#/sendereihe/([0-9]+)(/.+)?#', $url, $matches) || preg_match('#/profile/[^/]+/([0-9]+)#', $url, $matches))
		{
			$page = isset($_GET['page']) ? abs((int) $_GET['page']) : null;
			$limit = isset($_GET['limit']) ? abs((int) $_GET['limit']) : null;
			$results = getEpisodes
			(
				(int) $matches[1],
				$page ? $page : 1,
				$limit ? $limit : API_LIMIT
			);

			$IDs = [];
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
				}
				else
				{
					$title = $value['share_subject'];
				}

				if(isset($value['id']))
				{
					$IDs[] = $value['id'];
				}

				if(!empty($value['multi']))
				{
					$link = isset($value['link']) ? $value['link'] : null;
					$multi = true;
				}
				else
				{
					$link = isset($value['id']) ? sprintf('?url=%u', $value['id']) : null;
					$multi = false;
				}

				$result[] =
				[
					'genre'       => isset($value['genre_title'])      ? $value['genre_title']         : null,
					'description' => isset($value['description'])      ? $value['description']         : null,
					'duration'    => isset($value['duration_seconds']) ? $value['duration_seconds']    : null,
					'datetime'    => isset($value['date'])             ? strtotime($value['date'])     : null,
					'killdate'    => isset($value['killdate'])         ? strtotime($value['killdate']) : null,
					'link'        => $link,
					'title'       => $title,
					'multi'       => $multi,
					'selection'   => true,
					'withtime'    => true,
				];
			}

			$all = [];
			foreach($IDs as $id)
			{
				$gapless = null;
				$youth_protection = null;
				$fulldata = null;

				$data = getEpisode($id, $gapless, $youth_protection, $fulldata);

				if(empty($youth_protection['active']))
				{
					if(!empty($fulldata['title']))
					{
						$title = $fulldata['title'];

						if(!empty($fulldata['teaser_title']) && strpos($fulldata['title'], $fulldata['teaser_title']) === false)
						{
							$title .= ' - ' . $fulldata['teaser_title'];
						}
					}
					else
					{
						$title = $fulldata['share_subject'];
					}

					if($gapless)
					{
						if(count($gapless['progressive']))
						{
							$all[$id] =
							[
								'progressive' => array_pop($gapless['progressive']),
								'datetime'    => isset($fulldata['date']) ? strtotime($fulldata['date']) : null,
								'title'       => $title,
							];

							continue;
						}
					}
					else if(count($data) === 1)
					{
						$value = array_shift($data);

						if(isset($value['_embedded']['playlist']['sources']) && is_array($value['_embedded']['playlist']['sources']))
						{
							$progressive = [];
							foreach($value['_embedded']['playlist']['sources'] as $_key => $_value)
							{
								if(isset($_value['quality'], $_value['src'], $_value['delivery']) && $_value['delivery'] === 'progressive')
								{
									$progressive[$_value['quality']] = $_value['src'];
								}
							}

							if($progressive)
							{
								$all[$id] =
								[
									'progressive' => array_pop($progressive),
									'datetime'    => isset($fulldata['date']) ? strtotime($fulldata['date']) : null,
									'title'       => $title,
								];

								continue;
							}
						}
					}
				}

				$all = false;
				break;
			}
		}
		else if(preg_match('#/verpasst/([0-9]{4}-[0-9]{2}-[0-9]{2})#', $url, $matches))
		{
			$result = getSchedule($matches[1]);
		}
	}

	if($episode)
	{
		$result = [];
		$gapless = null;
		$youth_protection = null;
		$fulldata = null;

		$data = getEpisode($episode, $gapless, $youth_protection, $fulldata);

		if(!empty($fulldata['_embedded']['profile']['id']))
		{
			$result[] =
			[
				'datetime'    => time(),
				'description' => 'Zeigt alle Episoden dieser Sendereihe an.',
				'link'        => sprintf('?url=/sendereihe/%u', $fulldata['_embedded']['profile']['id']),
				'title'       =>'↑ EPISODENLISTE',
				'parent'      => true,
				'selection'   => true,
			];
		}

		if($gapless && (count($gapless['progressive']) || count($gapless['subtitles'])))
		{
			if(!empty($fulldata['title']))
			{
				$title = $fulldata['title'];

				if(!empty($fulldata['teaser_title']) && strpos($fulldata['title'], $fulldata['teaser_title']) === false)
				{
					$title .= ' - ' . $fulldata['teaser_title'];
				}
			}
			else
			{
				$title = $fulldata['share_subject'];
			}

			$glt = true;
			$result[] =
			[
				'genre'            => isset($fulldata['genre_title'])      ? $fulldata['genre_title']         : null,
				'description'      => isset($fulldata['description'])      ? $fulldata['description']         : null,
				'duration'         => isset($fulldata['duration_seconds']) ? $fulldata['duration_seconds']    : null,
				'datetime'         => isset($fulldata['date'])             ? strtotime($fulldata['date'])     : null,
				'killdate'         => isset($fulldata['killdate'])         ? strtotime($fulldata['killdate']) : null,
				'link'             => isset($fulldata['share_body'])       ? $fulldata['share_body']          : null,
				'youth_protection' => $youth_protection,
				'progressive'      => $gapless['progressive'],
				'subtitles'        => $gapless['subtitles'],
				'title'            => $title,
				'gapless'          => true,
			];
		}

		foreach($data as $key => $value)
		{
			$progressive = [];
			if(isset($value['_embedded']['playlist']['sources']) && is_array($value['_embedded']['playlist']['sources']))
			{
				foreach($value['_embedded']['playlist']['sources'] as $_key => $_value)
				{
					if(isset($_value['quality'], $_value['src'], $_value['delivery']) && $_value['delivery'] === 'progressive')
					{
						$progressive[$_value['quality']] = $_value['src'];
					}
				}
			}

			$subtitles = [];
			if(isset($value['_embedded']['playlist']['subtitles']) && is_array($value['_embedded']['playlist']['subtitles']))
			{
				foreach($value['_embedded']['playlist']['subtitles'] as $_key => $_value)
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

			$result[] =
			[
				'genre'            => isset($value['genre_title'])      ? $value['genre_title']             : null,
				'description'      => !empty($value['description'])     ? $value['description']             : null,
				'duration'         => isset($value['duration_seconds']) ? $value['duration_seconds']        : null,
				'datetime'         => isset($value['episode_date'])     ? strtotime($value['episode_date']) : null,
				'killdate'         => isset($value['killdate'])         ? strtotime($value['killdate'])     : null,
				'link'             => isset($value['share_body'])       ? $value['share_body']              : null,
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
	error_log('Caught ' . $e);

	$error = sprintf('%s: [%d] %s', get_class($e), $e->getCode(), $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="de">
	<head>
		<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
		<title>ORF TVthek API <?php echo htmlentities(API_VERSION, ENT_QUOTES, 'UTF-8'); ?></title>
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

			li.multi {
				border-left-color: red;
			}

			li.multi:hover {
				border-left-color: darkred;
			}

			li.parent {
				border-left-color: lightblue;
			}

			li.parent:hover {
				border-left-color: darkblue;
			}

			.downloads {
				position: absolute;
				bottom: 5px;
				right: 5px;
			}

			.killdate {
				cursor: help;
			}

			.youth_protection_active {
				font-weight: bold;
				color: red;
			}

			.youth_protection_inactive {
				font-weight: bold;
				color: green;
			}

			a {
				font-weight: bold;
				text-decoration: none;
			}

			a.headline {
				font-weight: normal;
			}

			.headline > .date {
				font-weight: bold;
			}

			.downloads, .downloads a, .killdate, .na {
				color: #555;
			}

			.downloads a, .killdate {
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

			#txt
			{
				position: absolute;
				overflow: hidden;
				opacity: .01;
				height: 0px;
			}

		</style>
		<script>

			function copyLinks(content)
			{
				var txt = document.getElementById('txt');
				txt.value = content;
				txt.select();
				txt.setSelectionRange(0, content.length);

				if(document.execCommand('copy'))
				{
					alert('Die Downloadlinks der besten Videodatei und aller Untertitel wurden in die Zwischenablage kopiert!');
				}
			}

		</script>
	</head>
	<body>
		<div id="header">
			<form action="<?php echo htmlentities($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8'); ?>" method="get">
				on.orf.at URL:
				<input type="text" name="url" class="url" value="<?php echo htmlentities($url, ENT_QUOTES, 'UTF-8'); ?>" />
				<input type="submit" class="submit" value="Absenden" />
			</form>
		</div>

<?php

if($error !== null)
{

?>
		<div id="error"><?php echo htmlentities($error, ENT_QUOTES, 'UTF-8') ?></div>

<?php

}
else if($result !== null)
{

?>
		<div id="content">
			<ul>
<?php

$files = [];
$lastdescription = null;
foreach($result as $item)
{
	if(!empty($item['parent']))
	{
		$li = 'class="parent"';
	}
	else if(!empty($item['multi']))
	{
		$li = 'class="multi"';
	}
	else if(isset($item['gapless']) && $item['gapless'])
	{
		$li = 'class="info"';
	}
	else
	{
		$li = '';
	}

?>
				<li <?php echo $li; ?>>
					<span class="headline">
<?php

	if(!empty($item['withtime']) && !empty($item['datetime']))
	{
		echo "\t\t\t\t\t\t" . sprintf('<span class="date">[<span>%s</span>]</span>', htmlentities(date('d.m.Y, H:i', $item['datetime']), ENT_QUOTES, 'UTF-8')) . "\n";
	}

	if(isset($item['genre']) && $item['genre'] !== '' && (!$glt || !empty($item['gapless'])))
	{
		echo "\t\t\t\t\t\t" . sprintf('<span class="genre"><span>%s</span>:</span>', htmlentities($item['genre'], ENT_QUOTES, 'UTF-8')) . "\n";
	}

?>
						<a href="<?php echo htmlentities($item['link'], ENT_QUOTES, 'UTF-8') ?>" class="headline"><?php echo htmlentities($item['title'], ENT_QUOTES, 'UTF-8') ?></a>
					</span>
<?php

	if($item['description'] && $item['description'] !== $lastdescription)
	{
		$lastdescription = $item['description'];

?>
					<p class="description"><?php echo nl2br(htmlentities($item['description'], ENT_QUOTES, 'UTF-8')); ?></p>
<?php

	}

	if(empty($item['selection']))
	{
		$progressive = $subtitles = $downloads = $shell = [];

		if(empty($item['youth_protection']['active']) && count($item['progressive']))
		{
			foreach($item['progressive'] as $key => $value)
			{
				$progressive[] = sprintf('<a href="%2$s">%1$s</a>', htmlentities($key, ENT_QUOTES, 'UTF-8'), htmlentities($value, ENT_QUOTES, 'UTF-8'));
			}

			$_ = $downloads[] = $shell[] = array_pop($item['progressive']);

			if(empty($item['gapless']))
			{
				$files[] = $_;
			}
			unset($_);
		}

		if(isset($item['subtitles']))
		{
			foreach($item['subtitles'] as $key => $value)
			{
				$subtitles[] = sprintf('<a href="%2$s">%1$s</a>', htmlentities($key, ENT_QUOTES, 'UTF-8'), htmlentities($value, ENT_QUOTES, 'UTF-8'));

				if(empty($item['gapless']))
				{
					$downloads[] = $shell[] = $value;
				}
			}
		}

		$killdate = '';
		if(!empty($item['killdate']) && (!$glt || !empty($item['gapless'])))
		{
			$seconds = $item['killdate'] - time();

			if($seconds >= 86400)
			{
				$timeleft = sprintf('<b>%d</b> Tag%s', $seconds / 86400, ($seconds >= 172800) ? 'e' : '');
			}
			else
			{
				$timeleft = sprintf('<b>%d</b> Stunde%s', $seconds / 3600, ($seconds >= 7200 || $seconds < 3600) ? 'n' : '');
			}

			$killdate = sprintf('<p><span class="killdate" title="Verfügbar bis %s">Noch %s verfügbar</span></p>', date('d.m.Y, H:i', $item['killdate']), $timeleft);
		}


?>
					<br /><br />
					<p>Dauer: <?php echo !empty($item['duration']) ? sprintf('<b>%s</b>', gmdate('H:i:s', $item['duration'])) : '-'; ?></p>
					<?php if(!$glt || !empty($item['gapless'])) { ?><p>Datum: <?php echo !empty($item['datetime']) ? sprintf('<a href="?url=/verpasst/%s">%s</a>', date('Y-m-d', $item['datetime']), date('d.m.Y, H:i', $item['datetime'])) : '-'; ?></p><?php } else { echo "\n"; } ?>
					<?php echo !empty($item['youth_protection']) ? sprintf('<p>Jugendschutz: <span class="youth_protection_%sactive">%s</span></p>', (empty($item['youth_protection']['active']) ? 'in' : ''), htmlentities($item['youth_protection']['type'])) : "\n"; ?>
					<p>Untertitel: <?php echo $subtitles ? implode(' &bull; ', $subtitles) : '<span class="na">Keine Untertitel vorhanden.</span>'; ?></p>
					<p>Videodatei: <?php echo $progressive ? implode(' &bull; ', $progressive) : '<span class="na">Keine Videodateien verfügbar.</span>'; ?></p>
					<?php echo $killdate; ?>

<?php

		if(empty($item['youth_protection']['active']))
		{

?>
					<div class="downloads">
						<a href="javascript:void copyLinks(<?php echo htmlentities(htmlJSON(implode("\n", $downloads) . "\n"), ENT_QUOTES, 'UTF-8'); ?>);">Downloadlinks</a>
						(<a href="javascript:void prompt(null, <?php echo htmlentities(htmlJSON(sprintf("wget --no-verbose --show-progress --user-agent=%s %s", escapeshellarg(''), implode(' ', array_map('escapeshellarg', $shell)))), ENT_QUOTES, 'UTF-8'); ?>);">Shell</a>)
					</div>
<?php

		}
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
					<textarea id="files" cols="10" rows="3" style="width: 100%; height: 100%; cursor: pointer;" readonly="readonly" onclick="this.focus(); this.select()"><?php echo htmlentities(implode("\n", $files), ENT_QUOTES, 'UTF-8'); ?></textarea>
					<script> var i = document.getElementById('files'); if(i.scrollHeight > i.clientHeight) { i.style.height = i.scrollHeight + 'px'; } </script> <!-- https://stackoverflow.com/a/17259991/3747688 -->
					<br /><br />Direkter Downloads mittels Bash und Wget:<br /><br />
					<textarea id="console" cols="10" rows="3" style="width: 100%; height: 100%; cursor: pointer;" readonly="readonly" onclick="this.focus(); this.select()">tvthek=( <?php echo htmlentities(implode(' ', array_map('escapeshellarg', $files)), ENT_QUOTES, 'UTF-8'); ?> ); for key in ${!tvthek[@]}; do wget --no-verbose --show-progress -O "$(printf %02d "$(( key + 1 ))").mp4" "${tvthek[$key]}" || break; done</textarea>
					<script> var i = document.getElementById('console'); if(i.scrollHeight > i.clientHeight) { i.style.height = i.scrollHeight + 'px'; } </script> <!-- https://stackoverflow.com/a/17259991/3747688 -->
					<?php if($glt) { ?><br /><br /><i>Als bessere Alternative gibt es das sogenannte Gapless-Video, siehe erster (grüner) Eintrag.</i><?php } ?>
				</li>
<?php

}
else if($all)
{
	$files = [];
	$commands = ['failed=0'];
	foreach($all as $id => $data)
	{
		$datetime = !empty($data['datetime']) ? date('Ymd-His - ', $data['datetime']) : '';
		$title = sprintf('%s%s.tvthek-%d.mp4', $datetime, preg_replace('/[_]{2,}/', '_', preg_replace('/[^\w\d\p{L} !#$%&\\\'()+,-.;=@\\[\\]^_`{}~]/u', '_', $data['title'])), $id);
		$commands[] = sprintf('wget --no-verbose --show-progress --no-clobber --output-document=%s %s || failed=1', escapeshellarg($title), escapeshellarg($data['progressive']));
		$files[] = $data['progressive'];
	}

	$commands[] = '[ "$failed" -ne 0 ] && echo \'!!! FAILED !!!\' >&2';

?>
				<li class="info">
					<b>Alle Episoden dieser Seite in bester Qualität:</b><br /><br />
					<textarea id="files" cols="10" rows="3" style="width: 100%; height: 100%; cursor: pointer;" readonly="readonly" onclick="this.focus(); this.select()"><?php echo htmlentities(implode("\n", $files), ENT_QUOTES, 'UTF-8'); ?></textarea>
					<script> var i = document.getElementById('files'); if(i.scrollHeight > i.clientHeight) { i.style.height = i.scrollHeight + 'px'; } </script> <!-- https://stackoverflow.com/a/17259991/3747688 -->
					<br /><br />Direkter Downloads mittels Bash und Wget:<br /><br />
					<textarea id="console" cols="10" rows="3" style="width: 100%; height: 100%; cursor: pointer;" readonly="readonly" onclick="this.focus(); this.select()"><?php echo htmlentities(implode("; \\\n", $commands), ENT_QUOTES, 'UTF-8'); ?></textarea>
					<script> var i = document.getElementById('console'); if(i.scrollHeight > i.clientHeight) { i.style.height = i.scrollHeight + 'px'; } </script> <!-- https://stackoverflow.com/a/17259991/3747688 -->
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
			<textarea id="txt"></textarea>
			Letzte Änderung des Programmcodes am <?php echo date('d.m.Y \u\m H:i:s', filemtime(__file__)); ?>

			&bull;
			<a href="?source=true">Quellcode anzeigen</a>
			&bull;
			<a href="javascript:(function(){ window.open('<?php echo addslashes(htmlentities(sprintf('https://%s%s', $_SERVER['HTTP_HOST'], $_SERVER['PHP_SELF']), ENT_QUOTES, 'UTF-8')); ?>?url='+encodeURI(location.href)); })();" title="Dieser Link kann direkt in die Lesezeichenleiste gezogen werden!">Interaktives Lesezeichen</a>
		</footer>
	</body>
</html>
