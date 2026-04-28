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
 * Resolve Learning Radar citation tokens to anonymized message rows.
 *
 * Admin-only. Returns a single message identified by its id, with the
 * student name pseudonymized. Used by the analytics frontend to expand
 * [#NNN] citation badges in the LLM response into a tooltip showing the
 * source message.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');

require_login();
$syscontext = context_system::instance();
require_capability('moodle/site:config', $syscontext);
require_sesskey();

header('Content-Type: application/json; charset=utf-8');

$id = required_param('id', PARAM_INT);

$row = $DB->get_record_sql(
    "SELECT m.id, m.role, m.message, m.timecreated, m.userid, m.courseid,
            m.provider, m.model_name, c.fullname AS coursename
       FROM {local_ai_course_assistant_msgs} m
       JOIN {course} c ON c.id = m.courseid
      WHERE m.id = :id",
    ['id' => $id]
);

if (!$row) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Message not found']);
    return;
}

$who = $row->role === 'user'
    ? \local_ai_course_assistant\anonymizer::name((int) $row->userid)
    : ('SOLA' . ($row->provider ? " ({$row->provider}" . ($row->model_name ? "/{$row->model_name}" : '') . ')' : ''));

echo json_encode([
    'ok' => true,
    'id' => (int) $row->id,
    'role' => $row->role,
    'who' => $who,
    'course' => $row->coursename,
    'date' => userdate($row->timecreated, '%Y-%m-%d %H:%M'),
    'message' => $row->message,
]);
