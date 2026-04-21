<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Mobile app support for local_ai_course_assistant.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025-2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$addons = [
    'local_ai_course_assistant' => [
        'handlers' => [
            'coursechat' => [
                'displaydata' => [
                    'title' => 'pluginname',
                    'icon' => 'chatbubbles',
                    'class' => '',
                ],
                'delegate' => 'CoreCourseOptionsDelegate',
                'method' => 'mobile_course_view',
                'offlinefunctions' => [],
            ],
        ],
        'lang' => [
            ['pluginname', 'local_ai_course_assistant'],
            ['mobile_welcome', 'local_ai_course_assistant'],
            ['mobile_welcome_sub', 'local_ai_course_assistant'],
            ['mobile_placeholder', 'local_ai_course_assistant'],
            ['mobile_clear', 'local_ai_course_assistant'],
            ['mobile_disabled', 'local_ai_course_assistant'],
            ['mobile_chip_concepts', 'local_ai_course_assistant'],
            ['mobile_chip_studyplan', 'local_ai_course_assistant'],
            ['mobile_chip_quiz', 'local_ai_course_assistant'],
        ],
    ],
];
