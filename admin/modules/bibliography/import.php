<?php
/**
 * Copyright (C) 2009  Arie Nugraha (dicarve@yahoo.com)
 *               2010 Juergen Goegelein (JGoegelein@googlemail.com)
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 *
 */

/* Biblio Import section */

// main system configuration
require '../../../sysconfig.inc.php';
// start the session
require SENAYAN_BASE_DIR.'admin/default/session.inc.php';
require SIMBIO_BASE_DIR.'simbio_GUI/form_maker/simbio_form_table_AJAX.inc.php';
require SIMBIO_BASE_DIR.'simbio_GUI/table/simbio_table.inc.php';
require SIMBIO_BASE_DIR.'simbio_FILE/simbio_file_upload.inc.php';

// privileges checking
$can_read = utility::havePrivilege('bibliography', 'r');
$can_write = utility::havePrivilege('bibliography', 'w');

if (!$can_write) {
    die('<div class="errorBox">'.__('You are not authorized to view this section').'</div>');
}

// max chars in line for file operations
$max_chars = 4096;

if (isset($_POST['doImport'])) {
    // check for form validity
    if (!$_FILES['importFile']['name']) {
        utility::jsAlert(__('Please select the file to import!'));
        exit();
    } else if (empty($_POST['fieldSep']) OR empty($_POST['fieldEnc'])) {
        utility::jsAlert(__('Required fields (*)  must be filled correctly!'));
        exit();
    } else {
        $start_time = time();
        // set PHP time limit
        set_time_limit(0);
        // set ob implicit flush
        ob_implicit_flush();
        // create upload object
        $upload = new simbio_file_upload();
        // get system temporary directory location
        $temp_dir = sys_get_temp_dir();
        $uploaded_file = $temp_dir.DIRECTORY_SEPARATOR.$_FILES['importFile']['name'];
        unlink($uploaded_file);
        // set max size
        $max_size = $sysconf['max_upload']*1024;
        $upload->setAllowableFormat(array('.csv'));
        $upload->setMaxSize($max_size);
        $upload->setUploadDir($temp_dir);
        $upload_status = $upload->doUpload('importFile');
        if ($upload_status != UPLOAD_SUCCESS) {
            utility::jsAlert(__('Upload failed! File type not allowed or the size is more than').' '.($sysconf['max_upload']/1024).' MB'); //mfc
            exit();
        }
        // uploaded file path
        $uploaded_file = $temp_dir.DIRECTORY_SEPARATOR.$_FILES['importFile']['name'];
        $row_count = 0;
        // file encoding
        $file_characterset = ($_POST['fileCharacterset'])?$_POST['fileCharacterset']:'';
        // check for import setting
        $record_num = intval($_POST['recordNum']);
        $field_enc = trim($_POST['fieldEnc']);
        $field_sep = trim($_POST['fieldSep']);
        $record_offset = intval($_POST['recordOffset']);
        $record_offset = ($record_offset > 0)?$record_offset:1;
        // foreign key id cache
        $gmd_id_cache = array();
        $publ_id_cache = array();
        $lang_id_cache = array();
        $place_id_cache = array();
        $author_id_cache = array();
        $subject_id_cache = array();
        // read file line by line
        $cnt_insert = 0;
        $cnt_update = 0;
        $cnt_ignore = 0;
        $cnt_sql_error = 0;
        $file = fopen($uploaded_file, 'r');
        $column_pos = 0;
        $num_fields_expected = 19; //the number of colums we expect in an import file
        while (!feof($file)) {
            // record count
            if ($record_num > 0 AND $row_count == $record_num) {
                break;
            }
            // go to offset
            if ($row_count++ < $record_offset) {
                // pass and continue to next loop
                $field = fgetcsv($file, $max_chars, $field_sep, $field_enc);
                continue;
            } else {
                // get an array of fields
                $field = fgetcsv($file, $max_chars, $field_sep, $field_enc);
                if (!(isset($field)) OR (count($field) <> $num_fields_expected)) {
                    $cnt_ignore++;
                    $row_ignored[]=$row_count;
                    continue;
                }
                // strip escape chars from all fields
                foreach ($field as $idx => $value) {
                    $field[$idx] = str_replace('\\', '', trim($value));
                    $field[$idx] = $dbs->escape_string($field[$idx]);
                    // convert ISO-8859-1 to utf-8 if requested
                    if (strtoupper($file_characterset) == 'ISO-8859-1') {
                        $field[$idx] = utf8_encode($field[$idx]);
                    }
                }
                $column_pos = 0;
                $biblio_id        = $field[$column_pos++];
                $title            = $field[$column_pos++];
                $gmd_name         = $field[$column_pos++];
                $gmd_id           = (empty($gmd_name)?'':utility::getID($dbs, 'mst_gmd', 'gmd_id', 'gmd_name', $gmd_name, $gmd_id_cache));
                $edition          = $field[$column_pos++];
                $isbn_issn        = $field[$column_pos++];
                $publisher_name   = $field[$column_pos++];
                $publisher_id     = (empty($publisher_name)?'':utility::getID($dbs, 'mst_publisher', 'publisher_id', 'publisher_name', $publisher_name, $publ_id_cache));
                $publish_year     = $field[$column_pos++];
                $collation        = $field[$column_pos++];
                $series_title     = $field[$column_pos++];
                $call_number      = $field[$column_pos++];
                $language_name    = $field[$column_pos++];
                $language_id      = (empty($language_name)?'':utility::getID($dbs, 'mst_language', 'language_id', 'language_name', $language_name, $lang_id_cache));
                $place_name       = $field[$column_pos++];
                $publish_place_id = (empty($place_name)?'':utility::getID($dbs, 'mst_place', 'place_id', 'place_name', $place_name, $place_id_cache));
                $classification   = $field[$column_pos++];
                $notes            = $field[$column_pos++];
                $image            = $field[$column_pos++];
                $file_att         = $field[$column_pos++];
                $authors          = $field[$column_pos++];
                $subjects         = $field[$column_pos++];
                $items            = $field[$column_pos++];

                // get current datetime
                $curr_datetime = '\''.date('Y-m-d H:i:s').'\'';

                if (!empty($biblio_id)) {
                    $sql_str = "UPDATE biblio set ";
                    $sql_str.= " title   =".        (empty($title)?           'null':'\''.$dbs->escape_string($title).'\'');
                    $sql_str.= ",gmd_id =".         (empty($gmd_id)?          'null':'\''.$dbs->escape_string($gmd_id).'\'');
                    $sql_str.= ",edition =".        (empty($edition)?         'null':'\''.$dbs->escape_string($edition).'\'');
                    $sql_str.= ",isbn_issn=".       (empty($isbn_issn)?       'null':'\''.$dbs->escape_string($isbn_issn).'\'');
                    $sql_str.= ",publisher_id=".    (empty($publisher_id)?    'null':'\''.$dbs->escape_string($publisher_id).'\'');
                    $sql_str.= ",publish_year=".    (empty($publish_year)?    'null':'\''.$dbs->escape_string($publish_year).'\'');
                    $sql_str.= ",collation=".       (empty($collation)?       'null':'\''.$dbs->escape_string($collation).'\'');
                    $sql_str.= ",series_title=".    (empty($series_title)?    'null':'\''.$dbs->escape_string($series_title).'\'');
                    $sql_str.= ",call_number=".     (empty($call_number)?     'null':'\''.$dbs->escape_string($call_number).'\'');
                    $sql_str.= ",language_id=".     (empty($language_id)?     'null':'\''.$dbs->escape_string($language_id).'\'');
                    $sql_str.= ",publish_place_id=".(empty($publish_place_id)?'null':'\''.$dbs->escape_string($publish_place_id).'\'');
                    $sql_str.= ",classification=".  (empty($classification)?  'null':'\''.$dbs->escape_string($classification).'\'');
                    $sql_str.= ",notes=".           (empty($notes)?           'null':'\''.$dbs->escape_string($notes).'\'');
                    $sql_str.= ",image=".           (empty($image)?           'null':'\''.$dbs->escape_string($image).'\'');
                    $sql_str.= ",file_att=".        (empty($file_att)?        'null':'\''.$dbs->escape_string($file_att).'\'');
                    $sql_str.= ",last_update=".     $curr_datetime;
                    $sql_str.= "WHERE biblio.biblio_id = ".'\''.$dbs->escape_string($biblio_id).'\'';
                    $cnt_update++;
                } else {
                    $sql_str = "INSERT IGNORE INTO biblio (
                        title, gmd_id, edition,
                        isbn_issn, publisher_id, publish_year,
                        collation, series_title, call_number,
                        language_id, publish_place_id, classification,
                        notes, image, file_att,
                        input_date, last_update)
                        VALUES (";
                    $sql_str .=     (empty($title)?           'null':'\''.$dbs->escape_string($title).'\''); //title must not be empty
                    $sql_str .= ",".(empty($gmd_id)?          'null':'\''.$dbs->escape_string($gmd_id).'\'');
                    $sql_str .= ",".(empty($edition)?         'null':'\''.$dbs->escape_string($edition).'\'');
                    $sql_str .= ",".(empty($isbn_issn)?       'null':'\''.$dbs->escape_string($isbn_issn).'\'');
                    $sql_str .= ",".(empty($publisher_id)?    'null':'\''.$dbs->escape_string($publisher_id).'\'');
                    $sql_str .= ",".(empty($publish_year)?    'null':'\''.$dbs->escape_string($publish_year).'\'');
                    $sql_str .= ",".(empty($collation)?       'null':'\''.$dbs->escape_string($collation).'\'');
                    $sql_str .= ",".(empty($series_title)?    'null':'\''.$dbs->escape_string($series_title).'\'');
                    $sql_str .= ",".(empty($call_number)?     'null':'\''.$dbs->escape_string($call_number).'\'');
                    $sql_str .= ",".(empty($language_id)?     'null':'\''.$dbs->escape_string($language_id).'\'');
                    $sql_str .= ",".(empty($publish_place_id)?'null':'\''.$dbs->escape_string($publish_place_id).'\'');
                    $sql_str .= ",".(empty($classification)?  'null':'\''.$dbs->escape_string($classification).'\'');
                    $sql_str .= ",".(empty($notes)?           'null':'\''.$dbs->escape_string($notes).'\'');
                    $sql_str .= ",".(empty($image)?           'null':'\''.$dbs->escape_string($image).'\'');
                    $sql_str .= ",".(empty($file_att)?        'null':'\''.$dbs->escape_string($file_att).'\'');
                    $sql_str .= ",".$curr_datetime;
                    $sql_str .= ",".$curr_datetime;
                    $sql_str .= ")";
                    $cnt_insert++;
                }
                // send query
                // die ($sql);
                $dbs->query($sql_str);
                if (empty($biblio_id)) {
                    $biblio_id = $dbs->insert_id;
                }
                if ($dbs->error) {
                    $cnt_sql_error++;
                } else {
                    // remove authors
                    $sql ="DELETE FROM biblio_author WHERE biblio_id = '".$biblio_id."'";
                    $dbs->query($sql);
                    // set authors
                    if (!empty($authors)) {
                        $biblio_author_sql = 'INSERT IGNORE INTO biblio_author (biblio_id, author_id, level) VALUES ';
                        $authors = explode('><', $authors);
                        foreach ($authors as $author) {
                            $author = trim(str_replace(array('>', '<'), '', $author));
                            $author_id = utility::getID($dbs, 'mst_author', 'author_id', 'author_name', $author, $author_id_cache);
                            $biblio_author_sql .= " ($biblio_id, $author_id, 2),";
                        }
                        // remove last comma
                        $biblio_author_sql = substr_replace($biblio_author_sql, '', -1);
                        // execute query
                        $dbs->query($biblio_author_sql);
                        // echo $dbs->error;
                    }
                    // remove topics
                    $sql ="DELETE FROM biblio_topic WHERE biblio_id = '".$biblio_id."'";
                    $dbs->query($sql);
                    // set topics
                    if (!empty($subjects)) {
                        $biblio_subject_sql = 'INSERT IGNORE INTO biblio_topic (biblio_id, topic_id, level) VALUES ';
                        $subjects = explode('><', $subjects);
                        foreach ($subjects as $subject) {
                            $subject = trim(str_replace(array('>', '<'), '', $subject));
                            $subject_id = utility::getID($dbs, 'mst_topic', 'topic_id', 'topic', $subject, $subject_id_cache);
                            $biblio_subject_sql .= " ($biblio_id, $subject_id, 2),";
                        }
                        // remove last comma
                        $biblio_subject_sql = substr_replace($biblio_subject_sql, '', -1);
                        // execute query
                        $dbs->query($biblio_subject_sql);
                        // echo $dbs->error;
                    }
                    // set items
                    if (!empty($items)) {
                        $item_sql = 'INSERT IGNORE INTO item (biblio_id, item_code) VALUES ';
                        $item_array = explode('><', $items);
                        foreach ($item_array as $item) {
                            $item = trim(str_replace(array('>', '<'), '', $item));
                            $item_sql .= " ($biblio_id, '$item'),";
                        }
                        // remove last comma
                        $item_sql = substr_replace($item_sql, '', -1);
                        // execute query
                        $dbs->query($item_sql);
                    }
                }
            }
        }
        // close file handle
        fclose($file);
        $end_time = time();
        $import_time_sec = $end_time-$start_time;
        utility::writeLogs($dbs, 'staff', $_SESSION['uid'], 'bibliography', 'Importing '.$cnt_insert.' bibliographic records from file : '.$_FILES['importFile']['name']);
        $msg="";
        $msg .= "<strong>".$row_count."</strong> ".__("record(s) in file.")." ";
        $msg .= __("Start processing from record")." <strong>".$record_offset." ".__("in")." ".$import_time_sec." ".__("second(s)")."</strong><br>";
        $msg .= "<strong>".$cnt_ignore."</strong> ".__("record(s) ignored").", ";
        $msg .= "<strong>".$cnt_insert."</strong> ".__("record(s) inserted").", ";
        $msg .= "<strong>".$cnt_update."</strong> ".__("record(s) updated")." ".__("having")." "."<strong>".$cnt_sql_error."</strong> ".__("record(s) with sql error");
        echo '<script type="text/javascript">'."\n";
        echo 'parent.$(\'importInfo\').update(\''.$msg.'\');'."\n";
        echo 'parent.$(\'importInfo\').setStyle( {display: \'block\'} );'."\n";
        echo '</script>';
        exit();
    }
}
?>
<fieldset class="menuBox">
<div class="menuBoxInner importIcon"><?php echo __('IMPORT TOOL'); ?>
<hr />
<?php echo __('Import for bibliografphic data from CSV file'); ?></div>
</fieldset>
<div id="importInfo" class="infoBox" style="display: none;">&nbsp;</div>
<div id="importError" class="errorBox" style="display: none;">&nbsp;</div>
<?php

