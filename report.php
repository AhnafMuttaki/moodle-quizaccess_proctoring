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
 * Report for the quizaccess_proctoring plugin.
 *
 * @package   quizaccess_proctoring
 * @copyright 2020 Brain Station 23
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later.
 */


require_once __DIR__ . '/../../../../config.php';
require_once $CFG->dirroot . '/lib/tablelib.php';

// Get vars.
$courseid = required_param('courseid', PARAM_INT);
$cmid = required_param('cmid', PARAM_INT);
$studentid = optional_param('studentid', '', PARAM_INT);
$reportid = optional_param('reportid', '', PARAM_INT);
$log_action = optional_param('log_action', '', PARAM_TEXT);

$context = context_module::instance($cmid, MUST_EXIST);

list ($course, $cm) = get_course_and_cm_from_cmid($cmid, 'quiz');

require_login($course, true, $cm);


$COURSE = $DB->get_record('course', array('id' => $courseid));
$quiz = $DB->get_record('quiz', array('id' => $cm->instance));

$params = array(
    'courseid' => $courseid,
    'userid' => $studentid,
    'cmid' => $cmid
);
if ($studentid) {
    $params['studentid'] = $studentid;
}
if ($reportid) {
    $params['reportid'] = $reportid;
}

$url = new moodle_url(
    '/mod/quiz/accessrule/proctoring/report.php',
    $params
);

//$form = new quizaccess_proctoring_delete_form($url);

$PAGE->set_url($url);
$PAGE->set_pagelayout('course');
$PAGE->set_title($COURSE->shortname . ': ' . get_string('pluginname', 'quizaccess_proctoring'));
$PAGE->set_heading($COURSE->fullname . ': ' . get_string('pluginname', 'quizaccess_proctoring'));

$PAGE->navbar->add(get_string('quizaccess_proctoring', 'quizaccess_proctoring'), $url);

echo $OUTPUT->header();

echo '<div id="main">
<h2>' . get_string('eprotroringreports', 'quizaccess_proctoring') . '' . $quiz->name . '</h2>
<div class="box generalbox m-b-1 adminerror alert alert-info p-y-1">'
    . get_string('eprotroringreportsdesc', 'quizaccess_proctoring') . '</div>
';

if (has_capability('quizaccess/proctoring:deletecamshots', $context, $USER->id)
    && $studentid != null
    && $cmid != null
    && $courseid != null
    && $reportid != null
    && !empty($log_action)
) {
    // Delete images
    // Remove logs from quizaccess_proctoring_logs
    $DB->delete_records('quizaccess_proctoring_logs', array('courseid' => $courseid, 'quizid' => $cmid, 'userid' => $studentid));

    $filesql = 'SELECT * FROM {files} WHERE userid IN ('.$studentid.') AND contextid IN ('.$context->id.') AND component = \'quizaccess_proctoring\' AND filearea = \'picture\'';
    $usersfile = $DB->get_records_sql($filesql);

    $fs = get_file_storage();
    foreach ($usersfile as $file):
        $fs->delete_area_files($context->id, 'quizaccess_proctoring', 'picture', $file->id);
    endforeach;
    $url2 = new moodle_url(
        '/mod/quiz/accessrule/proctoring/report.php',
        array(
            'courseid' => $courseid,
            'cmid' => $cmid
        )
    );
    redirect($url2, 'Images deleted!', -11);
}

if(has_capability('quizaccess/proctoring:deletecamshots', $context, $USER->id)
    && $log_action=="deletesingle"
){
    //redirect($CFG->wwwroot.'/local/message/manage.php', 'Message created successfully!');
    $logsql = "SELECT * FROM {quizaccess_proctoring_logs} WHERE id= $reportid";
    $records = $DB->get_records_sql($logsql);

    if(count($records)>0){
        $file_id = 0;
        $tempcontextid = 0;

        foreach($records as $record){
            $file_id = $record->fileid;
        }

        $filesql = "SELECT * FROM {files} WHERE id=$file_id";
        $usersfile = $DB->get_records_sql($filesql);

        foreach($usersfile as $tempfile){
            $tempcontextid = $tempfile->contextid;
        }
        ///// Delete Image
        /// Delete the file record
        $DB->delete_records('quizaccess_proctoring_logs', array('id' => $reportid));
        /// Delete the actual file
        $fs = get_file_storage();
        $fs->delete_area_files($tempcontextid, 'quizaccess_proctoring', 'picture', $file_id);
        //var_dump($file_id);
    }
}


