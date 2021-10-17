<?php
/*
 * ITIS Paleocapa Orario Scraping Tool
 * Created by Cristian Livella, mainly used by BgSchoolBot
 *
 * First script version: February 2017
 * First refactoring: August 2017
 * Second refactoring: October 2021
 *
 * Over time the script has undergone improvements, for example at first
 * the start_time.txt file was not expected, and the few changes that needed to be made
 * were done manually. Starting with the 2020/2021 school year, due to the COVID-19 pandemic,
 * several classes no longer start lessons at 8 some days,
 * so it became necessary to handle these special cases.
 *
 * This script takes three files in input (orario.txt, ore_classi.txt and ore_inizio.txt)
 * and produces 2 JSON file with the timetable of the ITIS Paleocapa.
 *
 * The first one is the timetable in a simple user-readable and computer-readable format.
 * The second one is the timetable in a format that can be easily imported and used by BgSchoolBot.
 *
 */

if (!file_exists('orario.txt') || !$timetable = file_get_contents('orario.txt')) {
    echo 'Error: cannot open orario.txt' . PHP_EOL;
    exit(1);
}

if (!file_exists('ore_classi.txt') OR !$dailyHours = file_get_contents('ore_classi.txt')) {
    echo 'Error: cannot open ore_classi.txt' . PHP_EOL;
    exit(1);
}

if (!file_exists('ore_inizio.txt') OR !$emptyInitialHours = file_get_contents('ore_inizio.txt')) {
    echo 'Error: cannot open ore_inizio.txt' . PHP_EOL;
    exit(1);
}

define('REGEX_CLASSE_DI_CONCORSO', '/[A-Za-z]{1}[0-9]{1,4}-[A-Za-z]+[A-Za-z0-9]+/');

// explode the timetable (orario.txt)
$timetable = explode(PHP_EOL, $timetable);
$timetable = array_map('trim', $timetable);

$lineCount = count($timetable);

$tempTimetable = [];

$time = 1;
$subjectFound = false;
$teachersFound = false;

// In this first loop, information about the hours of lessons for each class is extracted.
// The multidimensional tempTimetable array will contain the lessons of each class in chronological order, but they will not be divided by days.
$line = 0;
while ($line < $lineCount) {
	if (stripos($timetable[$line], 'I.T.I.S. "Paleocapa"') !== false) {
        // skip the next 6 lines, the following one contains the class name
        $line = $line+6;
		$class = $timetable[$line];
		$time = 1;
	}
    elseif (stripos($timetable[$line], 'Pagina ') !== false || strlen($timetable[$line]) === 0) {
        // skip the page number line or the empty line
        $line++;
        continue;
    }
	elseif (strpos($timetable[$line], ':00') === false && $timetable[$line] !== 'lunedì martedì mercoledì giovedì venerdì sabato') {
		if (!$subjectFound && strlen($timetable[$line])) {
            // if the subject has not yet been found, the first valid line that is found is the subject
    		$timetable[$line] = rtrim($timetable[$line], '.');
    		$tempTimetable[$class][$time]['materia'] = ltrim($timetable[$line], '.');
    		$subjectFound = true;
        }
		elseif (!$teachersFound) {
            if ((strpos($timetable[$line], '-') === false) || preg_match(REGEX_CLASSE_DI_CONCORSO, $timetable[$line])) {
                // if the current line doesn't contain hyphen, or if it matches the "classe di concorso" regex, then it's a teacher
                $tempTimetable[$class][$time]['professori'][] = ltrim(ucwords(mb_strtolower($timetable[$line])), '.');

                if (!isset($timetable[$line + 3]) || ((strpos($timetable[$line + 3], '-') === false) || preg_match(REGEX_CLASSE_DI_CONCORSO, $timetable[$line + 3]))) {
                    // if the third line after this one does not exist or contains a professor, then this is the last (or only) teacher of the current hour
                    $teachersFound = true;
                }
            }
		}
		else {
            if (stripos($timetable[$line], 'o ') === 0) {
                // if the line starts with 'o ', it means that this is an alternate classroom for the current lesson;
                // since BgSchoolBot doesn't support this, we ignore it, and hope Bolognini doesn't use it too often :)
                $line++;
                continue;
            }

            // perform some clean
            $classroom = str_replace('Lab. Terr. Occup.', 'LTO', $timetable[$line]);
            $classroom = str_replace(['Aula ', 'Aula', ' Aula Disegno 2', ' Aula Disegno'], '', $classroom);
			if (strpos($classroom, 'Lab') !== false) {
				$classroom = explode(' ', $classroom)[0];
			}

			$tempTimetable[$class][$time]['aula'] = $classroom;

            // the classroom is the last info to be found, after we continue looking for the next lesson
			$subjectFound = false;
			$teachersFound = false;
			$time++;
		}
	}
	$line++;
}

// explode the daily hours (ore_classi.txt)
$dailyHours = explode(PHP_EOL, $dailyHours);
$countClasses = count($dailyHours);
for ($i = 0; $i < $countClasses; $i++) {
	$dailyHours[$i] = explode('.', $dailyHours[$i]);
}

// explode the initial
$emptyInitialHours = explode(PHP_EOL, $emptyInitialHours);
$countClasses = count($dailyHours);
for ($i = 0; $i < $countClasses; $i++) {
	$emptyInitialHours[$i] = explode('.', $emptyInitialHours[$i]);
}

$classIndex = 0;
foreach (array_keys($tempTimetable) AS $class) {
	$time = 1;
	$lessonIndex = 1;
    for ($day = 1; $day < 7; $day++) {
        // set the day and time values for each lesson
        for ($time = 1; $time <= $dailyHours[$classIndex][$day - 1]; $time++) {
            $tempTimetable[$class][$lessonIndex]['giorno'] = $day;
    		$tempTimetable[$class][$lessonIndex]['ora'] = $time;
    		$lessonIndex++;
        }

        // fix the times according to ore_inizio.txt file
        $changes = str_split($emptyInitialHours[$classIndex][$day - 1]);
        foreach ($changes AS $change) {
            $startTime = null;

            if ($change === 'q' || $change === 'w') {
                $startTime = ($change === 'q') ? 4 : 5;
                $sumHours = 1;
            }
            elseif (intval($change) > 0) {
                $startTime = 0;
                $sumHours = intval($change);
            }

            if ($startTime !== null) {
                foreach ($tempTimetable[$class] AS &$lesson) {
                    if (isset($lesson['giorno']) && isset($lesson['ora']) && $lesson['giorno'] === $day && $lesson['ora'] > $startTime) {
                        $lesson['ora'] += $sumHours;
                    }
                }
            }
        }
    }

	$classIndex++;
}

$finalTimetable = [];

// produce an array of timetable records BgSchoolBot compatible
foreach ($tempTimetable AS $class => $classTimetable) {
	foreach ($classTimetable AS $ore) {
        if (isset($ore['professori']) AND count($ore['professori'])>0) {
    		foreach ($ore['professori'] AS $professore) {
                if (!isset($ore['giorno']) OR !isset($ore['materia']) OR !isset($ore['ora'])) {
                    echo 'ERROR AT ' . $class . '!' . PHP_EOL;
                    exit(1);
                }

    			$finalTimetable[] = [$professore, $ore['materia'], $class, $ore['aula'], $ore['giorno'], $ore['ora']];
    		}
        }
	}
}

file_put_contents('orario.json', json_encode($tempTimetable, JSON_PRETTY_PRINT));
file_put_contents('orario_bgschoolbot.json', json_encode($finalTimetable, JSON_PRETTY_PRINT));

echo 'Step 2 completed!' . PHP_EOL;
?>
