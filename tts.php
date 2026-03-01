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
 * OpenAI TTS proxy endpoint.
 * Accepts POST {text, sesskey, courseid}, returns JSON {audio: base64, type: 'audio/mpeg'}.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025 AI Course Assistant
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);
require_once('../../config.php');

require_login();
require_sesskey();

header('Content-Type: application/json');

$text     = required_param('text',     PARAM_TEXT);
$courseid = optional_param('courseid', 0,          PARAM_INT);

// Verify capability.
if ($courseid > 0) {
    $context = context_course::instance($courseid);
} else {
    $context = context_system::instance();
}
require_capability('local/ai_course_assistant:use', $context);

// Require either realtime to be enabled OR OpenAI as the main provider with an API key.
$realtimeenabled = get_config('local_ai_course_assistant', 'realtime_enabled');
$provider        = get_config('local_ai_course_assistant', 'provider');
$mainapikey      = get_config('local_ai_course_assistant', 'apikey');
if (!$realtimeenabled && !($provider === 'openai' && !empty($mainapikey))) {
    http_response_code(403);
    echo json_encode(['error' => 'OpenAI TTS is not available.']);
    exit;
}

// Get API key — realtime-specific key takes priority, then fall back to main OpenAI key.
$apikey = get_config('local_ai_course_assistant', 'realtime_apikey');
if (empty($apikey)) {
    $apikey = get_config('local_ai_course_assistant', 'apikey');
}
if (empty($apikey)) {
    http_response_code(503);
    echo json_encode(['error' => 'No OpenAI API key configured.']);
    exit;
}

$voice = get_config('local_ai_course_assistant', 'realtime_voice') ?: 'shimmer';

// Truncate text to avoid excessive API costs.
$text = mb_substr(trim($text), 0, 4096);

$curl = new \curl();
$curl->setHeader([
    'Authorization: Bearer ' . $apikey,
    'Content-Type: application/json',
]);

$body = json_encode([
    'model'  => 'tts-1',
    'voice'  => $voice,
    'input'  => $text,
    'format' => 'mp3',
]);

$response = $curl->post('https://api.openai.com/v1/audio/speech', $body);
$info     = $curl->get_info();

if ($info['http_code'] !== 200) {
    http_response_code(502);
    echo json_encode(['error' => 'TTS API error ' . $info['http_code']]);
    exit;
}

echo json_encode([
    'audio' => base64_encode($response),
    'type'  => 'audio/mpeg',
]);
