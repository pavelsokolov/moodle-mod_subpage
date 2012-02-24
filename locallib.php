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
 * Library of functions used by the subpage module.
 *
 * This contains functions that are called from within the quiz module only
 * Functions that are also called by core Moodle are in {@link lib.php}
 * This script also loads the code in {@link questionlib.php} which holds
 * the module-indpendent code for handling questions and which in turn
 * initialises all the questiontype classes.
 *
 * @package mod
 * @subpackage subpage
 * @copyright 2011 The Open University
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author Dan Marsden <dan@danmarsden.com>
 * @author Stacey Walker <stacey@catalyst-eu.net>
 */

if (!defined('MOODLE_INTERNAL')) {
    die('Direct access to this script is forbidden.'); /// It must be included from a Moodle page.
}

/**
 * Include those library functions that are also used by core Moodle or other modules
 */
require_once($CFG->dirroot . '/mod/subpage/lib.php');


/// Constants ///////////////////////////////////////////////////////////////////

class mod_subpage  {
    protected $subpage; //content of the subpage table row
    protected $cm;      //content of the relevant course_modules table
    protected $course;  //content of the relevant course table row

    /**
     * Start of range of section numbers used by subpages. Designed to be above any
     * likely week or topic number, but not too high that it causes heavy database
     * bulk in terms of unused sections.
     */
    const SECTION_NUMBER_MIN = 100;

    /**
     * End of range of section numbers used by subpages. Designed to allow more
     * subpage sections than anyone could possibly need. (Max real course so
     * far is using ~100 sections; this allows 900.)
     */
    const SECTION_NUMBER_MAX = 1000;

    /**
     * Constructor
     * @param object $context the context this table relates to.
     * @param string $id what to put in the id="" attribute.
     */
    public function __construct($subpage, $cm, $course) {
        $this->subpage = $subpage;
        $this->cm = $cm;
        $this->course = $course;
    }

    /**
     * Obtains full cm, subpage and course records and constructs a subpage object.
     *
     * @param int $cmid the course module id
     * @return class mod_subpage
     */
    public static function get_from_cmid($cmid) {
        global $DB;
        $cm = get_coursemodule_from_id('subpage', $cmid, 0, false, MUST_EXIST);
        $subpage = $DB->get_record('subpage', array('id'=>$cm->instance), '*', MUST_EXIST);
        $course = $DB->get_record('course', array('id'=>$cm->course), '*', MUST_EXIST);
        return new mod_subpage($subpage, $cm, $course);
    }

    /**
     * returns $cm
     *
     * @return stdClass full course module record
     */
    public function get_course_module() {
        return $this->cm;
    }

    /**
     * returns $course
     *
     * @return stdClass full course record
     */
    public function get_course() {
        return $this->course;
    }

    /**
     * returns $subpage
     *
     * @return stdClass full subpage record
     */
    public function get_subpage() {
        return $this->subpage;
    }

    /**
     * Returns true if there is an intro.
     *
     * @return boolean
     */
    public function has_intro() {
        return !empty($this->subpage->intro);
    }

    /**
     * returns text of name
     *
     * @return string
     */
    public function get_name() {
        return $this->subpage->name;
    }

    /**
     * returns text of intro
     *
     * @return string
     */
    public function get_intro() {
        return $this->subpage->intro;
    }

    /**
     * returns text format of intro
     *
     * @return int
     */
    public function get_intro_format() {
        return $this->subpage->introformat;
    }

    /**
     * Returns an array of all the section objects (containing data from course_sections table)
     * that are included in this subpage, in the order in which they are displayed.
     *
     * @return array
     */
    public function get_sections() {
        global $DB;
        $sql = "SELECT cs.*, ss.pageorder, ss.stealth
                FROM {course_sections} cs, {subpage_sections} ss
                WHERE cs.id = ss.sectionid AND ss.subpageid = ? ORDER BY ss.pageorder";
        return $DB->get_records_sql($sql, array($this->subpage->id));

    }

    /**
     * Returns the highest pageorder of this subpage.
     *
     * @return array
     */
    public function get_last_section_pageorder() {
        global $DB;
        $sql = "SELECT MAX(pageorder) FROM {subpage_sections} ss WHERE
                subpageid = ?";
        return $DB->get_field_sql($sql, array($this->subpage->id));

    }

