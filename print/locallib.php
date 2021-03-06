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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <http://www.gnu.org/licenses/>.

/**
 * Devuelve el path por defecto de archivos temporales de emarking.
 * Normalmente debiera ser moodledata\temp\emarking
 *
 * @param unknown $postfix
 *            Postfijo (típicamente el id de assignment)
 * @return string El path al directorio temporal
 */
function emarking_get_temp_dir_path($postfix)
{
    global $CFG;
    return $CFG->dataroot . "/temp/emarking/" . $postfix;
}

/**
 * Imports the OMR fonts
 *
 * @param string $echo
 *            if echoes the result
 */
function emarking_import_omr_fonts($echo = false)
{
    global $CFG;
    
    // The list of extensions a font in the tcpdf installation has
    $fontfilesextensions = array(
        '.ctg.z',
        '.php',
        '.z'
    );
    
    // The font files required for OMR
    $fonts = array(
        '3of9_new' => '/mod/emarking/lib/omr/3OF9_NEW.TTF',
        'omrbubbles' => '/mod/emarking/lib/omr/OMRBubbles.ttf',
        'omrextnd' => '/mod/emarking/lib/omr/OMRextnd.ttf'
    );
    
    // We delete previous fonts if any and then import it
    foreach ($fonts as $fontname => $fontfile) {
        
        // Deleteing the previous fonts
        foreach ($fontfilesextensions as $extension) {
            $fontfilename = $CFG->libdir . '/tcpdf/fonts/' . $fontname . $extension;
            if (file_exists($fontfilename)) {
                echo "Deleting $fontfilename<br/>";
                unlink($fontfilename);
            } else {
                echo "$fontfilename does not exist, it must be created<br/>";
            }
        }
        
        // Import the font
        $ttfontname = TCPDF_FONTS::addTTFfont($CFG->dirroot . $fontfile, 'TrueType', 'ansi', 32);
        
        // Validate if import went well
        if ($ttfontname !== $fontname) {
            echo "Fatal error importing font $fontname<br/>";
            return false;
        } else {
            echo "$fontname imported!<br/>";
        }
    }
    
    return true;
}

/**
 * Returns the path for a student picture.
 * The path is the directory plus
 * two subdirs based on the last two digits of the user idnumber,
 * e.g: user idnumber 12345 will be stored in
 * $CFG->emarking_pathuserpicture/5/4/user12345.png
 *
 * If the directory path is not configured or does not exist returns false
 * 
 * @param unknown $studentidnumber            
 * @return string|boolean false if user pictures are not configured or invalid idnumber (length < 2)
 */
function emarking_get_student_picture_path($studentidnumber)
{
    global $CFG;
    
    // If the length of the idnumber is less than 2 returns false
    if (strlen(trim($studentidnumber)) < 2)
        return false;
        
        // If the directory for user pictures is configured and exists
    if (isset($CFG->emarking_pathuserpicture) && $CFG->emarking_pathuserpicture && is_dir($CFG->emarking_pathuserpicture)) {
        // Reverse the id number
        $idstring = "" . $studentidnumber;
        $revid = strrev($idstring);
        
        // The path is the directory plus two subdirs based on the last two digits
        // of the user idnumber, e.g: user idnumber 12345 will be stored in
        // $CFG->emarking_pathuserpicture/5/4/user12345.png
        $idpath = $CFG->emarking_pathuserpicture;
        $idpath .= "/" . substr($revid, 0, 1);
        $idpath .= "/" . substr($revid, 1, 1);
        
        return $idpath . "/user$idstring.png";
    }
    
    return false;
}

function emarking_get_student_picture($student, $userimgdir)
{
    global $CFG, $DB;
    
    // Get the image file for student if user pictures are configured
    if ($studentimage = emarking_get_student_picture_path($student->idnumber) && file_exists($studentimage))
        return $studentimage;
        
    // If no picture was found in the pictures repo try to use the
    // Moodle one or default on the anonymous
    $usercontext = context_user::instance($student->id);
    $imgfile = $DB->get_record('files', array(
        'contextid' => $usercontext->id,
        'component' => 'user',
        'filearea' => 'icon',
        'filename' => 'f1.png'
    ));
    
    if ($imgfile)
        return emarking_get_path_from_hash($userimgdir, $imgfile->pathnamehash, "u" . $student->id, true);
    else
        return $CFG->dirroot . "/pix/u/f1.png";
}

/**
 * Get students count from a course, for printing.
 *
 * @param unknown_type $courseid            
 */
function emarking_get_students_count_for_printing($courseid)
{
    global $DB;
    
    $query = 'SELECT count(u.id) as total
			FROM {user_enrolments} ue
			JOIN {enrol} e ON (e.id = ue.enrolid AND e.courseid = ?)
			JOIN {context} c ON (c.contextlevel = 50 AND c.instanceid = e.courseid)
			JOIN {role_assignments} ra ON (ra.contextid = c.id AND ra.roleid = 5 AND ra.userid = ue.userid)
			JOIN {user} u ON (ue.userid = u.id)
			GROUP BY e.courseid';
    
    // Se toman los resultados del query dentro de una variable.
    $rs = $DB->get_record_sql($query, array(
        $courseid
    ));
    
    return isset($rs->total) ? $rs->total : null;
}

/**
 *
 *
 *
 * creates email to course manager, teacher and non-editingteacher, when a printing order has been created.
 *
 * @param unknown_type $exam            
 * @param unknown_type $course            
 */
function emarking_send_newprintorder_notification($exam, $course)
{
    global $USER;
    
    $postsubject = $course->fullname . ' : ' . $exam->name . '. ' . get_string('newprintorder', 'mod_emarking') . ' [' . $exam->id . ']';
    
    $examhasqr = $exam->headerqr ? get_string('yes') : get_string('no');
    
    $pagestoprint = emarking_exam_total_pages_to_print($exam);
    
    $originals = $exam->totalpages + $exam->extrasheets;
    $copies = $exam->totalstudents + $exam->extraexams;
    $totalsheets = $originals * $copies;
    
    $teachers = get_enrolled_users(context_course::instance($course->id), 'mod/emarking:receivenotification');
    
    $teachersnames = array();
    foreach ($teachers as $teacher) {
        $teachersnames[] = $teacher->firstname . ' ' . $teacher->lastname;
    }
    $teacherstring = implode(',', $teachersnames);
    
    // Create the email to be sent
    $posthtml = '';
    $posthtml .= '<table><tr><th colspan="2">' . get_string('newprintorder', 'mod_emarking') . '</th></tr>';
    $posthtml .= '<tr><td>' . get_string('examid', 'mod_emarking') . '</td><td>' . $exam->id . '</td></tr>';
    $posthtml .= '<tr><td>' . get_string('fullnamecourse') . '</td><td>' . $course->fullname . ' (' . $course->shortname . ')' . '</td></tr>';
    $posthtml .= '<tr><td>' . get_string('teacher', 'mod_emarking') . '</td><td>' . $teacherstring . '</td></tr>';
    $posthtml .= '<tr><td>' . get_string('requestedby', 'mod_emarking') . '</td><td>' . $USER->lastname . ' ' . $USER->firstname . '</td></tr>';
    $posthtml .= '<tr><td>' . get_string('examdate', 'mod_emarking') . '</td><td>' . date("d M Y - H:i", $exam->examdate) . '</td></tr>';
    $posthtml .= '<tr><td>' . get_string('headerqr', 'mod_emarking') . '</td><td>' . $examhasqr . '</td></tr>';
    $posthtml .= '<tr><td>' . get_string('doubleside', 'mod_emarking') . '</td><td>' . ($exam->usebackside ? get_string('yes') : get_string('no')) . '</td></tr>';
    $posthtml .= '<tr><td>' . get_string('printlist', 'mod_emarking') . '</td><td>' . ($exam->printlist ? get_string('yes') : get_string('no')) . '</td></tr>';
    $posthtml .= '<tr><td>' . get_string('originals', 'mod_emarking') . '</td><td>' . $originals . '</td></tr>';
    $posthtml .= '<tr><td>' . get_string('copies', 'mod_emarking') . '</td><td>' . $copies . '</td></tr>';
    $posthtml .= '<tr><td>' . get_string('totalpagesprint', 'mod_emarking') . '</td><td>' . $totalsheets . '</td></tr>';
    $posthtml .= '</table>';
    $posthtml .= '';
    
    // Create the email to be sent
    $posttext = get_string('newprintorder', 'mod_emarking') . '\n';
    $posttext .= get_string('examid', 'mod_emarking') . ' : ' . $exam->id . '\n';
    $posttext .= get_string('fullnamecourse') . ' : ' . $course->fullname . ' (' . $course->shortname . ')' . '\n';
    $posttext .= get_string('teacher', 'mod_emarking') . ' : ' . $teacherstring . '\n';
    $posttext .= get_string('requestedby', 'mod_emarking') . ': ' . $USER->lastname . ' ' . $USER->firstname . '\n';
    $posttext .= get_string('examdate', 'mod_emarking') . ': ' . date("d M Y - H:i", $exam->examdate) . '\n';
    $posttext .= get_string('headerqr', 'mod_emarking') . ': ' . $examhasqr . '\n';
    $posttext .= get_string('doubleside', 'mod_emarking') . ' : ' . ($exam->usebackside ? get_string('yes') : get_string('no')) . '\n';
    $posttext .= get_string('printlist', 'mod_emarking') . ' : ' . ($exam->printlist ? get_string('yes') : get_string('no')) . '\n';
    $posttext .= get_string('originals', 'mod_emarking') . ' : ' . $originals . '\n';
    $posttext .= get_string('copies', 'mod_emarking') . ' : ' . $copies . '\n';
    $posttext .= get_string('totalpagesprint', 'mod_emarking') . ': ' . $totalsheets . '\n';
    
    emarking_send_notification($exam, $course, $postsubject, $posttext, $posthtml);
}

