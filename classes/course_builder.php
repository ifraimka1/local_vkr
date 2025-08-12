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

    private static array $sectionstocreate = [
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

    private static array $modulestocreate = [
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
        global $DB;

        $sectionnumber = self::need_to_prepare($courseid);
        if ($sectionnumber === false) {return;}

        foreach (self::$sectionstocreate as $section) {
            $section['course'] = $courseid;
            $section['section'] = ++$sectionnumber;
            $DB->insert_record('course_sections', $section);
        }

        rebuild_course_cache($courseid, true);

        self::create_modules($courseid, --$sectionnumber);
    }

    public static function reset_course($courseid): bool {
        global $DB;

        $sql = "SELECT id
            FROM {course_sections}
            WHERE course = :courseid
              AND (name LIKE 'Подготовка ВКР' OR name LIKE 'Защита ВКР')";
        $params = ['courseid' => $courseid];
        $sections = $DB->get_records_sql($sql, $params);

        foreach ($sections as $section) {
            $modules = $DB->get_records('course_modules', ['course' => $courseid, 'section' => $section->id], '', 'id');
            foreach($modules as $module) {
                course_delete_module($module->id);
            }
            $DB->delete_records('course_sections', ['id' => $section->id]);
        }

        rebuild_course_cache($courseid, true);

        return true;
    }

    public static function need_to_prepare($courseid): int|bool {
        global $DB;

        $sections = $DB->get_records('course_sections', ['course' => $courseid]);

        // TODO: сделать названия секций переменными.
        foreach ($sections as $section) {
            if ($section->name === self::$sectionstocreate[0]['name']) {
                return false;
            }
        }

        return count($sections);
    }

    private static function create_modules($courseid, $sectionnumber): void {
        global $CFG;
        require_once($CFG->dirroot.'/mod/assign/lib.php');
        require_once($CFG->dirroot.'/mod/assign/locallib.php');

        foreach (self::$modulestocreate as $mod) {
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

            course_add_cm_to_section(
                $courseid,
                $createdmodinfo->coursemodule,
                $sectionnumber
            );

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
