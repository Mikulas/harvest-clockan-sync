<?php

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/console.php';

// Harvest
$username = '';
$password = '';
$harvest_project_id = 0;
$start_from_day = 1;

// Clockan
$api_key = '';
$person_id = 0;
$project_id = 0;

// Config
$storage = new Nette\Caching\Storages\FileStorage('temp');
$cache = new Nette\Caching\Cache($storage);

$entries = [];
for ($doy = $start_from_day; $doy <= date('z'); ++$doy) {
	$harvest = $cache->call('getHarvestForDay', $doy, 2013, $username, $password, $harvest_project_id);
	$entries = array_merge($entries, $harvest);

	clearLine();
	echo "$doy/" . date('z');
}
clearLine();

if (!isset($cache['last_imported_date']) && !isset($cache['last_synced_date'])) {
	printEntries($entries);

	$reply = NULL;
	while (!$reply) {
		echo "\n";
		echo "It seems you have never imported your Harvest to Clockan.\n";
		echo "From what date would you like to import?\n";
		echo "(you will be shown the payload before the sync)\n";
		echo "[2013-06-17, 2 weeks ago, last month, ...]: ";
		$reply = strToLower(trim(fgets(STDIN)));
		$time = strToTime($reply);
		$cache['last_imported_date'] = $time;
	}
} else {
	$time = isset($cache['last_synced_date'])
		? $cache['last_synced_date'] + 24 * 3600
		: $cache['last_imported_date'];
	echo "Filtering only entries since last sync.\n";
}

$filtered = $entries;

foreach ($filtered as $i => $entry) {
	if (strToTime($entry->date) < $time) {
		unset($filtered[$i]);
	}
}

printEntries($filtered);

while (TRUE) {
	if (!count($filtered)) {
		echo Colorize::red("You have no entries since " . date('Y-m-d', $time) . "\n");
		$reply = 'n';

	} else {
		echo "\n";
		echo "These are entries from " . date('Y-m-d', $time) . "\n";
		echo "Should we sync these to Clockan? [Yn]: ";
		$reply = strToLower(trim(fgets(STDIN)));
		$time = strToTime($reply);
	}

	if ($reply === 'y') {
		break;

	} else if ($reply === 'n') {
		$reply = NULL;
		while (!$reply) {
			echo "\n";
			echo "From what date would you like to import?\n";
			if (isset($cache['last_synced_date'])) {
				$last = date('Y-m-d', $cache['last_synced_date']);
				echo "Your last import was from $last\n";
			}
			echo "(you will be shown the payload before the sync)\n";
			echo "[new, 2013-06-17, 2 weeks ago, last month, ...]: ";
			$reply = strToLower(trim(fgets(STDIN)));
			if ($reply === 'new') {
				if (!isset($cache['last_synced_date'])) {
					echo "Cannot use 'new', never synced.\n";
				}
				// last day synced plus one day
				$time = $cache['last_synced_date'] + (3600 * 24);
			} else {
				$time = strToTime($reply);
			}
			$cache['last_imported_date'] = $time;
			$cache->remove('last_synced_date');
		}
	} else {
		continue;
	}

	$filtered = $entries;
	foreach ($filtered as $i => $entry) {
		if (strToTime($entry->date) < $time) {
			unset($filtered[$i]);
		}
	}

	printEntries($filtered);
}

$i = 1;
$count = count($filtered);
foreach ($filtered as $entry) {
	createClockanEntry($api_key, $project_id, $person_id, $entry->date, $entry->hours, $entry->notes);

	clearLine();
	echo "$i/$count";
	$i++;
}
clearLine();

$last = end($filtered);
$cache['last_synced_date'] = strToTime($last->date);

echo Colorize::green("\ndone\n");
die;


/*
// List available projects

$request = createClockanRequest('projects.xml', $api_key);
try {
    $response = $request->get();
	$xml = new SimpleXMLElement($response->getResponse());
	dump($xml);

} catch (Kdyby\Curl\CurlException $e) {
    echo $e->getMessage() . "\n";
}
//*/

/*
// List recent time entries in Simproperty

$request = createClockanRequest("projects/{$project_id}/time_entries.xml", $api_key);
try {
	$response = $request->get();
	$xml = new SimpleXMLElement($response->getResponse());
	dump($xml);

} catch (Kdyby\Curl\CurlException $e) {
	echo $e->getMessage() . "\n";
}
*/