/**
 * Extracts all pages in a big PDF file as separate PDF files, deleting the original PDF if successfull.
 *
 * @param unknown $newfile
 *            PDF file to extract
 * @param unknown $tempdir
 *            Temporary folder
 * @param string $doubleside
 *            Extract every two pages (for both sides scanning)
 * @return number unknown number of pages extracted
 */
function emarking_pdf_count_pages($newfile, $tempdir, $doubleside = true)
{
    global $CFG;
    
    require_once ($CFG->dirroot . "/mod/assign/feedback/editpdf/fpdi/fpdi2tcpdf_bridge.php");
    require_once ($CFG->dirroot . "/mod/assign/feedback/editpdf/fpdi/fpdi.php");
    
    $doc = new FPDI();
    $files = $doc->setSourceFile($newfile);
    $doc->Close();
    
    return $files;
}

/**
 * Creates a batch file for printing with windows
 */
function emarking_create_print_bat()
{
    global $CFG;
    
    // Generate Bat File
    $printerarray = explode(',', $CFG->emarking_printername);
    
    $contenido = "@echo off\r\n";
    $contenido .= "TITLE Sistema de impresion\r\n";
    $contenido .= "color ff\r\n";
    $contenido .= "cls\r\n";
    $contenido .= ":MENUPPL\r\n";
    $contenido .= "cls\r\n";
    $contenido .= "echo #######################################################################\r\n";
    $contenido .= "echo #                     Sistema de impresion                            #\r\n";
    $contenido .= "echo #                                                                     #\r\n";
    $contenido .= "echo # @copyright 2014 Eduardo Miranda                                     #\r\n";
    $contenido .= "echo # Fecha Modificacion 23-04-2014                                       #\r\n";
    $contenido .= "echo #                                                                     #\r\n";
    $contenido .= "echo #   Para realizar la impresion debe seleccionar una de las impresoras #\r\n";
    $contenido .= "echo #   configuradas.                                                     #\r\n";
    $contenido .= "echo #                                                                     #\r\n";
    $contenido .= "echo #                                                                     #\r\n";
    $contenido .= "echo #######################################################################\r\n";
    $contenido .= "echo #   Seleccione una impresora:                                         #\r\n";
    
    $numbers = array();
    for ($i = 0; $i < count($printerarray); $i ++) {
        $contenido .= "echo #   $i - $printerarray[$i]                                                   #\r\n";
        $numbers[] = $i;
    }
    
    $i ++;
    $contenido .= "echo #   $i - Cancelar                                                      #\r\n";
    $contenido .= "echo #                                                                     #\r\n";
    $contenido .= "echo #######################################################################\r\n";
    $contenido .= "set /p preg01= Que desea hacer? [";
    
    $contenido .= implode(',', $numbers);
    $contenido .= "]\r\n";
    
    for ($i = 0; $i < count($printerarray); $i ++) {
        $contenido .= "if %preg01%==$i goto MENU $i \r\n";
    }
    
    $i ++;
    $contenido .= "if %preg01%==$i  goto SALIR\r\n";
    $contenido .= "goto MENU\r\n";
    $contenido .= "pause\r\n";
    
    for ($i = 0; $i < count($printerarray); $i ++) {
        $contenido .= ":MENU" . $i . "\r\n";
        $contenido .= "cls\r\n";
        $contenido .= "set N=%Random%%random%\r\n";
        $contenido .= "plink central.apuntes mkdir -m 0777 ~/pruebas/%N%\r\n";
        $contenido .= "pscp *.pdf central.apuntes:pruebas/%N%\r\n";
        $contenido .= "plink central.apuntes cp ~/pruebas/script_pruebas.sh ~/pruebas/%N%\r\n";
        $contenido .= "plink central.apuntes cd pruebas/%N%;./script_pruebas.sh " . $printerarray[$i] . "\r\n";
        $contenido .= "plink central.apuntes rm -dfr ~/pruebas/%N%\r\n";
        $contenido .= "EXIT\r\n";
    }
    
    $contenido .= ":SALIR\r\n";
    $contenido .= "CLS\r\n";
    $contenido .= "ECHO Cancelando...\r\n";
    $contenido .= "EXIT\r\n";
    
    $random = random_string();
    
    mkdir($CFG->dataroot . '/temp/emarking/' . $random . '_bat/', 0777);
    
    $fp = fopen($CFG->dataroot . "/temp/emarking/" . $random . "_bat/imprimir.bat", "x");
    fwrite($fp, $contenido);
    fclose($fp);
    // Generate zip file
    $zip->addFile($CFG->dataroot . "/temp/emarking/" . $random . "_bat/imprimir.bat", "imprimir.bat");
    $zip->close();
    unlink($CFG->dataroot . "/temp/emarking/" . $random . "_bat/imprimir.bat");
    rmdir($CFG->dataroot . "/temp/emarking/" . $random . "_bat");
}

/**
 * Creates a PDF form for the copy center to print
 *
 * @param unknown $context            
 * @param unknown $exam            
 * @param unknown $userrequests            
 * @param unknown $useraccepts            
 * @param unknown $category            
 * @param unknown $totalpages            
 * @param unknown $course            
 */
function emarking_create_printform($context, $exam, $userrequests, $useraccepts, $category, $totalpages, $course)
{
    global $CFG;
    
    require_once ($CFG->dirroot . "/mod/assign/feedback/editpdf/fpdi/fpdi2tcpdf_bridge.php");
    require_once ($CFG->dirroot . "/mod/assign/feedback/editpdf/fpdi/fpdi.php");
    
    $cantsheets = $totalpages / ($exam->totalstudents + $exam->extraexams);
    $totalextraexams = $exam->totalstudents + $exam->extraexams;
    $canttotalpages = $cantsheets * $totalextraexams;
    
    $pdf = new FPDI();
    $cp = $pdf->setSourceFile($CFG->dirroot . "/mod/emarking/img/printformtemplate.pdf");
    for ($i = 1; $i <= $cp; $i ++) {
        $pdf->AddPage(); // Agrega una nueva página
        if ($i <= $cp) {
            $tplIdx = $pdf->importPage($i); // Se importan las pÃ¡ginas del documento pdf.
            $pdf->useTemplate($tplIdx, 0, 0, 0, 0, $adjustPageSize = true); // se inserta como template el archivo pdf subido
                                                                            
            // Copia/ImpresiÃ³n/Plotteo
            $pdf->SetXY(32, 48.5);
            $pdf->Write(1, "x");
            // Fecha dÃ­a
            $pdf->SetXY(153, 56);
            $pdf->Write(1, core_text::strtoupper(date('d')));
            // Fecha mes
            $pdf->SetXY(163, 56);
            $pdf->Write(1, core_text::strtoupper(date('m')));
            // Fecha aÃ±o
            $pdf->SetXY(173, 56);
            $pdf->Write(1, core_text::strtoupper(date('Y')));
            // Solicitante
            $pdf->SetXY(95, 69);
            $pdf->Write(1, core_text::strtoupper($useraccepts->firstname . " " . $useraccepts->lastname));
            // Centro de Costo
            $pdf->SetXY(95, 75.5);
            $pdf->Write(1, core_text::strtoupper($category->idnumber));
            // Campus UAI
            $pdf->SetXY(95, 80.8);
            $pdf->Write(1, core_text::strtoupper(""));
            // NÃºmero originales
            $pdf->SetXY(35, 106.5);
            $pdf->Write(1, core_text::strtoupper($cantsheets));
            // NÃºmero copias
            $pdf->SetXY(60, 106.5);
            $pdf->Write(1, core_text::strtoupper("--"));
            // NÃºmero impresiones
            $pdf->SetXY(84, 106.5);
            $pdf->Write(1, core_text::strtoupper($totalextraexams));
            // BN
            $pdf->SetXY(106, 106.5);
            $pdf->Write(1, "x");
            // PÃ¡ginas totales
            $pdf->SetXY(135, 106.5);
            $pdf->Write(1, core_text::strtoupper($canttotalpages));
            // NÃºmero impresiones Total
            $pdf->SetXY(84, 133.8);
            $pdf->Write(1, core_text::strtoupper(""));
            // PÃ¡ginas totales Total
            $pdf->SetXY(135, 133.8);
            $pdf->Write(1, core_text::strtoupper(""));
            // PÃ¡ginas totales Total
            $pdf->SetXY(43, 146);
            $pdf->Write(1, core_text::strtoupper($course->fullname . " , " . $exam->name));
            // Recepcionado por Nombre
            $pdf->SetXY(30, 164.5);
            $pdf->Write(1, core_text::strtoupper(""));
            // Recepcionado por RUT
            $pdf->SetXY(127, 164.5);
            $pdf->Write(1, core_text::strtoupper(""));
        }
    }
    $pdf->Output("PrintForm" . $exam->id . ".pdf", "I"); // se genera el nuevo pdf
}