    /**
     * Adds a new section object to be used by this subpage
     *
     * @return object
     */
    public function add_section($name= '', $summary = '') {
        global $DB, $CFG;
        require_once($CFG->dirroot .'/course/lib.php'); //needed for get_course_section

        $transaction = $DB->start_delegated_transaction();

        // Pick a section number. This query finds the first subpage section
        // on the course that does not have a subpage section in the following
        // section number, and returns that following section number. (This
        // means it can fill up gaps if sections are deleted.)
        $sql = "
SELECT
    cs.section+1 AS num
FROM
    {subpage} s
    JOIN {subpage_sections} ss ON ss.subpageid = s.id
    JOIN {course_sections} cs ON cs.id = ss.sectionid AND cs.course = s.course
    LEFT JOIN  {course_sections} cs2 ON cs2.course = s.course AND cs2.section = cs.section+1
    LEFT JOIN {subpage_sections} ss2 ON ss2.sectionid = cs2.id
WHERE
    s.course = ?
    AND ss2.id IS NULL
ORDER BY
    cs.section";
        $result = $DB->get_records_sql($sql, array($this->course->id), 0, 1);
        if (count($result) == 0) {
            // If no existing sections, use the min number
            $sectionnum = self::SECTION_NUMBER_MIN;
        } else {
            $sectionnum = reset($result)->num;
        }

        // Check to make sure there aren't too many sections
        if ($sectionnum >= self::SECTION_NUMBER_MAX) {
            throw new moodle_exception('sectionlimitexceeded', 'subpage');
        }

        // create a section entry with this section number - this function creates
        // and returns the section.
        $section = get_course_section($sectionnum, $this->course->id);
        //now update summary/name if set above.
        if (!empty($name) or !empty($summary)) {
            $section->name = format_string($name);
            $section->summary = format_text($summary);
            $DB->update_record('course_sections', $section);
        }

        $sql = "SELECT MAX(pageorder) FROM {subpage_sections} WHERE subpageid = ?";
        //get highest pageorder and add 1
        $pageorder = $DB->get_field_sql($sql, array($this->subpage->id))+1;

        $subpage_section = new stdClass();
        $subpage_section->subpageid = $this->subpage->id;
        $subpage_section->sectionid = $section->id;
        $subpage_section->pageorder = $pageorder;
        $subpage_section->stealth = 0;

        $ss = $DB->insert_record('subpage_sections', $subpage_section);

        $transaction->allow_commit();

        return array('subpagesectionid'=>$ss, 'sectionid'=>$section->id);
    }

    /**
     * Moves a section object within the subpage so that it has the new $pageorder value given.
     * @param int $sectionid the sectionid to move.
     * @param int $pageorder the place to move the section.
     */
    public function move_section($sectionid, $pageorder) {
        global $DB;

        $updatesection = $DB->get_record('subpage_sections',
                array('sectionid'=>$sectionid, 'subpageid'=>$this->subpage->id));
        $updatesection->pageorder = $pageorder;
        $DB->update_record('subpage_sections', $updatesection);

        $sections = $DB->get_records('subpage_sections',
                array('subpageid'=>$this->subpage->id), 'pageorder');
        $newpageorder = 1;
        foreach ($sections as $section) {
            if ($section->sectionid == $sectionid) {
                continue;
            }
            if ($newpageorder == $pageorder) {
                $newpageorder++;
            }

            $section->pageorder = $newpageorder;
            $DB->update_record('subpage_sections', $section);
            $newpageorder++;
        }
    }

    /**
     * Deletes a section
     * @param int $sectionid the sectionid to delete
     */
    public function delete_section($sectionid) {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        $coursemodules = $DB->get_records('course_modules',
                array('course'=>$this->course->id, 'section'=>$sectionid));
        foreach ($coursemodules as $cm) {
            $this->delete_module($cm);
        }
        $DB->delete_records('subpage_sections',
                array('sectionid'=>$sectionid, 'subpageid'=>$this->subpage->id));

        //now delete from course_sections;
        $DB->delete_records('course_sections',
                array('id'=>$sectionid, 'course'=>$this->get_course()->id));
        //now fix pageorder
        $subpagesections = $DB->get_records('subpage_sections',
                array('subpageid' => $this->subpage->id), 'pageorder');
        $pageorder = 1;
        foreach ($subpagesections as $subpagesection) {
            $subpagesection->pageorder = $pageorder;
            $DB->update_record('subpage_sections', $subpagesection);
            $pageorder++;
        }
        $transaction->allow_commit();
    }

