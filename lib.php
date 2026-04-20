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
 * Library functions for local_ai_course_assistant.
 *
 * This file is kept minimal. The plugin uses hooks and external functions
 * for its primary functionality.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025 AI Course Assistant
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Serves admin-uploaded avatar images from the customavatars filearea.
 *
 * Registered under the system context so any authenticated user can load an
 * avatar to render in the widget. Returns 404 for missing files and 403 when
 * the user is not logged in.
 *
 * @param stdClass $course  Unused (system-context plugin).
 * @param stdClass $cm      Unused.
 * @param \context $context Context the file lives in (system).
 * @param string $filearea  Expected: customavatars.
 * @param array  $args      URL components after the filearea.
 * @param bool   $forcedownload
 * @param array  $options
 * @return bool False on failure; sends file on success.
 */
function local_ai_course_assistant_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    global $USER;
    if ($filearea !== 'customavatars') {
        return false;
    }
    if ($context->contextlevel !== CONTEXT_SYSTEM) {
        return false;
    }
    if (empty($USER->id)) {
        send_file_not_found();
    }
    $itemid = 0;
    $filename = array_pop($args);
    $filepath = '/' . (implode('/', $args) ?: '');
    if (!str_ends_with($filepath, '/')) {
        $filepath .= '/';
    }
    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'local_ai_course_assistant', 'customavatars',
        $itemid, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        send_file_not_found();
    }
    send_stored_file($file, DAYSECS, 0, $forcedownload, $options);
    return true;
}

/**
 * Return the list of admin-uploaded custom avatars.
 *
 * @return array List of ['key' => 'custom:<hash>', 'label' => display name, 'url' => moodle_url]
 */
function local_ai_course_assistant_get_custom_avatars(): array {
    $fs = get_file_storage();
    $context = context_system::instance();
    $files = $fs->get_area_files($context->id, 'local_ai_course_assistant',
        'customavatars', 0, 'filename', false);
    $out = [];
    foreach ($files as $file) {
        $name = $file->get_filename();
        $key = 'custom:' . $file->get_contenthash();
        $url = moodle_url::make_pluginfile_url($context->id, 'local_ai_course_assistant',
            'customavatars', 0, $file->get_filepath(), $name, false);
        $out[] = ['key' => $key, 'label' => $name, 'url' => $url->out(false)];
    }
    return $out;
}