/**
 *
 * @param unknown $emarking            
 * @param unknown $student            
 * @param unknown $context            
 * @return Ambigous <mixed, stdClass, false, boolean>|stdClass
 */
function emarking_get_or_create_submission($emarking, $student, $context)
{
    global $DB, $USER;
    
    if ($submission = $DB->get_record('emarking_submission', array(
        'emarking' => $emarking->id,
        'student' => $student->id
    ))) {
        return $submission;
    }
    
    $submission = new stdClass();
    $submission->emarking = $emarking->id;
    $submission->student = $student->id;
    $submission->status = EMARKING_STATUS_SUBMITTED;
    $submission->timecreated = time();
    $submission->timemodified = time();
    $submission->teacher = $USER->id;
    $submission->generalfeedback = NULL;
    $submission->grade = $emarking->grademin;
    $submission->sort = rand(1, 9999999);
    
    $submission->id = $DB->insert_record('emarking_submission', $submission);
    
    // Normal marking - One draft default
    if ($emarking->type == EMARKING_TYPE_NORMAL) {
        $draft = new stdClass();
        $draft->emarkingid = $emarking->id;
        $draft->submissionid = $submission->id;
        $draft->groupid = 0;
        $draft->timecreated = time();
        $draft->timemodified = time();
        $draft->grade = $emarking->grademin;
        $draft->sort = rand(1, 9999999);
        $draft->qualitycontrol = 0;
        $draft->teacher = 0;
        $draft->generalfeedback = NULL;
        $draft->status = EMARKING_STATUS_SUBMITTED;
        
        $DB->insert_record('emarking_draft', $draft);
        
        if ($emarking->qualitycontrol) {
            $qcdrafts = $DB->count_records('emarking_draft', array(
                'emarkingid' => $emarking->id,
                'qualitycontrol' => 1
            ));
            $totalstudents = emarking_get_students_count_for_printing($emarking->course);
            if (ceil($totalstudents / 4) > $qcdrafts) {
                $draft->qualitycontrol = 1;
                $DB->insert_record('emarking_draft', $draft);
            }
        }
    }  // Markers training - One draft per marker
else 
        if ($emarking->type == EMARKING_TYPE_MARKER_TRAINING) {
            // Get all users with permission to grade in emarking
            $markers = get_enrolled_users($context, 'mod/emarking:grade');
            foreach ($markers as $marker) {
                if (has_capability('mod/emarking:supervisegrading', $context, $marker)) {
                    continue;
                }
                $draft = new stdClass();
                $draft->emarkingid = $emarking->id;
                $draft->submissionid = $submission->id;
                $draft->groupid = 0;
                $draft->timecreated = time();
                $draft->timemodified = time();
                $draft->grade = $emarking->grademin;
                $draft->sort = rand(1, 9999999);
                $draft->teacher = $marker->id;
                $draft->generalfeedback = NULL;
                $draft->status = EMARKING_STATUS_SUBMITTED;
                
                $DB->insert_record('emarking_draft', $draft);
            }
        }  // Students training
else 
            if ($emarking->type == EMARKING_TYPE_STUDENT_TRAINING) {
                // Get all users with permission to grade in emarking
                $students = get_enrolled_users($context, 'mod/emarking:submit');
                foreach ($students as $student) {
                    $draft = new stdClass();
                    $draft->emarkingid = $emarking->id;
                    $draft->submissionid = $submission->id;
                    $draft->groupid = 0;
                    $draft->timecreated = time();
                    $draft->timemodified = time();
                    $draft->grade = $emarking->grademin;
                    $draft->sort = rand(1, 9999999);
                    $draft->teacher = $student->id;
                    $draft->generalfeedback = NULL;
                    $draft->status = EMARKING_STATUS_SUBMITTED;
                    
                    $DB->insert_record('emarking_draft', $draft);
                }
            }  // Peer review
else 
                if ($emarking->type == EMARKING_TYPE_PEER_REVIEW) {
                    // TODO: Implement peer review (this is a hard one)
                }
    
    return $submission;
}

/**
 * Draws a table with a list of students in the $pdf document
 *
 * @param unknown $pdf
 *            PDF document to print the list in
 * @param unknown $logofilepath
 *            the logo
 * @param unknown $downloadexam
 *            the exam
 * @param unknown $course
 *            the course
 * @param unknown $studentinfo
 *            the student info including name and idnumber
 */
function emarking_draw_student_list($pdf, $logofilepath, $downloadexam, $course, $studentinfo)
{
    global $CFG;
    
    // Pages should be added automatically while the list grows
    $pdf->SetAutoPageBreak(true);
    $pdf->AddPage();
    
    // If we have a logo we draw it
    $left = 10;
    if ($CFG->emarking_includelogo && $logofilepath) {
        $pdf->Image($logofilepath, $left, 6, 30);
        $left += 40;
    }
    
    // We position to the right of the logo and write exam name
    $top = 8;
    $pdf->SetFont('Helvetica', 'B', 12);
    $pdf->SetXY($left, $top);
    $pdf->Write(1, core_text::strtoupper($downloadexam->name));
    
    // Write course name
    $top += 8;
    $pdf->SetFont('Helvetica', '', 8);
    $pdf->SetXY($left, $top);
    $pdf->Write(1, core_text::strtoupper(get_string('course') . ': ' . $course->fullname));
    
    // Write number of students
    $top += 4;
    $pdf->SetXY($left, $top);
    $pdf->Write(1, core_text::strtoupper(get_string('students') . ': ' . count($studentinfo)));
    
    // Write date
    $top += 4;
    $pdf->SetXY($left, $top);
    $pdf->Write(1, core_text::strtoupper(get_string('date') . ': ' . userdate($downloadexam->examdate, get_string('strftimedatefullshort', 'langconfig'))));
    
    // Write the table header
    $left = 10;
    $top += 8;
    $pdf->SetXY($left, $top);
    $pdf->Cell(10, 10, "N°", 1, 0, 'C');
    $pdf->Cell(20, 10, core_text::strtoupper(get_string('idnumber')), 1, 0, 'C');
    $pdf->Cell(20, 10, core_text::strtoupper(get_string('photo', 'mod_emarking')), 1, 0, 'C');
    $pdf->Cell(90, 10, core_text::strtoupper(get_string('name')), 1, 0, 'C');
    $pdf->Cell(50, 10, core_text::strtoupper(get_string('signature', 'mod_emarking')), 1, 0, 'C');
    $pdf->Ln();
    
    // Write each student
    $current = 0;
    foreach ($studentinfo as $stlist) {
        $current ++;
        $pdf->Cell(10, 10, $current, 1, 0, 'C');
        $pdf->Cell(20, 10, $stlist->idnumber, 1, 0, 'C');
        $x = $pdf->GetX();
        $y = $pdf->GetY();
        $pdf->Image($stlist->picture, $x + 5, $y, 10, 10, "PNG", null, "T", true);
        $pdf->SetXY($x, $y);
        $pdf->Cell(20, 10, "", 1, 0, 'L');
        $pdf->Cell(90, 10, core_text::strtoupper($stlist->name), 1, 0, 'L');
        $pdf->Cell(50, 10, "", 1, 0, 'L');
        $pdf->Ln();
    }
}

