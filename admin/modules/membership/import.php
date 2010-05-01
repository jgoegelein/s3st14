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

/* Member Import section */

// main system configuration
require '../../../sysconfig.inc.php';
// start the session
require SENAYAN_BASE_DIR.'admin/default/session.inc.php';
require SENAYAN_BASE_DIR.'admin/default/session_check.inc.php';
require SIMBIO_BASE_DIR.'simbio_GUI/form_maker/simbio_form_table_AJAX.inc.php';
require SIMBIO_BASE_DIR.'simbio_GUI/table/simbio_table.inc.php';
require SIMBIO_BASE_DIR.'simbio_FILE/simbio_file_upload.inc.php';

// privileges checking
$can_read = utility::havePrivilege('membership', 'r');
$can_write = utility::havePrivilege('membership', 'w');

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
        set_time_limit(7200);
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
        $mtype_id_cache = array();
        // read file line by line
        $cnt_insert = 0;
        $cnt_update = 0;
        $cnt_ignore = 0;
        $cnt_sql_error = 0;
        $file = fopen($uploaded_file, 'r');
        $column_pos = 0;
        $num_fields_expected = 17; //the number of colums we expect in an import file
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
                $member_id         = $field[$column_pos++];
                $member_name       = $field[$column_pos++];
                $gender            = $field[$column_pos++];
                $member_type_name  = $field[$column_pos++];
                $member_type_id    = utility::getID($dbs, 'mst_member_type', 'member_type_id', 'member_type_name', $member_type_name , $mtype_id_cache);
                $member_email      = $field[$column_pos++];
                $member_address    = $field[$column_pos++];
                $postal_code       = $field[$column_pos++];
                $inst_name         = $field[$column_pos++];
                $is_new            = $field[$column_pos++];
                $member_image      = $field[$column_pos++];
                $pin               = $field[$column_pos++];
                $member_phone      = $field[$column_pos++];
                $member_fax        = $field[$column_pos++];
                $member_since_date = $field[$column_pos++];
                $register_date     = $field[$column_pos++];
                $expire_date       = $field[$column_pos++];
                $member_notes      = $field[$column_pos++];
                $member_notes      = preg_replace('@\\\s*'.$field_enc.'$@i', '', $member_notes);
                // get current datetime
                $curr_datetime = '\''.date('Y-m-d H:i:s').'\'';

                if (!empty($member_id)) {
                    $sql_str = "UPDATE member set ";
                    $sql_str.= " member_name=".      (empty($member_name)?      'null':'\''.$dbs->escape_string($member_name).'\'');
                    $sql_str.= ",gender=".           (empty($gender)?           'null':'\''.$dbs->escape_string($gender).'\'');
                    $sql_str.= ",member_type_id=".   (empty($member_type_id)?   'null':'\''.$dbs->escape_string($member_type_id).'\'');
                    $sql_str.= ",member_email=".     (empty($member_email)?     'null':'\''.$dbs->escape_string($member_email).'\'');
                    $sql_str.= ",member_address=".   (empty($member_address)?   'null':'\''.$dbs->escape_string($member_address).'\'');
                    $sql_str.= ",postal_code=".      (empty($postal_code)?      'null':'\''.$dbs->escape_string($postal_code).'\'');
                    $sql_str.= ",inst_name=".        (empty($inst_name)?        'null':'\''.$dbs->escape_string($inst_name).'\'');
                    $sql_str.= ",is_new=".           (empty($is_new)?           'null':'\''.$dbs->escape_string($is_new).'\'');
                    $sql_str.= ",member_image=".     (empty($member_image)?     'null':'\''.$dbs->escape_string($member_image).'\'');
                    $sql_str.= ",pin=".              (empty($pin)?              'null':'\''.$dbs->escape_string($pin).'\'');
                    $sql_str.= ",member_phone=".     (empty($member_phone)?     'null':'\''.$dbs->escape_string($member_phone).'\'');
                    $sql_str.= ",member_fax=".       (empty($member_fax)?       'null':'\''.$dbs->escape_string($member_fax).'\'');
                    $sql_str.= ",member_since_date=".(empty($member_since_date)?'null':'\''.$dbs->escape_string($member_since_date).'\'');
                    $sql_str.= ",register_date=".    (empty($register_date)?    'null':'\''.$dbs->escape_string($register_date).'\'');
                    $sql_str.= ",expire_date=".      (empty($expire_date)?      'null':'\''.$dbs->escape_string($expire_date).'\'');
                    $sql_str.= ",member_notes=".     (empty($member_notes)?     'null':'\''.$dbs->escape_string($member_notes).'\'');
                    $sql_str.= " WHERE member.member_id = ".'\''.$dbs->escape_string($member_id).'\'';
                    $cnt_update++;
                } else {
                    $sql_str = "INSERT INTO member
                        (member_id, member_name, gender,
                        member_type_id, member_email, member_address, postal_code,
                        inst_name, is_new, member_image, pin, member_phone,
                        member_fax, member_since_date, register_date,
                        expire_date, member_notes,
                        input_date, last_update)
                        VALUES (";
                    $sql_str.=     (empty($member_id)?       'null':'\''.$dbs->escape_string($member_id).'\'');
                    $sql_str.= ",".(empty($member_name)?       'null':'\''.$dbs->escape_string($member_name).'\'');
                    $sql_str.= ",".(empty($gender)?       'null':'\''.$dbs->escape_string($gender).'\'');
                    $sql_str.= ",".(empty($member_type_id)?       'null':'\''.$dbs->escape_string($member_type_id).'\'');
                    $sql_str.= ",".(empty($member_email)?       'null':'\''.$dbs->escape_string($member_email).'\'');
                    $sql_str.= ",".(empty($member_address)?       'null':'\''.$dbs->escape_string($member_address).'\'');
                    $sql_str.= ",".(empty($postal_code)?       'null':'\''.$dbs->escape_string($postal_code).'\'');
                    $sql_str.= ",".(empty($inst_name)?       'null':'\''.$dbs->escape_string($inst_name).'\'');
                    $sql_str.= ",".(empty($is_new)?       'null':'\''.$dbs->escape_string($is_new).'\'');
                    $sql_str.= ",".(empty($member_image)?       'null':'\''.$dbs->escape_string($member_image).'\'');
                    $sql_str.= ",".(empty($pin)?       'null':'\''.$dbs->escape_string($pin).'\'');
                    $sql_str.= ",".(empty($member_phone)?       'null':'\''.$dbs->escape_string($member_phone).'\'');
                    $sql_str.= ",".(empty($member_fax)?       'null':'\''.$dbs->escape_string($member_fax).'\'');
                    $sql_str.= ",".(empty($member_since_date)?       'null':'\''.$dbs->escape_string($member_since_date).'\'');
                    $sql_str.= ",".(empty($register_date)?       'null':'\''.$dbs->escape_string($register_date).'\'');
                    $sql_str.= ",".(empty($expire_date)?       'null':'\''.$dbs->escape_string($expire_date).'\'');
                    $sql_str.= ",".(empty($member_notes)?       'null':'\''.$dbs->escape_string($member_notes).'\'');
                    $sql_str.= ",". $curr_datetime.",". $curr_datetime.")";
                    $cnt_insert++;
                }
                // send query
                // die ($sql);
                $dbs->query($sql_str);
                if (empty($item_id)) {
                    $item_id = $dbs->insert_id;
                }
                if ($dbs->error) {
                    $cnt_sql_error++;
                }
            }
        }
        // close file handle
        fclose($file);
        $end_time = time();
        $import_time_sec = $end_time-$start_time;
        utility::writeLogs($dbs, 'staff', $_SESSION['uid'], 'membership', 'Importing '.$cnt_insert.' members data from file : '.$_FILES['importFile']['name']);
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
<div class="menuBoxInner importIcon"><?php echo __('ITEM IMPORT TOOL'); ?>
<hr />
<?php echo __('Import for item data from CSV file'); ?></div>
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
