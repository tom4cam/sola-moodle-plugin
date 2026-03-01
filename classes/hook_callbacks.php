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

namespace local_ai_course_assistant;

/**
 * Hook callbacks for local_ai_course_assistant.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025 AI Course Assistant
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {

    /**
     * Inject the chat widget into course pages via the before_footer hook.
     *
     * @param \core\hook\output\before_footer_html_generation $hook
     */
    public static function inject_chat_widget(\core\hook\output\before_footer_html_generation $hook): void {
        global $PAGE, $USER, $OUTPUT;

        // Check if plugin is enabled.
        if (!get_config('local_ai_course_assistant', 'enabled')) {
            return;
        }

        // Only inject on course or module context pages.
        $context = $PAGE->context;
        if ($context->contextlevel !== CONTEXT_COURSE && $context->contextlevel !== CONTEXT_MODULE) {
            return;
        }

        // Get the course context (may need to go up from module context).
        if ($context->contextlevel === CONTEXT_MODULE) {
            $coursecontext = $context->get_course_context();
        } else {
            $coursecontext = $context;
        }

        // Don't show on site home (course id 1).
        $courseid = $coursecontext->instanceid;
        if ($courseid == SITEID) {
            return;
        }

        // Check capability.
        if (!has_capability('local/ai_course_assistant:use', $coursecontext)) {
            return;
        }

        // Detect role: administrator > academic_support > student.
        if (has_capability('local/ai_course_assistant:manage', $coursecontext)) {
            $userrole = 'administrator';
        } else if (has_capability('moodle/course:update', $coursecontext)) {
            $userrole = 'academic_support';
        } else {
            $userrole = 'student';
        }

        // Get config values.
        $position = get_config('local_ai_course_assistant', 'position') ?: 'bottom-right';

        // User's personally chosen avatar (falls back to site default).
        $useravatar = \get_user_preferences('local_ai_course_assistant_avatar', null);
        $avatar = $useravatar ?: (get_config('local_ai_course_assistant', 'avatar') ?: 'avatar_01');

        // Build avatar URL.
        $avatarurl = $OUTPUT->image_url("avatars/{$avatar}", 'local_ai_course_assistant')->out(false);

        // Build list of all available avatars as JSON for the picker.
        $availableavatars = [];
        for ($i = 1; $i <= 11; $i++) {
            $id = 'avatar_' . str_pad((string)$i, 2, '0', STR_PAD_LEFT);
            $availableavatars[] = [
                'id'  => $id,
                'url' => $OUTPUT->image_url("avatars/{$id}", 'local_ai_course_assistant')->out(false),
            ];
        }

        // Build SSE URL.
        $sseurl = new \moodle_url('/local/ai_course_assistant/sse.php');

        // Check if user can view analytics.
        $canviewanalytics = has_capability('local/ai_course_assistant:viewanalytics', $coursecontext);
        $analyticsurl = new \moodle_url('/local/ai_course_assistant/analytics.php', ['courseid' => $courseid]);

        // Check if user can access course-level AI settings (editing teachers, managers, admins).
        $cansiteconfig = has_capability('local/ai_course_assistant:manage', $coursecontext);
        $coursesettingsurl = new \moodle_url('/local/ai_course_assistant/course_settings.php', ['courseid' => $courseid]);

        // Build quiz topics from visible course sections.
        $modinfo = get_fast_modinfo($courseid);
        $quizsections = [];
        foreach ($modinfo->get_section_info_all() as $s) {
            if ($s->visible && ($name = get_section_name($courseid, $s)) && $name !== 'General') {
                $quizsections[] = ['name' => $name];
            }
        }

        // Build learning objectives from course summary (parse bullet/numbered list lines).
        $learningobjectives = [];
        $course = get_course($courseid);
        if (!empty($course->summary)) {
            $plaintext = strip_tags($course->summary);
            foreach (preg_split('/[\r\n]+/', $plaintext) as $line) {
                $line = trim(ltrim(trim($line), '-•*0123456789.)'));
                if (strlen($line) > 10) {
                    $learningobjectives[] = ['name' => $line];
                }
            }
        }

        // Build module/activity titles from course structure.
        $moduletitles = [];
        foreach ($modinfo->get_cms() as $cm) {
            if ($cm->uservisible && !empty($cm->name)) {
                $moduletitles[] = ['name' => $cm->name];
            }
        }

        // Detect current module context (resource/activity page).
        $currentpageid = 0;
        $currentpagetitle = '';
        if ($context->contextlevel === CONTEXT_MODULE && !empty($PAGE->cm)) {
            $currentpageid = (int)$PAGE->cm->id;
            $currentpagetitle = (string)($PAGE->cm->name ?? '');
        }

        // Position offsets for fine-grained widget placement.
        $offsetx = (int)(get_config('local_ai_course_assistant', 'position_offset_x') ?: 95);
        $offsety = (int)(get_config('local_ai_course_assistant', 'position_offset_y') ?: 20);

        // Voice mode availability.
        $realtimeenabled = (bool)get_config('local_ai_course_assistant', 'realtime_enabled');

        // TTS proxy URL: available when an OpenAI key is present (realtime or main provider).
        $realtimeapikey = get_config('local_ai_course_assistant', 'realtime_apikey');
        $provider       = get_config('local_ai_course_assistant', 'provider');
        $mainapikey     = get_config('local_ai_course_assistant', 'apikey');
        $hasttskey = !empty($realtimeapikey) || ($provider === 'openai' && !empty($mainapikey));
        $ttsurl = $hasttskey
            ? (new \moodle_url('/local/ai_course_assistant/tts.php'))->out(false)
            : '';

        // Render template.
        $templatedata = [
            'courseid'           => $courseid,
            'sesskey'            => sesskey(),
            'avatarurl'          => $avatarurl,
            'availableavatars'   => json_encode($availableavatars),
            'positionclass'      => $position,
            'userrole'           => $userrole,
            'sseurl'             => $sseurl->out(false),
            'canviewanalytics'   => $canviewanalytics,
            'analyticsurl'       => $analyticsurl->out(false),
            'cansiteconfig'      => $cansiteconfig,
            'coursesettingsurl'  => $coursesettingsurl->out(false),
            'quiztopics'         => json_encode($quizsections),
            'learningobjectives' => json_encode($learningobjectives),
            'moduletitles'       => json_encode($moduletitles),
            'offsetx'            => $offsetx,
            'offsety'            => $offsety,
            'firstname'          => $USER->firstname,
            'currentpageid'      => $currentpageid,
            'currentpagetitle'   => $currentpagetitle,
            'realtimeenabled'    => $realtimeenabled,
            'ttsurl'             => $ttsurl,
        ];

        $html = $OUTPUT->render_from_template('local_ai_course_assistant/chat_widget', $templatedata);

        // Load AMD module.
        $PAGE->requires->js_call_amd('local_ai_course_assistant/chat', 'init');

        $hook->add_html($html);
    }
}