/**
 *
 * @package mod
 * @subpackage emarking
 * @copyright 2015 Jorge Villalon <villalon@gmail.com>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
function emarking_upload_answers($emarking, $fileid, $course, $cm, progress_bar $progressbar = null)
{
    global $CFG, $DB;
    
    $context = context_module::instance($cm->id);
    
    // Setup de directorios temporales
    $tempdir = emarking_get_temp_dir_path($emarking->id);
    
    if (! emarking_unzip($fileid, $tempdir . "/")) {
        return array(
            false,
            get_string('errorprocessingextraction', 'mod_emarking'),
            0,
            0
        );
    }
    
    $totalDocumentsProcessed = 0;
    $totalDocumentsIgnored = 0;
    
    // Read full directory, then start processing
    $files = scandir($tempdir);
    
    $doubleside = false;
    
    $pdfFiles = array();
    foreach ($files as $fileInTemp) {
        if (! is_dir($fileInTemp) && strtolower(substr($fileInTemp, - 4, 4)) === ".png") {
            $pdfFiles[] = $fileInTemp;
            if (strtolower(substr($fileInTemp, - 5, 5)) === "b.png") {
                $doubleside = true;
            }
        }
    }
    
    $total = count($pdfFiles);
    
    if ($total == 0) {
        return array(
            false,
            get_string('nopagestoprocess', 'mod_emarking'),
            0,
            0
        );
    }
    
    // Process files
    for ($current = 0; $current < $total; $current ++) {
        
        $file = $pdfFiles[$current];
        
        $filename = explode(".", $file);
        
        $updatemessage = $filename;
        
        if ($progressbar) {
            $progressbar->update($current, $total, $updatemessage);
        }
        
        $parts = explode("-", $filename[0]);
        if (count($parts) != 3) {
            if ($CFG->debug)
                echo "Ignoring $file as it has invalid name";
            $totalDocumentsIgnored ++;
            continue;
        }
        
        $studentid = $parts[0];
        $courseid = $parts[1];
        $pagenumber = $parts[2];
        
        // Now we process the files according to the emarking type
        if ($emarking->type == EMARKING_TYPE_NORMAL) {
            
            if (! $student = $DB->get_record('user', array(
                'id' => $studentid
            ))) {
                $totalDocumentsIgnored ++;
                continue;
            }
            
            if ($courseid != $course->id) {
                $totalDocumentsIgnored ++;
                continue;
            }
        } else {
            $student = new stdClass();
            $student->id = $studentid;
        }
        
        // 1 pasa a 1 1 * 2 - 1 = 1
        // 1b pasa a 2 1 * 2
        // 2 pasa a 3 2 * 2 -1 = 3
        // 2b pasa a 4 2 * 2
        $anonymouspage = false;
        // First clean the page number if it's anonymous
        if (substr($pagenumber, - 2) === "_a") {
            $pagenumber = substr($pagenumber, 0, strlen($pagenumber) - 2);
            $anonymouspage = true;
        }
        
        if ($doubleside) {
            if (substr($pagenumber, - 1) === "b") { // Detecta b
                $pagenumber = intval($pagenumber) * 2;
            } else {
                $pagenumber = intval($pagenumber) * 2 - 1;
            }
        }
        
        if ($anonymouspage) {
            continue;
        }
        
        if (! is_numeric($pagenumber)) {
            if ($CFG->debug) {
                echo "Ignored file: $filename[0] page: $pagenumber student id: $studentid course id: $courseid";
            }
            $totalDocumentsIgnored ++;
            continue;
        }
        
        if (emarking_submit($emarking, $context, $tempdir, $file, $student, $pagenumber)) {
            $totalDocumentsProcessed ++;
        } else {
            return array(
                false,
                get_string('invalidzipnoanonymous', 'mod_emarking'),
                $totalDocumentsProcessed,
                $totalDocumentsIgnored
            );
        }
    }
    
    return array(
        true,
        get_string('invalidpdfnopages', 'mod_emarking'),
        $totalDocumentsProcessed,
        $totalDocumentsIgnored
    );
}

/**
 * Uploads a PDF file as a student's submission for a specific assignment
 *
 * @param object $emarking
 *            the assignment object from dbrecord
 * @param unknown_type $context
 *            the coursemodule
 * @param unknown_type $course
 *            the course object
 * @param unknown_type $path            
 * @param unknown_type $filename            
 * @param unknown_type $student            
 * @param unknown_type $numpages            
 * @param unknown_type $merge            
 * @return boolean
 */
// exportado y cambiado
function emarking_submit($emarking, $context, $path, $filename, $student, $pagenumber = 0)
{
    global $DB, $USER, $CFG;
    
    // All libraries for grading
    require_once ("$CFG->dirroot/grade/grading/lib.php");
    require_once $CFG->dirroot . '/grade/lib.php';
    require_once ("$CFG->dirroot/grade/grading/form/rubric/lib.php");
    
    // Calculate anonymous file name from original file name
    $filenameparts = explode(".", $filename);
    $anonymousfilename = $filenameparts[0] . "_a." . $filenameparts[1];
    
    // Verify that both image files (anonymous and original) exist
    if (! file_exists($path . "/" . $filename) || ! file_exists($path . "/" . $anonymousfilename)) {
        return false;
    }
    
    if (! $student)
        return false;
        
        // Filesystem
    $fs = get_file_storage();
    
    $userid = isset($student->firstname) ? $student->id : $USER->id;
    $author = isset($student->firstname) ? $student->firstname . ' ' . $student->lastname : $USER->firstname . ' ' . $USER->lastname;
    
    // Copy file from temp folder to Moodle's filesystem
    $file_record = array(
        'contextid' => $context->id,
        'component' => 'mod_emarking',
        'filearea' => 'pages',
        'itemid' => $emarking->id,
        'filepath' => '/',
        'filename' => $filename,
        'timecreated' => time(),
        'timemodified' => time(),
        'userid' => $userid,
        'author' => $author,
        'license' => 'allrightsreserved'
    );
    
    // If the file already exists we delete it
    if ($fs->file_exists($context->id, 'mod_emarking', 'pages', $emarking->id, '/', $filename)) {
        $previousfile = $fs->get_file($context->id, 'mod_emarking', 'pages', $emarking->id, '/', $filename);
        $previousfile->delete();
    }
    
    // Info for the new file
    $fileinfo = $fs->create_file_from_pathname($file_record, $path . '/' . $filename);
    
    // Now copying the anonymous version of the file
    $file_record['filename'] = $anonymousfilename;
    
    // Check if anoymous file exists and delete it
    if ($fs->file_exists($context->id, 'mod_emarking', 'pages', $emarking->id, '/', $anonymousfilename)) {
        $previousfile = $fs->get_file($context->id, 'mod_emarking', 'pages', $emarking->id, '/', $anonymousfilename);
        $previousfile->delete();
    }
    
    $fileinfoanonymous = $fs->create_file_from_pathname($file_record, $path . '/' . $anonymousfilename);
    
    $submission = emarking_get_or_create_submission($emarking, $student, $context);
    
    // Get the page from previous uploads. If exists update it, if not insert a new page
    $page = $DB->get_record('emarking_page', array(
        'submission' => $submission->id,
        'student' => $student->id,
        'page' => $pagenumber
    ));
    
    if ($page != null) {
        $page->file = $fileinfo->get_id();
        $page->fileanonymous = $fileinfoanonymous->get_id();
        $page->timemodified = time();
        $page->teacher = $USER->id;
        $DB->update_record('emarking_page', $page);
    } else {
        $page = new stdClass();
        $page->student = $student->id;
        $page->page = $pagenumber;
        $page->file = $fileinfo->get_id();
        $page->fileanonymous = $fileinfoanonymous->get_id();
        $page->submission = $submission->id;
        $page->timecreated = time();
        $page->timemodified = time();
        $page->teacher = $USER->id;
        
        $page->id = $DB->insert_record('emarking_page', $page);
    }
    
    // Update submission info
    $submission->teacher = $page->teacher;
    $submission->timemodified = $page->timemodified;
    $DB->update_record('emarking_submission', $submission);
    
    return true;
}

/**
 * Esta funcion copia el archivo solicitado mediante el Hash (lo busca en la base de datos) en la carpeta temporal especificada.
 *
 * @param String $tempdir
 *            Carpeta a la cual queremos copiar el archivo
 * @param String $hash
 *            hash del archivo en base de datos
 * @param String $prefix
 *            ???
 * @return mixed
 */
// exportado y cambiado
function emarking_get_path_from_hash($tempdir, $hash, $prefix = '', $create = true)
{
    global $CFG;
    
    // Obtiene filesystem
    $fs = get_file_storage();
    
    // Obtiene archivo gracias al hash
    if (! $file = $fs->get_file_by_hash($hash)) {
        return false;
    }
    
    // Se copia archivo desde Moodle a temporal
    $newfile = emarking_clean_filename($tempdir . '/' . $prefix . $file->get_filename());
    
    $file->copy_content_to($newfile);
    
    return $newfile;
}

/**
 * Counts files in dir using an optional suffix
 *
 * @param unknown $dir
 *            Folder to count files from
 * @param string $suffix
 *            File extension to filter
 */