function createClockanEntry($api_key, $project_id, $person_id, $date, $hours, $note) {
	$request = createClockanRequest("projects/{$project_id}/time_entries.xml", $api_key);
	// @TODO dont expect date in the Y-m-d format

	try {
		$response = $request->post("<time-entry>
	<person-id>{$person_id}</person-id>
	<date>{$date}</date>
	<hours>{$hours}</hours>
	<description>{$note}</description>
</time-entry>");
		$xml = new SimpleXMLElement($response->getResponse());
		//dump($xml);

	} catch (Kdyby\Curl\CurlException $e) {
		if ($e->getCode() != 404) {
			echo "Sync failed: ";
			echo $e->getMessage() . "\n";
			die;
		}
		// success
		// but seriously, it should return 201, not 404 guys
	}
}


function createClockanRequest($target, $api_key)
{
	$request = new Kdyby\Curl\Request("https://www.clockan.com/$target");

	$request->setUserAgent("HarvestSync (mikulas.dite@gmail.com)");
	$request->headers['Accept'] = 'application/xml';
	$request->headers['Content-Type'] = 'application/xml';
	$request->headers['Authorization'] = 'Basic ' . base64_encode("$api_key:X");

	return $request;
}


function createHarvestRequest($target, $username, $password)
{
	$request = new Kdyby\Curl\Request("https://mikulas.harvestapp.com/$target");

	$request->setUserAgent("HarvestSync (mikulas.dite@gmail.com)");
	$request->headers['Accept'] = 'application/xml';
	$request->headers['Content-Type'] = 'application/xml';
	$request->headers['Authorization'] = 'Basic ' . base64_encode("$username:$password");

	return $request;
}


function getHarvestForDay($doy, $year, $username, $password, $harvest_project_id)
{
	$entries = [];
	$request = createHarvestRequest("daily/$doy/$year", $username, $password);
	try {
		$response = $request->get();
		$xml = new SimpleXMLElement($response->getResponse());
		if (!$xml->day_entries->children()->count()) {
			return [];
		}
		foreach ($xml->day_entries->day_entry as $entry) {
			if ((int) $entry->project_id !== $harvest_project_id) {
				continue;
			}
			if (in_array($entry->task_id, [2177104])) { // not billable
				continue;
			}

			if (!in_array($entry->task_id, [1601599])) { // development
				$note = strToLower($entry->task) . ' – ' . $entry->notes;
			} else {
				$note = (string) $entry->notes;
			}
			if (preg_match('~^\d{3,}($|\s+)~', $note)) {
				$note = "#$note"; // add hash to issue id
			}
			$note = str_replace('project management', 'administrativa', $note);
			$note = str_replace(' - ', ' – ', $note);

			$entries[] = (object) [
				'hours' => (double) $entry->hours,
				'notes' => trim($note),
				'date' => (string) $entry->spent_at,
			];
		}

	} catch (Kdyby\Curl\CurlException $e) {
		echo $e->getMessage() . "\n";
	}

	return $entries;
}


function printEntries($entries)
{
	$lastDate = NULL;
	$odd = FALSE;
	foreach ($entries as $entry) {
		if ($lastDate !== $entry->date) {
			$odd = $odd ? FALSE : TRUE;
		}

		if ($odd) {
			$meta = Colorize::bg_blue($entry->date);
		} else {
			$meta = Colorize::bg_magenta($entry->date);
		}

		$meta .= " ";
		@list($hours, $minutes) = explode('.', $entry->hours); // fails if time is zero
		$time = ((int) $hours) . ':' . str_pad(round(60 * $minutes / 100, 0), 2, '0', STR_PAD_LEFT);
		if ($entry->hours <= .3) {
			$meta .= Colorize::white($time);
		} else if ($entry->hours <= 1) {
			$meta .= Colorize::cyan($time);
		} else if ($entry->hours > 3) {
			$meta .= Colorize::red($time);
		} else if ($entry->hours > 1) {
			$meta .= Colorize::magenta($time);
		}

		echo str_pad($meta, 35);
		echo preg_replace_callback('~^(#?\d{3,})($|\s)~', function($m) {
			return Colorize::green($m[1]) . $m[2];
		}, $entry->notes);
		echo "\n";

		$lastDate = $entry->date;
	}
}
