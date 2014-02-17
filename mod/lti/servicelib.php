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
 * Utility code for LTI service handling.
 *
 * @package    mod
 * @subpackage lti
 * @copyright  Copyright (c) 2011 Moodlerooms Inc. (http://www.moodlerooms.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Chris Scribner
 */

defined('MOODLE_INTERNAL') || die;

require_once($CFG->dirroot.'/mod/lti/OAuthBody.php');

// TODO: Switch to core oauthlib once implemented - MDL-30149
use moodle\mod\lti as lti;

define('LTI_ITEM_TYPE', 'mod');
define('LTI_ITEM_MODULE', 'lti');
define('LTI_SOURCE', 'mod/lti');

function lti_get_response_xml($codemajor, $description, $messageref, $messagetype) {
    $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><imsx_POXEnvelopeResponse />');
    $xml->addAttribute('xmlns', 'http://www.imsglobal.org/services/ltiv1p1/xsd/imsoms_v1p0');

    $headerinfo = $xml->addChild('imsx_POXHeader')->addChild('imsx_POXResponseHeaderInfo');

    $headerinfo->addChild('imsx_version', 'V1.0');
    $headerinfo->addChild('imsx_messageIdentifier', (string)mt_rand());

    $statusinfo = $headerinfo->addChild('imsx_statusInfo');
    $statusinfo->addchild('imsx_codeMajor', $codemajor);
    $statusinfo->addChild('imsx_severity', 'status');
    $statusinfo->addChild('imsx_description', $description);
    $statusinfo->addChild('imsx_messageRefIdentifier', $messageref);
    $incomingtype = str_replace('Response','Request', $messagetype);
    $statusinfo->addChild('imsx_operationRefIdentifier', $incomingtype);

    $xml->addChild('imsx_POXBody')->addChild($messagetype);

    return $xml;
}

function lti_parse_message_id($xml) {
    $node = $xml->imsx_POXHeader->imsx_POXRequestHeaderInfo->imsx_messageIdentifier;
    return (string) $node;
}

function lti_parse_grade_replace_message($xml) {
    $node = $xml->imsx_POXBody->replaceResultRequest->resultRecord->sourcedGUID->sourcedId;
    $resultjson = json_decode((string)$node);

    $node = $xml->imsx_POXBody->replaceResultRequest->resultRecord->result->resultScore->textString;

    $score = (string) $node;
    if ( ! is_numeric($score) ) {
        throw new Exception('Score must be numeric');
    }
    $grade = floatval($score);
    if ( $grade < 0.0 || $grade > 1.0 ) {
        throw new Exception('Score not between 0.0 and 1.0');
    }

    $parsed = new stdClass();
    $parsed->gradeval = $grade * 100;

    $parsed->instanceid = $resultjson->data->instanceid;
    $parsed->userid = $resultjson->data->userid;
    $parsed->launchid = $resultjson->data->launchid;
    $parsed->sourcedidhash = $resultjson->hash;

    $parsed->messageid = lti_parse_message_id($xml);

    return $parsed;
}

function lti_parse_grade_read_message($xml) {
    $node = $xml->imsx_POXBody->readResultRequest->resultRecord->sourcedGUID->sourcedId;
    $resultjson = json_decode((string)$node);

    $parsed = new stdClass();
    $parsed->instanceid = $resultjson->data->instanceid;
    $parsed->userid = $resultjson->data->userid;
    $parsed->launchid = $resultjson->data->launchid;
    $parsed->sourcedidhash = $resultjson->hash;

    $parsed->messageid = lti_parse_message_id($xml);

    return $parsed;
}

function lti_parse_grade_delete_message($xml) {
    $node = $xml->imsx_POXBody->deleteResultRequest->resultRecord->sourcedGUID->sourcedId;
    $resultjson = json_decode((string)$node);

    $parsed = new stdClass();
    $parsed->instanceid = $resultjson->data->instanceid;
    $parsed->userid = $resultjson->data->userid;
    $parsed->launchid = $resultjson->data->launchid;
    $parsed->sourcedidhash = $resultjson->hash;

    $parsed->messageid = lti_parse_message_id($xml);

    return $parsed;
}

function lti_parse_memberships_message($xml, $groups = FALSE) {
    if($groups) {
        return $xml->imsx_POXBody->readMembershipsWithGroupsRequest->sourcedId;
    } else {
        return $xml->imsx_POXBody->readMembershipsRequest->sourcedId;
    }

}

function lti_update_grade($ltiinstance, $userid, $launchid, $gradeval) {
    global $CFG, $DB;
    require_once($CFG->libdir . '/gradelib.php');

    $params = array();
    $params['itemname'] = $ltiinstance->name;

    $grade = new stdClass();
    $grade->userid   = $userid;
    $grade->rawgrade = $gradeval;

    $status = grade_update(LTI_SOURCE, $ltiinstance->course, LTI_ITEM_TYPE, LTI_ITEM_MODULE, $ltiinstance->id, 0, $grade, $params);

    $record = $DB->get_record('lti_submission', array('ltiid' => $ltiinstance->id, 'userid' => $userid, 'launchid' => $launchid), 'id');
    if ($record) {
        $id = $record->id;
    } else {
        $id = null;
    }

    if (!empty($id)) {
        $DB->update_record('lti_submission', array(
            'id' => $id,
            'dateupdated' => time(),
            'gradepercent' => $gradeval,
            'state' => 2
        ));
    } else {
        $DB->insert_record('lti_submission', array(
            'ltiid' => $ltiinstance->id,
            'userid' => $userid,
            'datesubmitted' => time(),
            'dateupdated' => time(),
            'gradepercent' => $gradeval,
            'originalgrade' => $gradeval,
            'launchid' => $launchid,
            'state' => 1
        ));
    }

    return $status == GRADE_UPDATE_OK;
}

function lti_read_grade($ltiinstance, $userid) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    $grades = grade_get_grades($ltiinstance->course, LTI_ITEM_TYPE, LTI_ITEM_MODULE, $ltiinstance->id, $userid);

    if (isset($grades) && isset($grades->items[0]) && is_array($grades->items[0]->grades)) {
        foreach ($grades->items[0]->grades as $agrade) {
            $grade = $agrade->grade;
            $grade = $grade / 100.0;
            break;
        }
    }

    if (isset($grade)) {
        return $grade;
    }
}