function emarking_count_files_in_dir($dir, $suffix = ".pdf")
{
    return count(emarking_get_files_list($dir, $suffix));
}

/**
 * Gets a list of files filtered by extension from a folder
 *
 * @param unknown $dir
 *            Folder
 * @param string $suffix
 *            Extension to filter
 * @return multitype:unknown Array of filenames
 */
function emarking_get_files_list($dir, $suffix = ".pdf")
{
    $files = scandir($dir);
    $cleanfiles = array();
    
    foreach ($files as $filename) {
        if (! is_dir($filename) && substr($filename, - 4, 4) === $suffix)
            $cleanfiles[] = $filename;
    }
    
    return $cleanfiles;
}

/**
 * Calculates the total number of pages an exam will have for printing statistics
 * according to extra sheets, extra exams and if it has a personalized header and
 * if it uses the backside
 *
 * @param unknown $exam
 *            the exam object
 * @param unknown $numpages
 *            total pages in document
 * @return number total pages to print
 */
function emarking_exam_total_pages_to_print($exam)
{
    if (! $exam)
        return 0;
    
    $total = $exam->totalpages + $exam->extrasheets;
    if ($exam->totalstudents > 0) {
        $total = $total * ($exam->totalstudents + $exam->extraexams);
    }
    if ($exam->usebackside) {
        $total = $total / 2;
    }
    return $total;
}

/**
 *
 *
 *
 * Send email with the downloading code.
 *
 * @param unknown_type $code            
 * @param unknown_type $user            
 * @param unknown_type $coursename            
 * @param unknown_type $examname            
 */
function emarking_send_email_code($code, $user, $coursename, $examname)
{
    global $CFG;
    
    $posttext = get_string('emarkingsecuritycode', 'mod_emarking') . '\n'; // TODO: Internacionalizar
    $posttext .= $coursename . ' ' . $examname . '\n';
    $posttext .= get_string('yourcodeis', 'mod_emarking') . ': ' . $code . '';
    
    $thismessagehtml = '<html>';
    $thismessagehtml .= '<h3>' . get_string('emarkingsecuritycode', 'mod_emarking') . '</h3>';
    $thismessagehtml .= $coursename . ' ' . $examname . '<br>';
    $thismessagehtml .= get_string('yourcodeis', 'mod_emarking') . ':<br>' . $code . '<br>';
    $thismessagehtml .= '</html>';
    
    $subject = get_string('emarkingsecuritycode', 'mod_emarking');
    
    $headers = "From: $CFG->supportname  \r\n" . "Reply-To: $CFG->noreplyaddress\r\n" . 'Content-Type: text/html; charset="utf-8"' . "\r\n" . 'X-Mailer: PHP/' . phpversion();
    
    $eventdata = new stdClass();
    $eventdata->component = 'mod_emarking';
    $eventdata->name = 'notification';
    $eventdata->userfrom = get_admin();
    $eventdata->userto = $user;
    $eventdata->subject = $subject;
    $eventdata->fullmessage = $posttext;
    $eventdata->fullmessageformat = FORMAT_HTML;
    $eventdata->fullmessagehtml = $thismessagehtml;
    $eventdata->smallmessage = $subject;
    
    $eventdata->notification = 1;
    
    return message_send($eventdata);
}

/**
 * Gets course names for all courses that share the same exam file
 *
 * @param unknown $exam            
 * @return multitype:boolean unknown
 */
function emarking_exam_get_parallels($exam)
{
    global $DB;
    
    // Checking if exam is for multicourse
    $courses = array();
    $canbedeleted = true;
    
    // Find all exams with the same PDF file
    $multi = $DB->get_records('emarking_exams', array(
        'file' => $exam->file
    ), 'course ASC');
    foreach ($multi as $mult) {
        if ($mult->status >= EMARKING_EXAM_SENT_TO_PRINT) {
            $canbedeleted = false;
        }
        if ($mult->id != $exam->id) {
            $shortname = $DB->get_record('course', array(
                'id' => $mult->course
            ));
            $courses[] = $shortname->shortname;
        }
    }
    $multicourse = implode(", ", $courses);
    
    return array(
        $canbedeleted,
        $multicourse
    );
}

/**
 * Creates the PDF version (downloadable) of the whole feedback produced by the teacher/tutor
 *
 * @param unknown $draft            
 * @param unknown $student            
 * @param unknown $context            
 * @param unknown $cmid            
 * @return boolean
 */
