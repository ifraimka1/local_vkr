<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Preparing course
 *
 * @package     local_vkr
 * @copyright   2025 Ifraim Solomonov <solomonov@sfedu.ru>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_vkr;

class course_builder {

    private static array $defaultsections = [
        [
            'name'          => 'Подготовка ВКР',
            'summary'       => '',
            'summaryformat' => FORMAT_HTML,
            'sequence'      => '',
            'visible'       => 1,
            'availability'  => null,
        ],
        [
            'name'          => 'Защита ВКР',
            'summary'       => '',
            'summaryformat' => FORMAT_HTML,
            'sequence'      => '',
            'visible'       => 1,
            'availability'  => null,
        ],
    ];

    private static array $defaultmodules = [
        "review" => [
            'name' => 'Отзыв руководителя',
            'dependencies' => [],
        ],
        "normcontrol" => [
            'name' => 'Нормоконтроль',
            'dependencies' => ['review'],
        ],
        "pass" => [
            'name' => 'Допуск',
            'dependencies' => ['review', 'normcontrol'],
        ],
    ];

    public static function prepare_course($courseid): void {
        $sectionnumber = self::need_to_prepare($courseid);
        if ($sectionnumber === false) {return;}

        self::set_course_name($courseid);
        self::create_sections($courseid, $sectionnumber);
    }

    public static function reset_course($courseid): void {
        global $DB;

        foreach (self::$defaultsections as $section) {
            $sectionid = $DB->get_field(
                'course_sections',
                'section',
                ['name' => $section['name'], 'course' => $courseid]
            );
            course_delete_section($courseid, $sectionid);
        }

        rebuild_course_cache($courseid, true);
    }

    public static function need_to_prepare($courseid): int|bool {
        global $DB;

        $sections = $DB->get_records('course_sections', ['course' => $courseid]);

        // TODO: сделать названия секций переменными.
        foreach ($sections as $section) {
            if ($section->name === self::$defaultsections[0]['name']) {
                return false;
            }
        }

        return count($sections);
    }

    private static function set_course_name($courseid): void {
        global $DB;

        $updated_course = new \stdClass();
        $updated_course->id = $courseid;
        $updated_course->fullname = "ГЭК - 09.03.02 Информационные системы и технологии 2025";
        $updated_course->shortname = "ГЭК 09.03.02 2025"; // TODO: сделать название курса переменной.

        $current_course = $DB->get_record('course', ['id' => $courseid]);
        if ($current_course->shortname === $updated_course->shortname) {return;}

        $condition = $DB->sql_like('shortname', ':shortname');
        $condition .= " AND id <> :courseid";
        $params = ['courseid' => $courseid, 'shortname' => $updated_course->shortname.'%'];
        $duplicates = $DB->get_records_select(
            'course',
            $condition,
            $params,
            '',
            'id'
        );

        $duplicatesamount = count($duplicates);
        if ($duplicatesamount > 0) {
            $updated_course->shortname .= ' ' . ++$duplicatesamount;
        }

        update_course($updated_course);
    }

    private static function create_sections($courseid, $sectionnumber) {
        global $DB;

        foreach (self::$defaultsections as $section) {
            $section['course'] = $courseid;
            $section['section'] = ++$sectionnumber;
            $DB->insert_record('course_sections', $section);
        }

        rebuild_course_cache($courseid, true);

        self::create_modules($courseid, --$sectionnumber);
    }

    private static function create_modules($courseid, $sectionnumber): void {
        global $CFG;
        require_once($CFG->dirroot.'/mod/assign/lib.php');
        require_once($CFG->dirroot.'/mod/assign/locallib.php');

        foreach (self::$defaultmodules as $mod) {
            $createdmodinfo = (object)[
                'modulename' => 'assign',
                'section' => $sectionnumber,
                'course' => $courseid,
                'name' => $mod['name'],
                'introeditor' => [
                    'text' => 'Загрузите окончательную версию ВКР и отзыв руководителя',
                    'format' => FORMAT_HTML,
                ],
                'alwaysshowdescription' => 1,
                'submissiondrafts' => 0,
                'requiresubmissionstatement' => 0,
                'sendnotifications' => 0,
                'allowsubmissionsfromdate' => 0,
                'sendlatenotifications' => 0,
                'duedate' => time() + (86400 * 30), // TODO: установка срока.
                'cutoffdate' => 0,
                'grade' => 100,
                'gradingduedate' => 0,
                'teamsubmission' => 0,
                'requireallteammemberssubmit' => 0,
                'teamsubmissiongroupingid' => 0,
                'blindmarking' => 0,
                'hidegrader' => 0,
                'attemptreopenmethod' => 'none',
                'maxattempts' => -1,
                'markingworkflow' => 0,
                'markingallocation' => 0,
                'assignfeedback_comments_enabled' => 1,
                'visible' => 1,
                'cmidnumber' => '',
                'availability' => null,
            ];
            $createdmodinfo = create_module($createdmodinfo);

            self::protect_cm($createdmodinfo->coursemodule);
        }
    }

    private static function protect_cm($cmid) {
        $context = \context_module::instance($cmid);

        $capabilitiestolock = [
            'moodle/course:manageactivities',
            'moodle/course:activityvisibility'
        ];

        $roles = role_fix_names(get_all_roles());

        foreach ($capabilitiestolock as $capability) {
            foreach ($roles as $role) {
                role_change_permission($role->id, $context, $capability, CAP_PROHIBIT);
            }
        }
    }
}