function lti_delete_grade($ltiinstance, $userid) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    $grade = new stdClass();
    $grade->userid   = $userid;
    $grade->rawgrade = null;

    $status = grade_update(LTI_SOURCE, $ltiinstance->course, LTI_ITEM_TYPE, LTI_ITEM_MODULE, $ltiinstance->id, 0, $grade, array('deleted'=>1));

    return $status == GRADE_UPDATE_OK;
}

function lti_verify_message($key, $sharedsecrets, $body, $headers = null) {
    foreach ($sharedsecrets as $secret) {
        $signaturefailed = false;

        try {
            // TODO: Switch to core oauthlib once implemented - MDL-30149
            lti\handleOAuthBodyPOST($key, $secret, $body, $headers);
        } catch (Exception $e) {
            $signaturefailed = true;
        }

        if (!$signaturefailed) {
            return $secret;//Return the secret used to sign the message)
        }
    }

    return false;
}

function lti_verify_sourcedid($ltiinstance, $parsed) {
    $sourceid = lti_build_sourcedid($parsed->instanceid, $parsed->userid, $parsed->launchid, $ltiinstance->servicesalt);

    if ($sourceid->hash != $parsed->sourcedidhash) {
        throw new Exception('SourcedId hash not valid');
    }
}

/**
 * Extend the LTI services through the ltisource plugins
 *
 * @param stdClass $data LTI request data
 * @return bool
 * @throws coding_exception
 */
function lti_extend_lti_services($data) {
    $plugins = get_plugin_list_with_function('ltisource', $data->messagetype);
    if (!empty($plugins)) {
        try {
            // There can only be one
            if (count($plugins) > 1) {
                throw new coding_exception('More than one ltisource plugin handler found');
            }
            $callback = current($plugins);
            call_user_func($callback, $data);
        } catch (moodle_exception $e) {
            $error = $e->getMessage();
            if (debugging('', DEBUG_DEVELOPER)) {
                $error .= ' '.format_backtrace(get_exception_info($e)->backtrace);
            }
            $responsexml = lti_get_response_xml(
                'failure',
                $error,
                $data->messageid,
                $data->messagetype
            );

            header('HTTP/1.0 400 bad request');
            echo $responsexml->asXML();
        }
        return true;
    }
    return false;
}