function emarking_create_response_pdf($draft, $student, $context, $cmid)
{
    global $CFG, $DB;
    
    require_once $CFG->libdir . '/pdflib.php';
    
    $fs = get_file_storage();
    
    if (! $submission = $DB->get_record('emarking_submission', array(
        'id' => $draft->submissionid
    ))) {
        return false;
    }
    
    if (! $pages = $DB->get_records('emarking_page', array(
        'submission' => $submission->id,
        'student' => $student->id
    ), 'page ASC')) {
        return false;
    }
    
    if (! $emarking = $DB->get_record('emarking', array(
        'id' => $submission->emarking
    )))
        return false;
    
    $numpages = count($pages);
    
    $sqlcomments = "SELECT ec.id,
			ec.posx,
			ec.posy,
			ec.rawtext,
			ec.pageno,
			grm.maxscore,
			ec.levelid,
			ec.width,
			ec.colour,
			ec.textformat,
			grl.score AS score,
			grl.definition AS leveldesc,
			grc.id AS criterionid,
			grc.description AS criteriondesc,
			u.id AS markerid, CONCAT(u.firstname,' ',u.lastname) AS markername
			FROM {emarking_comment} AS ec
			INNER JOIN {emarking_page} AS ep ON (ec.draft = :draft AND ec.page = ep.id)
			LEFT JOIN {user} AS u ON (ec.markerid = u.id)
			LEFT JOIN {gradingform_rubric_levels} AS grl ON (ec.levelid = grl.id)
			LEFT JOIN {gradingform_rubric_criteria} AS grc ON (grl.criterionid = grc.id)
			LEFT JOIN (
			SELECT grl.criterionid, max(score) AS maxscore
			FROM {gradingform_rubric_levels} AS grl
			GROUP BY grl.criterionid
			) AS grm ON (grc.id = grm.criterionid)
			WHERE ec.pageno > 0
			ORDER BY ec.pageno";
    $params = array(
        'draft' => $draft->id
    );
    $comments = $DB->get_records_sql($sqlcomments, $params);
    
    $commentsperpage = array();
    
    foreach ($comments as $comment) {
        if (! isset($commentsperpage[$comment->pageno])) {
            $commentsperpage[$comment->pageno] = array();
        }
        
        $commentsperpage[$comment->pageno][] = $comment;
    }
    
    // Parameters for PDF generation
    $iconsize = 5;
    
    $tempdir = emarking_get_temp_dir_path($emarking->id);
    if (! file_exists($tempdir)) {
        mkdir($tempdir);
    }
    
    // create new PDF document
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    
    // set document information
    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor($student->firstname . ' ' . $student->lastname);
    $pdf->SetTitle($emarking->name);
    $pdf->SetSubject('Exam feedback');
    $pdf->SetKeywords('feedback, emarking');
    $pdf->SetPrintHeader(false);
    $pdf->SetPrintFooter(false);
    
    // set default header data
    $pdf->SetHeaderData(PDF_HEADER_LOGO, PDF_HEADER_LOGO_WIDTH, PDF_HEADER_TITLE . ' 036', PDF_HEADER_STRING);
    
    // set header and footer fonts
    $pdf->setHeaderFont(Array(
        PDF_FONT_NAME_MAIN,
        '',
        PDF_FONT_SIZE_MAIN
    ));
    $pdf->setFooterFont(Array(
        PDF_FONT_NAME_DATA,
        '',
        PDF_FONT_SIZE_DATA
    ));
    
    // set default monospaced font
    $pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
    
    // set margins
    $pdf->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP, PDF_MARGIN_RIGHT);
    $pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
    $pdf->SetFooterMargin(PDF_MARGIN_FOOTER);
    
    // set auto page breaks
    $pdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);
    
    // set image scale factor
    $pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);
    
    // set some language-dependent strings (optional)
    if (@file_exists(dirname(__FILE__) . '/lang/eng.php')) {
        require_once (dirname(__FILE__) . '/lang/eng.php');
        $pdf->setLanguageArray($l);
    }
    
    // ---------------------------------------------------------
    
    // set font
    $pdf->SetFont('times', '', 16);
    
    foreach ($pages as $page) {
        // add a page
        $pdf->AddPage();
        
        // get the current page break margin
        $bMargin = $pdf->getBreakMargin();
        // get current auto-page-break mode
        $auto_page_break = $pdf->getAutoPageBreak();
        // disable auto-page-break
        $pdf->SetAutoPageBreak(false, 0);
        // set bacground image
        $pngfile = $fs->get_file_by_id($page->file);
        $img_file = emarking_get_path_from_hash($tempdir, $pngfile->get_pathnamehash());
        $pdf->Image($img_file, 0, 0, 210, 297, '', '', '', false, 300, '', false, false, 0);
        // restore auto-page-break status
        // $pdf->SetAutoPageBreak($auto_page_break, $bMargin);
        // set the starting point for the page content
        $pdf->setPageMark();
        
        $dimensions = $pdf->getPageDimensions();
        
        if (isset($commentsperpage[$page->page])) {
            foreach ($commentsperpage[$page->page] as $comment) {
                
                $content = $comment->rawtext;
                $posx = (int) (((float) $comment->posx) * $dimensions['w']);
                $posy = (int) (((float) $comment->posy) * $dimensions['h']);
                
                if ($comment->textformat == 1) {
                    // text annotation
                    $pdf->Annotation($posx, $posy, 6, 6, $content, array(
                        'Subtype' => 'Text',
                        'StateModel' => 'Review',
                        'State' => 'None',
                        'Name' => 'Comment',
                        'NM' => 'Comment' . $comment->id,
                        'T' => $comment->markername,
                        'Subj' => 'example',
                        'C' => array(
                            0,
                            0,
                            255
                        )
                    ));
                } elseif ($comment->textformat == 2) {
                    $content = $comment->criteriondesc . ': ' . round($comment->score, 1) . '/' . round($comment->maxscore, 1) . "\n" . $comment->leveldesc . "\n" . get_string('comment', 'mod_emarking') . ': ' . $content;
                    // text annotation
                    $pdf->Annotation($posx, $posy, 6, 6, $content, array(
                        'Subtype' => 'Text',
                        'StateModel' => 'Review',
                        'State' => 'None',
                        'Name' => 'Comment',
                        'NM' => 'Mark' . $comment->id,
                        'T' => $comment->markername,
                        'Subj' => 'grade',
                        'C' => array(
                            255,
                            255,
                            0
                        )
                    ));
                } elseif ($comment->textformat == 3) {
                    $pdf->Image($CFG->dirroot . "/mod/emarking/img/check.gif", $posx, $posy, $iconsize, $iconsize, '', '', '', false, 300, '', false, false, 0);
                } elseif ($comment->textformat == 4) {
                    $pdf->Image($CFG->dirroot . "/mod/emarking/img/crossed.gif", $posx, $posy, $iconsize, $iconsize, '', '', '', false, 300, '', false, false, 0);
                }
            }
        }
    }
    // ---------------------------------------------------------
    
    // COGIDO PARA IMPRIMIR RÃšBRICA
    if ($emarking->downloadrubricpdf) {
        
        $cm = new StdClass();
        
        $rubricdesc = $DB->get_recordset_sql("SELECT
		d.name AS rubricname,
		a.id AS criterionid,
		a.description ,
		b.definition,
		b.id AS levelid,
		b.score,
		IFNULL(E.id,0) AS commentid,
		IFNULL(E.pageno,0) AS commentpage,
		E.rawtext AS commenttext,
		E.markerid AS markerid,
		IFNULL(E.textformat,2) AS commentformat,
		IFNULL(E.bonus,0) AS bonus,
		IFNULL(er.id,0) AS regradeid,
		IFNULL(er.motive,0) AS motive,
		er.comment AS regradecomment,
		IFNULL(er.markercomment, '') AS regrademarkercomment,
		IFNULL(er.accepted,0) AS regradeaccepted
		FROM {course_modules} AS c
		INNER JOIN {context} AS mc ON (c.id = :coursemodule AND c.id = mc.instanceid)
		INNER JOIN {grading_areas} AS ar ON (mc.id = ar.contextid)
		INNER JOIN {grading_definitions} AS d ON (ar.id = d.areaid)
		INNER JOIN {gradingform_rubric_criteria} AS a ON (d.id = a.definitionid)
		INNER JOIN {gradingform_rubric_levels} AS b ON (a.id = b.criterionid)
		LEFT JOIN (
		SELECT ec.*, d.id AS draftid
		FROM {emarking_comment} AS ec
		INNER JOIN {emarking_draft} AS d ON (d.id = :draft AND ec.draft = d.id)
		) AS E ON (E.levelid = b.id)
		LEFT JOIN {emarking_regrade} AS er ON (er.criterion = a.id AND er.draft = E.draftid)
		ORDER BY a.sortorder ASC, b.score ASC", array(
            'coursemodule' => $cmid,
            'draft' => $draft->id
        ));
        
        $table = new html_table();
        $data = array();
        foreach ($rubricdesc as $rd) {
            if (! isset($data[$rd->criterionid])) {
                $data[$rd->criterionid] = array(
                    $rd->description,
                    $rd->definition . " (" . round($rd->score, 2) . " ptos. )"
                );
            } else {
                array_push($data[$rd->criterionid], $rd->definition . " (" . round($rd->score, 2) . " ptos. )");
            }
        }
        $table->data = $data;
        
        // add extra page with rubrics
        $pdf->AddPage();
        $pdf->Write(0, 'RÃºbrica', '', 0, 'L', true, 0, false, false, 0);
        $pdf->SetFont('helvetica', '', 8);
        
        $tbl = html_writer::table($table);
        
        $pdf->writeHTML($tbl, true, false, false, false, '');
    }
    // ---------------------------------------------------------
    
    $pdffilename = 'response_' . $emarking->id . '_' . $draft->id . '.pdf';
    $pathname = $tempdir . '/' . $pdffilename;
    
    if (@file_exists($pathname)) {
        unlink($pathname);
    }
    
    // Close and output PDF document
    $pdf->Output($pathname, 'F');
    
    // Copiar archivo desde temp a Ã�rea
    $file_record = array(
        'contextid' => $context->id,
        'component' => 'mod_emarking',
        'filearea' => 'response',
        'itemid' => $student->id,
        'filepath' => '/',
        'filename' => $pdffilename,
        'timecreated' => time(),
        'timemodified' => time(),
        'userid' => $student->id,
        'author' => $student->firstname . ' ' . $student->lastname,
        'license' => 'allrightsreserved'
    );
    
    // Si el archivo ya existía entonces lo borramos
    if ($fs->file_exists($context->id, 'mod_emarking', 'response', $student->id, '/', $pdffilename)) {
        $previousfile = $fs->get_file($context->id, 'mod_emarking', 'response', $student->id, '/', $pdffilename);
        $previousfile->delete();
    }
    
    $fileinfo = $fs->create_file_from_pathname($file_record, $pathname);
    
    return true;
}

/**
 * Creates a personalized exam file.
 *
 * @param unknown $examid            
 * @return NULL
 */
