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

if (!file_exists('ore_classi.txt') || !$dailyHours = file_get_contents('ore_classi.txt')) {
    echo 'Error: cannot open ore_classi.txt' . PHP_EOL;
    exit(1);
}

if (!file_exists('ore_inizio.txt') || !$emptyInitialHours = file_get_contents('ore_inizio.txt')) {
    echo 'Error: cannot open ore_inizio.txt' . PHP_EOL;
    exit(1);
}

if (!file_exists('omonimi.json') || !$oldOmonimi = json_decode(file_get_contents('omonimi.json'), true)) {
    $oldOmonimi = [];
}

define('REGEX_CLASSE_DI_CONCORSO', '/[A-Za-z]{1}[0-9]{2,4}-[A-Za-z0-9]+/');

// remove strange non-standard lines
// TODO: check what is the exact meaning of these annotations
$timetable = preg_replace('/(lun|mar|mer|gio|ven|sab) [0-9]{2}.*-.*[0-9]{2}\n|\r/', '', $timetable);

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
            $classroom = str_replace([' Aula Disegno 2', ' Aula Disegno', 'Aula ', 'Aula'], '', $classroom);
            if (stripos($classroom, 'Lab') !== false || stripos($classroom, ' Ex ') !== false) {
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

// explode the initial hours (ore_inizio.txt)
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

$teacherHours = [];
$finalTimetable = [];

// Produce an array of timetable records BgSchoolBot compatible.
// At the same time, count the lesson hours for each subject for each teacher.
foreach ($tempTimetable AS $class => $classTimetable) {
    foreach ($classTimetable AS $lesson) {
        if (isset($lesson['professori']) && count($lesson['professori']) > 0) {
            foreach ($lesson['professori'] AS $professore) {
                if (!isset($lesson['giorno']) || !isset($lesson['materia']) || !isset($lesson['ora'])) {
                    echo 'ERROR AT ' . $class . '!' . PHP_EOL;
                    exit(1);
                }

                $finalTimetable[] = [$professore, $lesson['materia'], $class, $lesson['aula'], $lesson['giorno'], $lesson['ora']];

                if (!isset($teacherHours[$professore])) {
                    $teacherHours[$professore] = ['ore' => 0, 'materie' => [], 'lezioni' => []];
                }

                if (!isset($teacherHours[$professore]['materie'][$lesson['materia']])) {
                    $teacherHours[$professore]['materie'][$lesson['materia']] = 0;
                }

                // Create the hash of the lesson, considering the day, time and subject.
                // This is to prevent multiple entries of the same lesson, in case of combined classes.
                // (in some particular cases, 2 classes have lessons at the same time with the same teacher)
                $lessonHash = hash('sha512', json_encode([$lesson['giorno'], $lesson['ora'], $lesson['materia']]));
                if (!in_array($lessonHash, $teacherHours[$professore]['lezioni'])) {
                    $teacherHours[$professore]['ore']++;
                    $teacherHours[$professore]['materie'][$lesson['materia']]++;
                    $teacherHours[$professore]['lezioni'][] = $lessonHash;
                }

            }
        }
    }
}

// Here starts the search for homonymous teachers (with the same surname).
$compatibleSubjects = [];
$omonimi = [];

// First, let's look at which subjects are "compatible" with each other,
// meaning that they are in some cases taught by the same teacher.
foreach ($teacherHours AS $teacher => $details) {
    // we skip the teachers who have more than 18 weekly hours of lesson, as these are probably homonyms
    if ($details['ore'] > 18) continue;

    foreach (array_keys($details['materie']) AS $subject1) {
        // create the array in $compatibleSubjects, if it doesn't exist
        if (!isset($compatibleSubjects[$subject1])) {
            $compatibleSubjects[$subject1] = [];
        }

        // Add each other subject teached by this teacher in the array created before,
        // if it doesn't already in it and it is differet from subject1.
        foreach (array_keys($details['materie']) AS $subject2) {
            if (!in_array($subject2, $compatibleSubjects[$subject1]) && $subject1 !== $subject2) {
                $compatibleSubjects[$subject1][] = $subject2;
            }
        }
    }
}

// For each teacher with more then 18 hours, try dividing his subjects into compatible groups.
// If there are more than one group for the same theacher, it is most likely a homonymy.
foreach ($teacherHours AS $teacher => $details) {
    // we skip the teachers who have 18 or less weekly hours of lesson
    if ($details['ore'] <= 18) continue;

    $subjectGroups = [];
    foreach ($details['materie'] AS $subject => $hours) {
        $matchingGroupIndex = null;

        // We look for each subject in each groups to see if the current one is comptabile
        // with another subject that already has a group. In this case, we insert the
        // current subject in the same group, otherwise we insert it in a new group.
        foreach ($subjectGroups AS $groupIndex => $internalSubjects) {
            foreach ($internalSubjects AS $internalSubject => $val2) {
                if (in_array($subject, $compatibleSubjects[$internalSubject])) {
                    $matchingGroupIndex = $groupIndex;
                    break 2;
                }
            }
        }

        if ($matchingGroupIndex === null) {
            // if there is no compatible group, we insert the subject in a new group
            $subjectGroups[] = [$subject => $hours];
        }
        else {
            // otherwise we insert it in the compatibile group
            $subjectGroups[$matchingGroupIndex][$subject] = $hours;
        }
    }

    if (count($subjectGroups) > 1) {
        // If there is more than one group of compatible subjects,
        // then there are multiple professors with the same surname.
        // We insert it in the $omonimi array, which will then be saved
        // in omonimi.json. Then we can manually insert the name
        // of the teachers, and then run step3.php to fix them.
        $currentTeacherHomonyms = ['cognome' => $teacher, 'omonimi' => []];
        foreach ($subjectGroups AS $group) {
            $materie = array_keys($group);
            sort($materie);
            $currentTeacherHomonyms['omonimi'][] = ['nome' => '', 'ore' => array_sum($group), 'materie' => $materie];
        }
        $omonimi[] = $currentTeacherHomonyms;
    }
}

// For each homonymous teacher, we check if the name was already set
// in the json file, and if so we use that.
foreach ($omonimi AS &$omonimo) {
    foreach ($oldOmonimi AS $oldOmonimo) {
        if ($omonimo['cognome'] === $oldOmonimo['cognome']) {
            foreach ($omonimo['omonimi'] AS &$singleOmonimo) {
                foreach ($oldOmonimo['omonimi'] AS $singleOldOmonimo) {
                    asort($singleOmonimo['materie']);
                    asort($singleOldOmonimo['materie']);
                    if ($singleOmonimo['materie'] === $singleOldOmonimo['materie']) {
                        // if the surname and the subjects are the same,
                        // we are referring to the same homonymous,
                        // so we can use the already set name.
                        $singleOmonimo['nome'] = $singleOldOmonimo['nome'];
                    }
                }
            }
        }
    }
}

// Clear references.
unset($omonimo);
unset($singleOmonimo);

// Create the list of homonyms to fix.
$homonymsToFix = [];
foreach ($omonimi AS $omonimo) {
    foreach ($omonimo['omonimi'] AS $singleOmonimo) {
        // if the name is not set, we do nothing
        if (strlen($singleOmonimo['nome']) === 0) continue;

        foreach ($singleOmonimo['materie'] AS $subject) {
            $homonymyHash = hash('sha512', json_encode([$omonimo['cognome'], $subject]));
            $homonymsToFix[$homonymyHash] = $singleOmonimo['nome'];
        }
    }
}

// Fix the homonyms in $tempTimetable.
foreach ($tempTimetable AS $class => &$classTimetable) {
    foreach ($classTimetable AS &$lesson) {
        foreach ($lesson['professori'] AS &$teacher) {
            $homonymyHash = hash('sha512', json_encode([$teacher, $lesson['materia']]));
            if (isset($homonymsToFix[$homonymyHash])) {
                $teacher .= ' ' . $homonymsToFix[$homonymyHash];
            }
        }
    }
}

// Fix the homonyms in $finalTimetable.
foreach ($finalTimetable AS &$teacherLesson) {
    $homonymyHash = hash('sha512', json_encode([$teacherLesson[0], $teacherLesson[1]]));
    if (isset($homonymsToFix[$homonymyHash])) {
        $teacherLesson[0] .= ' ' . $homonymsToFix[$homonymyHash];
    }
}


file_put_contents('orario.json', json_encode($tempTimetable, JSON_PRETTY_PRINT));
file_put_contents('orario_bgschoolbot.json', json_encode($finalTimetable, JSON_PRETTY_PRINT));
file_put_contents('omonimi.json', json_encode($omonimi, JSON_PRETTY_PRINT));

echo 'Step 2 completed!' . PHP_EOL;
?>