// create new instance
$form = new simbio_form_table_AJAX('mainForm', $_SERVER['PHP_SELF'], 'post');
$form->submit_button_attr = 'name="doImport" value="'.__('Import Now').'" class="button"';

// form table attributes
$form->table_attr = 'align="center" id="dataList" cellpadding="5" cellspacing="0"';
$form->table_header_attr = 'class="alterCell" style="font-weight: bold;"';
$form->table_content_attr = 'class="alterCell2"';

/* Form Element(s) */
// csv files
$str_input = simbio_form_element::textField('file', 'importFile');
$str_input .= ' Maximum '.$sysconf['max_upload'].' KB';
$form->addAnything(__('File To Import').'*', $str_input);
// fileCharacterset
$characterset_options[] = array('ISO-8859-1', 'Latin-1');
$characterset_options[] = array('ISO-8859-1', 'ISO-8859-1');
$characterset_options[] = array('UTF-8', 'UTF-8');
$form->addSelectList('fileCharacterset', __('File Encoding'), $characterset_options);
// field separator
$form->addTextField('text', 'fieldSep', __('Field Separator').'*', ''.htmlentities(',').'', 'style="width: 10%;" maxlength="3"');
//  field enclosed
$form->addTextField('text', 'fieldEnc', __('Field Enclosed With').'*', ''.htmlentities('"').'', 'style="width: 10%;"');
// number of records to import
$form->addTextField('text', 'recordNum', __('Number of Records To Import (0 for all records)'), '0', 'style="width: 10%;"');
// records offset
$form->addTextField('text', 'recordOffset', __('Start From Record'), '1', 'style="width: 10%;"');
// output the form
echo $form->printOut();
?>