function lti_get_memberships_xml($xml, $include_groups = FALSE) {

    global $DB,$CFG;

    $id = (int) lti_parse_memberships_message($xml, $include_groups);

    $ltiinstance = $DB->get_record('lti', array('id' => $id));

    $typeconfig = lti_get_type_config($ltiinstance->typeid);

    if (!isset($typeconfig)) {
        do_error("Unable to load type");
    }

    if (! $course = $DB->get_record('course', array('id'=>$ltiinstance->course))) {
        do_error("Could not retrieve course");
    }
    if (! $context = context_course::instance($course->id)) {
        do_error('Could not retrieve context');
    }
    $sql = 'SELECT u.id, u.username, u.firstname, u.lastname, u.email, ro.shortname
        FROM  '.$CFG->prefix.'role_assignments ra
        JOIN  '.$CFG->prefix.'user AS u ON ra.userid = u.id
        JOIN  '.$CFG->prefix.'role ro ON ra.roleid = ro.id
        WHERE ra.contextid = '.$context->id;
    $userlist = $DB->get_recordset_sql($sql);

    $responsetype = $include_groups
         ? 'readMembershipsWithGroupsResponse' : 'readMembershipsResponse';

    $responsexml = lti_get_response_xml(
            'success',
            'read memberships',
            $id,
            $responsetype
    );

    if($include_groups) {
        $node = $responsexml->imsx_POXBody->readMembershipsWithGroupsResponse;
    } else {
        $node = $responsexml->imsx_POXBody->readMembershipsResponse;
    }

    $membershipsnode = $node->addChild('memberships');

    foreach ($userlist as $user) {

        $role = 'Learner';
        if ($user->shortname == 'editingteacher' || $user->shortname == 'admin') {
            $role = 'Instructor';
        }
        $membernode = $membershipsnode->addChild('member');
        $membernode->addChild('user_id',htmlspecialchars($user->id));
        $membernode->addChild('roles',$role);

        if($include_groups) {
            $coursegroups = groups_get_user_groups($course->id,$user->id);
            $groupids = array_merge(array(), $coursegroups['0']);
            $groupsnode = $membernode->addChild('groups');
            foreach ($groupids as $groupid) {
                $group = groups_get_group($groupid);
                $groupnode = $groupsnode->addChild('group');
                $groupnode->addChild('id', $group->id);
                $groupnode->addChild('title', $group->name);
                $setnode = $groupnode->addChild('set');
                $setnode->addChild('id', $group->id);
                $setnode->addChild('title', $group->name);
            }
        }

        if ($typeconfig['sendname'] == 1 || ($typeconfig['sendname'] == 2 && $ltiinstance->instructorchoicesendname == 1)) {

             if (isset($user->firstname)) {
                 $membernode->addChild('person_name_given',htmlspecialchars($user->firstname));
             }

            if (isset($user->lastname)) {
                 $membernode->addChild('person_name_family',htmlspecialchars($user->lastname));
            }
        }

        if (isset($user->email)) {

            if ($typeconfig['sendemailaddr'] == 1
                 || ($typeconfig['sendemailaddr'] == 2 && $ltiinstance->instructorchoicesendemailaddr == 1)) {
                $membernode->addChild('person_contact_email_primary',htmlspecialchars($user->email));
            }
        }

        $placementsecret = $ltiinstance->password;
        if (isset($placementsecret)) {
            $suffix = ':::' . $user->id . ':::' . $ltiinstance->id;
            $plaintext = $placementsecret . $suffix;
            $hashsig = hash('sha256', $plaintext, false);
            $sourcedid = $hashsig . $suffix;
        }

        if (isset($sourcedid)) {
            if ($typeconfig['acceptgrades'] == 1
                 || ($typeconfig['acceptgrades'] == 2 && $ltiinstance->instructorchoiceacceptgrades == 1)) {
                $membernode->addChild('lis_result_sourcedid',htmlspecialchars($sourcedid));
            }
        }
    }

    return $responsexml->asXML();
}