function emarking_download_exam($examid, $multiplepdfs = false, $groupid = null, progress_bar $pbar = null, $sendprintorder = false, $printername = null, $printanswersheet = false, $debugprinting = false)
{
    global $DB, $CFG, $USER, $OUTPUT;
    require_once ($CFG->dirroot . '/mod/emarking/lib/openbub/ans_pdf_open.php');
    
    // Validate emarking exam object
    if (! $downloadexam = $DB->get_record('emarking_exams', array(
        'id' => $examid
    ))) {
        throw new Exception('Invalid exam');
    }
    
    // Contexto del curso para verificar permisos
    $context = context_course::instance($downloadexam->course);
    
    if (! has_capability('mod/emarking:downloadexam', $context)) {
        throw new Exception('Capability problem, user cannot download exam');
    }
    
    // Verify that remote printing is enable, otherwise disable a printing order
    if ($sendprintorder && (! $CFG->emarking_enableprinting || $printername == null)) {
        throw new Exception('Printing is not enabled or printername was absent ' . $printername);
    }
    
    // Validate course
    if (! $course = $DB->get_record('course', array(
        'id' => $downloadexam->course
    ))) {
        throw new Exception('Invalid course');
    }
    
    // Validate course category
    if (! $coursecat = $DB->get_record('course_categories', array(
        'id' => $course->category
    ))) {
        throw new Exception('Invalid course category');
    }
    
    // We tell the user we are setting up the printing
    if ($pbar) {
        $pbar->update(0, 1, get_string('settingupprinting', 'mod_emarking'));
    }
    
    // Default value for enrols that will be included
    if ($CFG->emarking_enrolincludes && strlen($CFG->emarking_enrolincludes) > 1) {
        $enrolincludes = $CFG->emarking_enrolincludes;
    }
    
    // If the exam sets enrolments, we use those
    if (isset($downloadexam->enrolments) && strlen($downloadexam->enrolments) > 1) {
        $enrolincludes = $downloadexam->enrolments;
    }
    
    // Convert enrolments to array
    $enrolincludes = explode(",", $enrolincludes);
    
    // Produce all PDFs first separatedly
    $filedir = $CFG->dataroot . "/temp/emarking/$context->id";
    $fileimg = $filedir . "/qr";
    $userimgdir = $filedir . "/u";
    $pdfdir = $filedir . "/pdf";
    
    emarking_initialize_directory($filedir, true);
    emarking_initialize_directory($fileimg, true);
    emarking_initialize_directory($userimgdir, true);
    emarking_initialize_directory($pdfdir, true);
    
    // Get all the files uploaded as forms for this exam
    $fs = get_file_storage();
    $files = $fs->get_area_files($context->id, 'mod_emarking', 'exams', $examid);
    
    // We filter only the PDFs
    $pdffileshash = array();
    foreach ($files as $filepdf) {
        if ($filepdf->get_mimetype() === 'application/pdf') {
            $pdffileshash[] = array(
                'hash' => $filepdf->get_pathnamehash(),
                'filename' => $filepdf->get_filename(),
                'path' => emarking_get_path_from_hash($filedir, $filepdf->get_pathnamehash())
            );
        }
    }
    
    // Verify that at least we have a PDF
    if (count($pdffileshash) < 1) {
        throw new Exception('Exam id has no PDF associated. This is a terrible error, please notify the administrator.');
    }
    
    $students = emarking_get_students_for_printing($downloadexam->course);
    
    $studentinfo = array();
    
    $current = 0;
    // Fill studentnames with student info (name, idnumber, id and picture)
    foreach ($students as $student) {
        
        // Verifies that the student is enrolled through a valid enrolment and that we haven't added her yet
        if (array_search($student->enrol, $enrolincludes) === false || isset($studentinfo[$student->id])) {
            continue;
        }
        
        // We create a student info object
        $stinfo = new stdClass();
        $stinfo->name = substr("$student->lastname, $student->firstname", 0, 65);
        $stinfo->idnumber = $student->idnumber;
        $stinfo->id = $student->id;
        $stinfo->picture = emarking_get_student_picture($student, $userimgdir);
        
        // Store student info
        $studentinfo[$student->id] = $stinfo;
    }
    
    // We validate the number of students as we are filtering by enrolment
    // type after getting the data
    $numberstudents = count($studentinfo);
    
    if ($numberstudents == 0) {
        throw new Exception('No students to print/create the exam');
    }
    
    // Add the extra students to the list
    for ($i = $numberstudents; $i < $numberstudents + $downloadexam->extraexams; $i ++) {
        $stinfo = new stdClass();
        $stinfo->name = '..............................................................................';
        $stinfo->idnumber = 0;
        $stinfo->id = 0;
        $stinfo->picture = $CFG->dirroot . "/pix/u/f1.png";
        $studentinfo[] = $stinfo;
    }
    
    // Check if there is a logo file
    $logofilepath = emarking_get_logo_file($filedir);
    
    // If asked to do so we create a PDF witht the students list
    if ($downloadexam->printlist == 1) {
        $pdf = new FPDI();
        $pdf->SetPrintHeader(false);
        $pdf->SetPrintFooter(false);
        emarking_draw_student_list($pdf, $logofilepath, $downloadexam, $course, $studentinfo);
        $studentlistpdffile = $pdfdir . "/000-studentslist.pdf";
        $pdf->Output($studentlistpdffile, "F"); // se genera el nuevo pdf
        $pdf = null;
    }
    
    // Here we produce a PDF file for each student
    $currentstudent = 0;
    foreach ($studentinfo as $stinfo) {
        
        // If we have a progress bar, we notify the new PDF being created
        if ($pbar) {
            $pbar->update($currentstudent + 1, count($studentinfo), $stinfo->name);
        }
        
        // We create the PDF file
        $pdf = new FPDI();
        $pdf->SetPrintHeader(false);
        $pdf->SetPrintFooter(false);
        
        // We use the next form available from the list of PDF forms sent
        if ($current >= count($pdffileshash) - 1) {
            $current = 0;
        } else {
            $current ++;
        }
        
        // Load the PDF from the filesystem as template
        $path = $pdffileshash[$current]['path'];
        $originalpdfpages = $pdf->setSourceFile($path);
        
        $pdf->SetAutoPageBreak(false);
        
        // Add all pages in the template, adding the header if it corresponds
        for ($pagenumber = 1; $pagenumber <= $originalpdfpages + $downloadexam->extrasheets; $pagenumber ++) {
            
            // Adding a page
            $pdf->AddPage();
            
            // If the page is not an extra page, we import the page from the template
            if ($pagenumber <= $originalpdfpages) {
                $template = $pdf->importPage($pagenumber);
                $pdf->useTemplate($template, 0, 0, 0, 0, true);
            }
            
            // If we have a personalized header, we add it
            if ($downloadexam->headerqr) {
                emarking_draw_header($pdf, $stinfo, $downloadexam->name, $pagenumber, $fileimg, $logofilepath, $course, $originalpdfpages);
            }
        }
        
        // The filename will be the student id - course id - page number
        $qrstringtmp = "$stinfo->id-$course->id-$pagenumber";
        
        // Create the PDF file for the student
        $pdffile = $pdfdir . "/" . $qrstringtmp . ".pdf";
        $pdf->Output($pdffile, "F");
        
        // Store the exam file for printing later
        $stinfo->examfile = $pdffile;
        $stinfo->number = $currentstudent + 1;
        $stinfo->pdffilename = $qrstringtmp;
        
        $currentstudent ++;
    }
    
    // If we have to print directly
    $debugprintingmsg = '';
    if ($sendprintorder) {
        
        // Check if we have to print the students list
        if ($downloadexam->printlist == 1) {
            $printresult = emarking_print_file($printername, $studentlistpdffile, $debugprinting);
            if (! $printresult) {
                $debugprintingmsg .= 'Problems printing ' . $studentlistpdffile . '<hr>';
            } else {
                $debugprintingmsg .= $printresult . '<hr>';
            }
        }
        
        // Print each student
        $currentstudent = 0;
        foreach ($studentinfo as $stinfo) {
            $currentstudent ++;
            
            if ($pbar != null) {
                $pbar->update($currentstudent, count($studentinfo), get_string('printing', 'mod_emarking') . ' ' . $stinfo->name);
            }
            
            if (! isset($stinfo->examfile) || ! file_exists($stinfo->examfile)) {
                continue;
            }
            
            $printresult = emarking_print_file($printername, $stinfo->examfile, $debugprinting);
            if (! $printresult) {
                $debugprintingmsg .= 'Problems printing ' . $stinfo->examfile . '<hr>';
            } else {
                $debugprintingmsg .= $printresult . '<hr>';
            }
        }
        
        if ($CFG->debug || $debugprinting) {
            echo $debugprintingmsg;
        }
        
        $downloadexam->status = EMARKING_EXAM_SENT_TO_PRINT;
        $downloadexam->printdate = time();
        $DB->update_record('emarking_exams', $downloadexam);
        
        return true;
    }
    
    $examfilename = emarking_clean_filename($course->shortname, true) . "_" . emarking_clean_filename($downloadexam->name, true);
    
    $zipdebugmsg = '';
    if ($multiplepdfs) {
        $zip = new ZipArchive();
        $zipfilename = $filedir . "/" . $examfilename . ".zip";
        
        if ($zip->open($zipfilename, ZipArchive::CREATE) !== true) {
            throw new Exception('Could not create zip file');
        }
        
        // Check if we have to print the students list
        if ($downloadexam->printlist == 1) {
            $zip->addFile($studentlistpdffile);
        }
        
        // Add every student PDF to zip file
        $currentstudent = 0;
        foreach ($studentinfo as $stinfo) {
            $currentstudent ++;
            
            if ($pbar != null) {
                $pbar->update($currentstudent, count($studentinfo), get_string('printing', 'mod_emarking') . ' ' . $stinfo->name);
            }
            
            if (! isset($stinfo->examfile) || ! file_exists($stinfo->examfile)) {
                continue;
            }
            
            if (! $zip->addFile($stinfo->examfile, $stinfo->pdffilename . '.pdf')) {
                $zipdebugmsg .= "Problems adding $stinfo->examfile to ZIP file using name $stinfo->pdffilename <hr>";
            }
        }
        
        $zip->close();
        
        if ($CFG->debug || $debugprinting) {
            echo $zipdebugmsg;
        }
        
        $downloadexam->status = EMARKING_EXAM_SENT_TO_PRINT;
        $downloadexam->printdate = time();
        $DB->update_record('emarking_exams', $downloadexam);
        
        // Read zip file from disk and send to the browser
        $file_name = basename($zipfilename);
        
        header("Content-Type: application/zip");
        header("Content-Disposition: attachment; filename=" . $examfilename . ".zip");
        header("Content-Length: " . filesize($zipfilename));
        
        readfile($zipfilename);
        exit();
    }
    
    // We create the final big PDF file
    $pdf = new FPDI();
    $pdf->SetPrintHeader(false);
    $pdf->SetPrintFooter(false);
    
    // We import the students list if required
    if ($downloadexam->printlist) {
        emarking_import_pdf_into_pdf($pdf, $studentlistpdffile);
    }
    
    // Add every student PDF to zip file
    $currentstudent = 0;
    foreach ($studentinfo as $stinfo) {
        $currentstudent ++;
        
        if (! isset($stinfo->examfile) || ! file_exists($stinfo->examfile)) {
            continue;
        }
        
        emarking_import_pdf_into_pdf($pdf, $stinfo->examfile);
    }
    
    $downloadexam->status = EMARKING_EXAM_SENT_TO_PRINT;
    $downloadexam->printdate = time();
    $DB->update_record('emarking_exams', $downloadexam);
    
    $pdf->Output($examfilename . '.pdf', 'D');
}

