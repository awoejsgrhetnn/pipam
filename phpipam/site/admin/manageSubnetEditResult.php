<?php

/** 
 * Function to add / edit / delete section
 ********************************************/

/* required functions */
require_once('../../functions/functions.php');

/* verify that user is logged in */
isUserAuthenticated(true);

/* prevent XSS in action */
$_POST['action'] = filter_user_input ($_POST['action'], false, true, true);
/* escape vars to prevent SQL injection */
$_POST = filter_user_input ($_POST, true, true);

/* must be numeric */
if($_POST['action']=="add") {
	if(!is_numeric($_POST['sectionId']))	{ die('<div class="alert alert-danger">'._("Invalid ID").'</div>'); }
} else {
	if(!is_numeric($_POST['subnetId']))		{ die('<div class="alert alert-danger">'._("Invalid ID").'</div>'); }
	if(!is_numeric($_POST['sectionId']))	{ die('<div class="alert alert-danger">'._("Invalid ID").'</div>'); }	
}



/* verify that user has permissions if add */
if($_POST['action'] == "add") {
	$sectionPerm = checkSectionPermission ($_POST['sectionId']);
	if($sectionPerm != 3) {
		die("<div class='alert alert-danger'>"._('You do not have permissions to add new subnet in this section')."!</div>");
	}
}
/* otherwise check subnet permission */
else {
	$subnetPerm = checkSubnetPermission ($_POST['subnetId']);
	if($subnetPerm != 3) {
		die("<div class='alert alert-danger'>"._('You do not have permissions to add edit/delete this subnet')."!</div>");
	}	
}



//we need old values for mailing
if($_POST['action']=="edit" || $_POST['action']=="delete") {
	$old = getSubnetDetailsById($_POST['subnetId']);
	$old['subnet']=transform2long($old['subnet']);
}

$new = $_POST;
$new['mask']=trim(strstr($new['subnet'], "/"),"/");
$new['subnet']=strstr($new['subnet'], "/",true);
$new['id']=$new['subnetId'];
$new['allowRequests']=isCheckbox($new['allowRequests']);
		


/* verify post */
CheckReferrer();

/* get all settings */
$settings = getAllSettings();

/* get section details */
$section = getSectionDetailsById($_POST['sectionId']);

/* get master subnet details for folder overrides */
if($_POST['masterSubnetId']!="0")	{
	$mSection = getSubnetDetailsById($_POST['masterSubnetId']);
	if($mSection['isFolder']=="1")	{ $parentIsFolder = true; }
	else							{ $parentIsFolder = false; }
} 
else 								{ $parentIsFolder = false; }


/**
 * If request came from IP address subnet edit and
 * action2 is Delete then change action
 */
if(	(isset($_POST['action2'])) && ($_POST['action2'] == "delete") ) {
	$_POST['action'] = $_POST['action2'];
}


/**
 *	If section changes then do checks!
 */
if ( ($_POST['sectionId'] != $_POST['sectionIdNew']) && $_POST['action'] == "edit" ) {
	
	# reset masterId - we are putting it to root
	$_POST['masterSubnetId'] = "0";

    # check for overlapping
    if($section['strictMode'] == 1 && !$parentIsFolder) {
    	/* verify that no overlapping occurs if we are adding root subnet */
    	if ( $overlap = verifySubnetOverlapping ($_POST['sectionIdNew'], $_POST['subnet'], $_POST['vrfId']) ) {
    		$errors[] = $overlap;
    	}   
    }
}
/**
 * Execute checks on add only and when root subnet is being added
 */
else if (($_POST['action'] == "add") && ($_POST['masterSubnetId'] == 0)) {
    /* first verify user input */
    $errors   	= verifyCidr ($_POST['subnet']);

    /* check for overlapping */
    if($section['strictMode'] == 1 && !$parentIsFolder) {
    	/* verify that no overlapping occurs if we are adding root subnet 
	       only check for overlapping if vrf is empty or not exists!
    	*/
    	if ( $overlap = verifySubnetOverlapping ($_POST['sectionId'], $_POST['subnet'], $_POST['vrfId']) ) {
    		$errors[] = $overlap;
    	}   
    }
}
/**
 * Execute different checks on add only and when subnet is nested
 */
else if ($_POST['action'] == "add") {
    /* first verify user input */
    $errors   	= verifyCidr ($_POST['subnet']);
    
    /* disable checks for folders and if strict check enabled */
    if($section['strictMode'] == 1 && !$parentIsFolder ) {

	    /* verify that nested subnet is inside root subnet */
        if (!$overlap = verifySubnetNesting($_POST['masterSubnetId'], $_POST['subnet'])) {
            $errors[] = _('Nested subnet not in root subnet!');
        }
    
	    /* nested? */
	    if($_POST['masterSubnetId']!= 0) {
	        if ($overlap = verifyNestedSubnetOverlapping($_POST['sectionId'], $_POST['subnet'], $_POST['vrfId'], $_POST['masterSubnetId'])) {
	            $errors[] = $overlap;
	        }		    
	    }
	    /* not nested */
	    else {
	        if ($overlap = verifySubnetOverlapping($_POST['sectionId'], $_POST['subnet'], $_POST['vrfId'], $_POST['masterSubnetId'])) {
	            $errors[] = $overlap;
	        }		    
	    }
    }
    
} 
/**
 * Check if slave is under master
 */