    /**
     * function used to delete a module - copied from course/mod.php, it would
     * be nice for this to be a core function.
     * @param stdclass $cm full course modules record
     */
    public function delete_module($cm) {
        global $CFG, $OUTPUT, $USER, $DB;

        $cm->modname = $DB->get_field("modules", "name", array("id"=>$cm->module));
        $modcontext = get_context_instance(CONTEXT_MODULE, $cm->id);

        $modlib = "$CFG->dirroot/mod/$cm->modname/lib.php";

        if (file_exists($modlib)) {
            require_once($modlib);
        } else {
            print_error('modulemissingcode', '', '', $modlib);
        }

        $deleteinstancefunction = $cm->modname."_delete_instance";

        if (!$deleteinstancefunction($cm->instance)) {
            echo $OUTPUT->notification("Could not delete the $cm->modname (instance)");
        }

        // remove all module files in case modules forget to do that
        $fs = get_file_storage();
        $fs->delete_area_files($modcontext->id);

        if (!delete_course_module($cm->id)) {
            echo $OUTPUT->notification("Could not delete the $cm->modname (coursemodule)");
        }
        if (!delete_mod_from_section($cm->id, $cm->section)) {
            echo $OUTPUT->notification("Could not delete the $cm->modname from that section");
        }

        // Trigger a mod_deleted event with information about this module.
        $eventdata = new stdClass();
        $eventdata->modulename = $cm->modname;
        $eventdata->cmid       = $cm->id;
        $eventdata->courseid   = $cm->course;
        $eventdata->userid     = $USER->id;
        events_trigger('mod_deleted', $eventdata);

        add_to_log($cm->course, 'course', "delete mod",
                   "view.php?id=$cm->course",
                   "$cm->modname $cm->instance", $cm->id);

        rebuild_course_cache($cm->course);
    }

    /**
     * Toggles the subpage section's stealth
     * @param integer $sectionid the section to set
     * @param boolean $stealth value to set
     */
    public function set_section_stealth($sectionid, $stealth) {
        global $DB;
        $DB->set_field('subpage_sections', 'stealth', $stealth ? 1 : 0,
                array('sectionid' => $sectionid));
    }

    /**
     * Return an array of all course subpage objects
     * @param Object $course
     * @return Array mixed
     */
    public static function get_course_subpages($course) {
        global $DB;

        $sql = "SELECT * FROM {course_modules} WHERE course = ? AND module = " .
                "(SELECT DISTINCT(id) FROM {modules} WHERE name = 'subpage')";
        $results = $DB->get_records_sql($sql, array($course->id));

        $subpages = array();
        foreach ($results as $result) {
            $subpage = self::get_from_cmid($result->id);
            $subpages[$result->id] = $subpage;
        }

        return $subpages;
    }

    /**
     * Return an array of modules that can be moved in this situation
     *    The Array is keyed first with sections (subpage or main course)
     *    and then the modules within each section by cmid
     * @param Object $subpage current subpage
     * @param Array $allsubpages
     * @param Array $coursesections
     * @param Object $modinfo
     * @param String $move to or from
     * @return Array mixed
     */
    public static function moveable_modules($subpage, $allsubpages,
            $coursesections, $modinfo, $move) {
        global $OUTPUT;

        get_all_mods($subpage->get_course()->id, $allmods, $modnames,
                $modnamesplural, $modnamesused);
        $mods = array();

        $subsections = array();
        if (!empty($allsubpages) && $move === 'to') {
            foreach ($allsubpages as $sub) {
                $subsections += $sub->get_sections();
            }
            $sections = $coursesections;
        } else {
            $subsections = $subpage->get_sections();
            $sections = $subsections;
        }

        if ($sections) {
            foreach ($sections as $section) {
                if (!empty($section->sequence)) {
                    if ($move === 'to' && array_key_exists($section->id, $subsections)) {
                        continue;
                    }

                    $sectionalt = (isset($section->pageorder)) ? $section->pageorder
                    : $section->section;
                    if ($move === 'to') {
                        // include the required course/format library
                        global $CFG;
                        require_once("$CFG->dirroot/course/format/" .
                        $subpage->get_course()->format . "/lib.php");
                        $callbackfunction = 'callback_' .
                        $subpage->get_course()->format . '_get_section_name';

                        if (function_exists($callbackfunction)) {
                            $name =  $callbackfunction($subpage->get_course(), $section);
                        } else {
                            $name = $section->name ? $section->name
                            : get_string('section') . ' ' . $sectionalt;
                        }

                    } else {
                        $name = $section->name ? $section->name
                        : get_string('section') . ' ' . $sectionalt;
                    }

                    $sectionmods = explode(',', $section->sequence);
                    foreach ($sectionmods as $modnumber) {
                        if (empty($allmods[$modnumber]) ||
                        $modnumber === $subpage->get_course_module()->id) {
                            continue;
                        }
                        $instancename = format_string($modinfo->cms[$modnumber]->name,
                        true, $subpage->get_course()->id);

                        $customicon = $modinfo->cms[$modnumber]->icon;
                        if (!empty($customicon)) {
                            if (substr($customicon, 0, 4) === 'mod/') {
                                list($modname, $iconname) = explode('/', substr($customicon, 4), 2);
                                $icon = $OUTPUT->pix_url($iconname, $modname);
                            } else {
                                $icon = $OUTPUT->pix_url($customicon);
                            }
                        } else {
                            $icon = $OUTPUT->pix_url('icon', $modinfo->cms[$modnumber]->modname);
                        }
                        $mod = $allmods[$modnumber];
                        $mods[$section->section]['section'] = $name;
                        $mods[$section->section]['pageorder'] = $sectionalt;
                        $mods[$section->section]['mods'][$modnumber] =
                                "<span><img src='$icon' /> " . $instancename . "</span>";
                    }
                }
            }
        }

        return $mods;
    }

