<?php

/**
 * This script aim to match the teacher name abbreviations with the full names.
 * 
 * It takes as input:
 * - `orarioV2.json`: the complete timetable, with the short version of teacher surnames
 * - `teachers.txt`: the list of full names of teachers, in format SURNAME NAME, separated by newlines
 * - `hardcoded.txt`: 
 * 
 * With a few exceptions, abbreviations are made up of the first 4 letters of surname,
 * or the first 3 letter of the surname + the first letter of the name, in case of homonymy.
 * 
 * In some cases, these rules are not followed, so at the end abbbreviations that were not matched
 * with these rules are tried to match in more creative ways.
 */

define('REGEX_CLASSE_DI_CONCORSO', '/[A-Za-z]{1}[0-9]{2,4}-[A-Za-z0-9]+/');

function getMatchesForAbbreviation($partialName, $teachers) {
    $matches = [];

    foreach ($teachers as $teacher) {
        if (stripos(str_replace([' ', '\''], '', $teacher), str_replace([' ', '\''], '', $partialName)) === 0) {
            $matches[] = $teacher;
        }
    }

    if (count($matches) === 0) {
        $partialSurname = substr(str_replace(' ', '', $partialName), 0, -1);
        $partialName = substr(str_replace(' ', '', $partialName), -1);

        foreach ($teachers as $teacher) {
            if (stripos($teacher, $partialSurname) === 0 && stripos($teacher, ' ' . $partialName) !== false) {
                $matches[] = $teacher;
            }
        }
    }

    return $matches;
}

$teacherAbbreviations = [];
$teacherHardcodedAbbreviations = array_column(array_map('str_getcsv', file(realpath(__DIR__) . '/../config/hardcoded_abbreviations.txt')), 1, 0);
$teacherFullNames = explode(PHP_EOL, file_get_contents(realpath(__DIR__) . '/../config/teachers.txt'));

$finalMatches = [];

$orarioV2 = json_decode(file_get_contents('../orarioV2.json'), true);

// extrac the list of abbriavations from the current timetable
foreach ($orarioV2['timetable'] as $class => $classTimetable) {
    foreach ($classTimetable as $lesson) {
        foreach ($lesson['teachers_classrooms'] as $teacherClassroom) {
            if (!in_array($teacherClassroom['teacher'], $teacherAbbreviations)) {
                $teacherAbbreviations[] = $teacherClassroom['teacher'];
            }
        }
    }
}

// The algorithm is wrapped in a loop, because it may be needed to run it 2-3 times in order to match all the teachers
// For example, let assume we have two teachers: ROSS (Rossi Andrea) and and ROSM (Rossi Marco);
// the first time we cannot unambiguously match ROSS, because it fits both Rossi Andrea and Rossi Mario.
// However, ROSM only fits Rossi Marco, so after we assigned it, and run the algorithm the second time,
// ROSS can only be matched to Rossi Andrea, because Rossi Marco has already been matched.

do {
    $changesMade = false;

    foreach ($teacherAbbreviations as $abbreviation) {
        if (isset($finalMatches[$abbreviation])) continue;

        if (isset($teacherHardcodedAbbreviations[strtoupper($abbreviation)])) {
            $matches = [$teacherHardcodedAbbreviations[strtoupper($abbreviation)]];
        } else {
            $matches = getMatchesForAbbreviation($abbreviation, array_filter($teacherFullNames, fn($tch) => !in_array($tch, array_values($finalMatches))));
        }

        if (count($matches) === 1) {
            $finalMatches[$abbreviation] = $matches[0];
            $changesMade = true;
        }
    }
} while ($changesMade);

$unmatchedAbbreviations = [];

foreach ($teacherAbbreviations as $abbreviation) {
    if (!isset($finalMatches[$abbreviation]) && !preg_match(REGEX_CLASSE_DI_CONCORSO, $abbreviation)) {
        $unmatchedAbbreviations[] = $abbreviation;
    }
}

$toReturn = [];

// For non-homonyms teachers (different surnames) we don't want to use the full name (SURNAME NAME), but just the first name.
foreach ($finalMatches as $abbreviation => $fullName) {
    $words = explode(' ', $fullName);
    $name = '';

    for ($i = 1; $i <= count($words); $i++) {
        $name = '';

        for ($j = 0; $j < $i; $j++) {
            $name .= $words[$j] . ' ';
        }

        $name = trim($name);

        if (strlen($name) < 3) continue;

        foreach ($teacherFullNames as $teacherFullName) {
            if (stripos($teacherFullName, $name . ' ') === 0 && $teacherFullName !== $fullName) {
                $name = $fullName;
                break 2;
            }
        }

        break;
    }

    $toReturn[] = [
        'abbreviation' => $abbreviation,
        'name' => ucwords(strtolower($name), " \'"),
        'full_name' => ucwords(strtolower($fullName), " \'"),
    ];
}

file_put_contents('abbreviations_matches.json', json_encode($toReturn, JSON_PRETTY_PRINT));
file_put_contents('abbreviations_unmatched.json', json_encode($unmatchedAbbreviations, JSON_PRETTY_PRINT));