else if ($_POST['action'] == "edit") {
	
    if($section['strictMode']==1 && !$parentIsFolder) {
    	/* verify that nested subnet is inside root subnet */
    	if ( (!$overlap = verifySubnetNesting($_POST['masterSubnetId'], $_POST['subnet'])) && $_POST['masterSubnetId']!=0) {
    		$errors[] = _('Nested subnet not in root subnet!');
    	}   
    }
    /* for nesting - MasterId cannot be the same as subnetId! */
    if ( $_POST['masterSubnetId'] == $_POST['subnetId'] ) {
    	$errors[] = _('Subnet cannot nest behind itself!');
    }    
}
else {}

/* but always verify vlan! */
$vlancheck = validateVlan($_POST['VLAN']);

if($vlancheck != 'ok') {
    $errors[] = $vlancheck;
}



//custom
$myFields = getCustomFields('subnets');
if(sizeof($myFields) > 0) {
	foreach($myFields as $myField) {
		# replace possible ___ back to spaces!
		$myField['nameTest']      = str_replace(" ", "___", $myField['name']);
		
		if(isset($_POST[$myField['nameTest']])) { $_POST[$myField['name']] = $_POST[$myField['nameTest']];}
		
		//booleans can be only 0 and 1!
		if($myField['type']=="tinyint(1)") {
			if($_POST[$myField['name']]>1) {
				$_POST[$myField['name']] = "";
			}
		}
		
		//not empty
		if($myField['Null']=="NO" && strlen($_POST[$myField['name']])==0 && !checkAdmin(false)) {
			$errors[] = "Field \"$myField[name]\" cannot be empty!";
		}
	}
}


/* sanitize description */
/* $_POST['description'] = htmlentities($_POST['description'], ENT_COMPAT | ENT_HTML401, "UTF-8");	//prevent XSS */


/* Set permissions for add! */
if($_POST['action'] == "add") {
	# root
	if($_POST['masterSubnetId'] == 0) {
		$_POST['permissions'] = $section['permissions'];
	}
	# nested - inherit parent permissions
	else {
		# get parent
		$parent = getSubnetDetailsById($_POST['masterSubnetId']);
		$_POST['permissions'] = $parent['permissions'];
	}
}


/* If no errors are present execute request */
if (sizeof($errors) != 0) 
{
    print '<div class="alert alert-danger"><strong>'._('Please fix following problems').'</strong>:';
    foreach ($errors as $error) { print "<br>".$error; }
    print '</div>';
    die();
}
elseif ($_POST['action']=="delete" && !isset($_POST['deleteconfirm'])) {
	# for ajax to prevent reload
	print "<div style='display:none'>alert alert-danger</div>";
	# result
	print "<div class='alert alert-warning'>";
	# print what will be deleted
	getAllSlaves($_POST['subnetId'], false);
	$subcnt = sizeof(array_unique($removeSlaves));
	$ipcnt  = countAllSlaveIPAddresses($_POST['subnetId']);
	print "<strong>"._("Warning")."</strong>: "._("I will delete").":<ul>";
	print "	<li>$subcnt "._("subnets")."</li>";
	if($ipcnt>0) {
	print "	<li>$ipcnt "._("IP addresses")."</li>";
	}
	print "</ul>";
	
	print "<hr><div style='text-align:right'>";
	print _("Are you sure you want to delete above items?")." ";
	print "<div class='btn-group'>";
	print "	<a class='btn btn-sm btn-danger editSubnetSubmitDelete' id='editSubnetSubmitDelete'>"._("Confirm")."</a>";
	print "</div>";
	print "</div>";
	print "</div>";
}
else
{
	# failed
    if (!modifySubnetDetails ($_POST)) 		{ print '<div class="alert alert-danger">'._('Error adding new subnet').'!</div>'; }
    # all good
    else {
	    
    	/* @mail functions ------------------- */
		include_once('../../functions/functions-mail.php');
		sendObjectUpdateMails("subnet", $_POST['action'], $old, $new);

    	if($_POST['action'] == "delete") 	{ print '<div class="alert alert-success">'._('Subnet, IP addresses and all belonging subnets deleted successfully').'!</div>'; } 
    	else 								{ print '<div class="alert alert-success">'._("Subnet $_POST[action] successfull").'!</div>';  }
    }
}

?>