function emarking_import_pdf_into_pdf(FPDI $pdf, $pdftoimport)
{
    $originalpdfpages = $pdf->setSourceFile($pdftoimport);
    
    $pdf->SetAutoPageBreak(false);
    
    // Add all pages in the template, adding the header if it corresponds
    for ($pagenumber = 1; $pagenumber <= $originalpdfpages; $pagenumber ++) {
        // Adding a page
        $pdf->AddPage();
        $template = $pdf->importPage($pagenumber);
        $pdf->useTemplate($template, 0, 0, 0, 0, true);
    }
}

function emarking_print_file($printername, $file, $debugprinting)
{
    global $CFG;
    
    if (! $printername)
        return null;
    
    if ($printername === "Edificio-C-mesonSecretaria") {
        $command = "lp -d " . $printername . " -o StapleLocation=SinglePortrait -o PageSize=Letter -o Duplex=none " . $file;
    } else {
        $command = "lp -d " . $printername . " -o StapleLocation=UpperLeft -o fit-to-page -o media=Letter " . $file;
    }
    
    $printresult = null;
    if (! $debugprinting) {
        $printresult = exec($command);
    }
    
    if ($CFG->debug || $debugprinting) {
        $printresult .= "$command <br>";
    }
    
    return $printresult;
}

function emarking_draw_header($pdf, $stinfo, $examname, $pagenumber, $fileimgpath, $logofilepath, $course, $totalpages = null, $bottomqr = true, $isanswersheet = false, $attemptid = 0)
{
    global $CFG;
    
    $pdf->SetAutoPageBreak(false);
    
    // For the QR string and get the images
    $qrstring = "$stinfo->id-$course->id-$pagenumber";
    if ($isanswersheet && $attemptid > 0) {
        $qrstring .= '-' . $attemptid . '-BB';
    }
    list ($img, $imgrotated) = emarking_create_qr_image($fileimgpath, $qrstring, $stinfo, $pagenumber);
    
    if ($CFG->emarking_includelogo && $logofilepath) {
        $pdf->Image($logofilepath, 2, 8, 30);
    }
    
    $left = 58;
    $top = 8;
    $pdf->SetFont('Helvetica', '', 12);
    $pdf->SetXY($left, $top);
    $pdf->Write(1, core_text::strtoupper($examname));
    $pdf->SetFont('Helvetica', '', 9);
    $top += 5;
    $pdf->SetXY($left, $top);
    $pdf->Write(1, core_text::strtoupper(get_string('name') . ": " . $stinfo->name));
    $top += 4;
    if ($stinfo->idnumber && strlen($stinfo->idnumber) > 0) {
        $pdf->SetXY($left, $top);
        $pdf->Write(1, get_string('idnumber', 'mod_emarking') . ": " . $stinfo->idnumber);
        $top += 4;
    }
    $pdf->SetXY($left, $top);
    $pdf->Write(1, core_text::strtoupper(get_string('course') . ": " . $course->fullname));
    $top += 4;
    if (file_exists($stinfo->picture)) {
        $pdf->Image($stinfo->picture, 35, 8, 15, 15, "PNG", null, "T", true);
    }
    if ($totalpages) {
        $totals = new stdClass();
        $totals->identified = $pagenumber;
        $totals->total = $totalpages;
        $pdf->SetXY($left, $top);
        $pdf->Write(1, core_text::strtoupper(get_string('page') . ": " . get_string('aofb', 'mod_emarking', $totals)));
    }
    $pdf->Image($img, 176, 3, 34); // y antes era -2
    if ($bottomqr) {
        $pdf->Image($imgrotated, 0, $pdf->getPageHeight() - 35, 34);
    }
    unlink($img);
    unlink($imgrotated);
}

function emarking_create_qr_image($fileimg, $qrstring, $stinfo, $i)
{
    global $CFG;
    require_once ($CFG->dirroot . '/mod/emarking/lib/phpqrcode/phpqrcode.php');
    
    $h = random_string(15);
    $hash = random_string(15);
    $img = $fileimg . "/qr" . $h . "_" . $stinfo->idnumber . "_" . $i . "_" . $hash . ".png";
    $imgrotated = $fileimg . "/qr" . $h . "_" . $stinfo->idnumber . "_" . $i . "_" . $hash . "r.png";
    // Se genera QR con id, curso y número de página
    QRcode::png($qrstring, $img); // se inserta QR
    QRcode::png($qrstring . "-R", $imgrotated); // se inserta QR
    $gdimg = imagecreatefrompng($imgrotated);
    $rotated = imagerotate($gdimg, 180, 0);
    imagepng($rotated, $imgrotated);
    
    return array(
        $img,
        $imgrotated
    );
}

/**
 * Erraces all the content of a directory, then ir creates te if they don't exist.
 *
 * @param unknown $dir
 *            Directorio
 * @param unknown $delete
 *            Borrar archivos previamente
 */
function emarking_initialize_directory($dir, $delete)
{
    if ($delete) {
        // First erase all files
        if (is_dir($dir)) {
            emarking_rrmdir($dir);
        }
    }
    
    // Si no existe carpeta para temporales se crea
    if (! is_dir($dir)) {
        if (! mkdir($dir, 0777, true)) {
            print_error(get_string('initializedirfail', 'mod_emarking', $dir));
        }
    }
}

/**
 * Recursively remove a directory.
 * Enter description here ...
 *
 * @param unknown_type $dir            
 */
function emarking_rrmdir($dir)
{
    foreach (glob($dir . '/*') as $file) {
        if (is_dir($file))
            emarking_rrmdir($file);
        else
            unlink($file);
    }
    rmdir($dir);
}

/**
 * Sends an sms message using UAI's service with infobip.com.
 * Returns true if successful, false otherwise.
 *
 * @param string $message
 *            the message to be sent
 * @param string $number
 *            the mobile number
 */
function emarking_send_sms($message, $number)
{
    global $CFG;
    
    $postUrl = $CFG->emarking_smsurl;
    
    $xmlString = "<SMS>
	<authentification>
	<username>$CFG->emarking_smsuser</username>
	<password>$CFG->emarking_smspassword</password>
	</authentification>
	<message>
	<sender>Webcursos</sender>
	<text>$message</text>
	<recipients>
	<gsm>$number</gsm>
	</recipients>
	</message>

	</SMS>";
    
    // previamente formateado en XML
    $fields = "XML=" . urlencode($xmlString);
    
    // Se require cURL
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $postUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    // Respuesta del POST
    $response = curl_exec($ch);
    curl_close($ch);
    
    if (! $response) {
        return false;
    }
    
    try {
        $xml = new SimpleXmlElement($response);
    } catch (exception $e) {
        return false;
    }
    
    if ($xml && $xml->status == 1) {
        return true;
    }
    
    return false;
}

/**
 * Replace "acentos", spaces from file names.
 * Evita problemas en Windows y Linux.
 *
 * @param unknown $filename
 *            El nombre original del archivo
 * @return unknown El nombre sin acentos, espacios.
 */
function emarking_clean_filename($filename, $slash = false)
{
    $replace = array(
        ' ',
        'á',
        'é',
        'í',
        'ó',
        'ú',
        'ñ',
        'Ñ',
        'Á',
        'É',
        'Í',
        'Ó',
        'Ú',
        '(',
        ')',
        ','
    );
    $replacefor = array(
        '-',
        'a',
        'e',
        'i',
        'o',
        'u',
        'n',
        'N',
        'A',
        'E',
        'I',
        'O',
        'U',
        '-',
        '-',
        '-'
    );
    if ($slash) {
        $replace[] = '/';
        $replacefor[] = '-';
    }
    $newfile = str_replace($replace, $replacefor, $filename);
    return $newfile;
}