    /**
     * TODO Should be documented.
     */
    public static function destination_options($subpage, $allsubpages,
            $coursesections, $modinfo, $move) {

        $othersectionstr = get_string('anothersection', 'mod_subpage');
        $newstr = get_string('newsection', 'mod_subpage');
        $sectionstr = get_string('section', 'mod_subpage');
        $mainpagestr = get_string('coursemainpage', 'mod_subpage');

        $options = array();

        // subpage we're on are the default options
        if ($sections = $subpage->get_sections()) {
            $options = ($move === 'to')
                    ? $options[] = array()
                    : $options[$othersectionstr] = array();
            foreach ($sections as $section) {
                $name = $section->name
                        ? $section->name
                        : $sectionstr . ' ' . $section->pageorder;
                $options[$othersectionstr][
                        $subpage->get_course_module()->id . ',' . $section->id] = $name;
            }
            $options[$othersectionstr][$subpage->get_course_module()->id . ',new'] = $newstr;
        }

        // only move from has other options
        if ($move === 'from') {
            // other subpage sections
            if (!empty($allsubpages)) {
                foreach ($allsubpages as $sub) {
                    $subpagestr = get_string('modulename', 'mod_subpage');
                    $subpagestr .= ': '.$sub->get_subpage()->name;
                    // ignore the current subpage
                    if ($sub->get_course_module()->id !== $subpage->get_course_module()->id) {
                        $options[$subpagestr] = array();
                        if ($sections = $sub->get_sections()) {
                            foreach ($sections as $section) {
                                $name = $section->name ? $section->name
                                : $sectionstr . ' ' . $section->pageorder;
                                $options[$subpagestr][
                                $sub->get_course_module()->id . ',' .$section->id] = $name;
                            }
                            $options[$subpagestr][$sub->get_course_module()->id.',new'] = $newstr;
                        }
                    }
                }
            }

            // course sections
            if (!empty($coursesections)) {

                // include the required course/format library
                global $CFG;
                require_once($CFG->dirroot . '/course/format/' .
                        $subpage->get_course()->format . '/lib.php');
                $callbackfunction = 'callback_' . $subpage->get_course()->format .
                        '_get_section_name';

                // these need to be formatted based on $course->format
                foreach ($coursesections as $coursesection) {
                    if (($coursesection->section > self::SECTION_NUMBER_MAX
                    || $coursesection->section < self::SECTION_NUMBER_MIN)
                    && ($coursesection->section <= $subpage->get_course()->numsections)) {
                        if (function_exists($callbackfunction)) {
                            $coursesection->name =
                            $callbackfunction($subpage->get_course(), $coursesection);
                        }
                        $options[$mainpagestr]['course,'.$coursesection->id] = $coursesection->name;
                    }
                }
            }
        }

        return $options;
    }
    /**
    * Check if the section contains any modules
    *
    * @param int $sectionid the course section id (the id in the course_section table) to delete
    * @return bool true if the section doesn't contains any modules or false otherwise
    */
    public function is_section_empty($sectionid) {
        global $DB;
        if ($DB->count_records('course_modules',
                array('course' => $this->course->id, 'section' => $sectionid))) {
            return false;
        }
        return true;
    }
}