if (has_capability('quizaccess/proctoring:viewreport', $context, $USER->id) && $cmid != null && $courseid != null) {

    // Check if report if for some user.
    if ($studentid != null && $cmid != null && $courseid != null && $reportid != null) {
        // Report for this user.
        $sql = "SELECT e.id as reportid, e.userid as studentid, e.webcampicture as webcampicture, e.status as status,
         e.timemodified as timemodified, u.firstname as firstname, u.lastname as lastname, u.email as email
         from  {quizaccess_proctoring_logs} e INNER JOIN {user} u  ON u.id = e.userid
         WHERE e.courseid = '$courseid' AND e.quizid = '$cmid' AND u.id = '$studentid' AND e.id = '$reportid'";
    }

    if ($studentid == null && $cmid != null && $courseid != null) {
        // Report for all users.
        $sql = "SELECT  DISTINCT e.userid as studentid, u.firstname as firstname, u.lastname as lastname,
                u.email as email, max(e.webcampicture) as webcampicture,max(e.id) as reportid, max(e.status) as status,
                max(e.timemodified) as timemodified
                from  {quizaccess_proctoring_logs} e INNER JOIN {user} u ON u.id = e.userid
                WHERE e.courseid = '$courseid' AND e.quizid = '$cmid'
                group by e.userid, u.firstname, u.lastname, u.email";
    }

    // Print report.
    $table = new flexible_table('proctoring-report-' . $COURSE->id . '-' . $cmid);

    $table->define_columns(array('fullname', 'email', 'dateverified', 'actions'));
    $table->define_headers(
        array(
            get_string('user'),
            get_string('email'),
            get_string('dateverified', 'quizaccess_proctoring'),
            get_string('actions', 'quizaccess_proctoring')
        )
    );
    $table->define_baseurl($url);

    $table->set_attribute('cellpadding', '5');
    $table->set_attribute('class', 'generaltable generalbox reporttable');
    $table->setup();

    // Prepare data.
    $sqlexecuted = $DB->get_recordset_sql($sql);

    foreach ($sqlexecuted as $info) {
        $data = array();
        $data[] = '<a href="' . $CFG->wwwroot . '/user/view.php?id=' . $info->studentid .
            '&course=' . $courseid . '" target="_blank">' . $info->firstname . ' ' . $info->lastname . '</a>';

        $data[] = $info->email;

        $data[] = date("Y/M/d H:m:s", $info->timemodified);

        $data[] = '<a href="?courseid=' . $courseid .
            '&quizid=' . $cmid . '&cmid=' . $cmid . '&studentid=' . $info->studentid . '&reportid=' . $info->reportid . '">' .
            get_string('picturesreport', 'quizaccess_proctoring') . '</a>';

        $table->add_data($data);
    }
    $table->finish_html();


    // Print image results.
    if ($studentid != null && $cmid != null && $courseid != null && $reportid != null) {

        $data = array();
        $sql = "SELECT e.id as reportid, e.userid as studentid, e.webcampicture as webcampicture, e.status as status,
        e.timemodified as timemodified, u.firstname as firstname, u.lastname as lastname, u.email as email
        from {quizaccess_proctoring_logs} e INNER JOIN {user} u  ON u.id = e.userid
        WHERE e.courseid = '$courseid' AND e.quizid = '$cmid' AND u.id = '$studentid'";

        $sqlexecuted = $DB->get_recordset_sql($sql);
        echo '<h3>' . get_string('picturesusedreport', 'quizaccess_proctoring') . '</h3>';

        $tablepictures = new flexible_table('proctoring-report-pictures' . $COURSE->id . '-' . $cmid);

        $tablepictures->define_columns(
            array(get_string('name', 'quizaccess_proctoring'),
                get_string('webcampicture', 'quizaccess_proctoring'),
                'Actions'
            )
        );
        $tablepictures->define_headers(
            array(get_string('name', 'quizaccess_proctoring'),
                get_string('webcampicture', 'quizaccess_proctoring'),
                get_string('actions', 'quizaccess_proctoring')
            )
        );
        $tablepictures->define_baseurl($url);

        $tablepictures->set_attribute('cellpadding', '2');
        $tablepictures->set_attribute('class', 'generaltable generalbox reporttable');

        $tablepictures->setup();
        $pictures = '';

        foreach ($sqlexecuted as $info) {
            $pictures .= $info->webcampicture
                ? ' <a class="quiz-img-div" onclick="return confirm(`Are you sure want to delete this picture?`)" href="?courseid='.$courseid.'&cmid=' . $cmid.'&reportid='.$info->reportid.'&log_action=deletesingle"><img title="Click to Delete" width="100" src="' . $info->webcampicture . '" alt="' . $info->firstname . ' ' . $info->lastname . '" /></a>'
                : '';
        }

        $datapictures = array(
            $info->firstname . ' ' . $info->lastname . '<br/>' . $info->email,
            $pictures,
            '<a onclick="return confirm(`Are you sure want to delete the pictures?`)" class="text-danger" href="?courseid=' . $courseid .
            '&quizid=' . $cmid . '&cmid=' . $cmid . '&studentid=' . $info->studentid . '&reportid=' . $info->reportid . '&log_action=delete">Delete ALL Images</a>',
        );
        $tablepictures->add_data($datapictures);
        $tablepictures->finish_html();
    }

} else {
    // User has not permissions to view this page.
    echo '<div class="box generalbox m-b-1 adminerror alert alert-danger p-y-1">' .
        get_string('notpermissionreport', 'quizaccess_proctoring') . '</div>';
}
echo '</div>';
echo $OUTPUT->footer();

$icon_path = new moodle_url('/mod/quiz/accessrule/proctoring/pix/delete_icon.png');
echo "<style> .quiz-img-div{position:relative; display: inline-block;}.quiz-img-div:hover:after{content:'';position:absolute;left: 0px;top: 0px;bottom: 0px;width: 100%;background: url('$icon_path') center no-repeat;background-size: 50px;}.quiz-img-div:hover img{opacity: 0.4;} </style>";

