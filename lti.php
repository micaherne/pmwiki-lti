<?php
/**
 * pmwiki-lti. Copyright 2011, University of Strathclyde.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * This code represents an early engineering implementation of the draft IMS
 * Learning Tools Interoperability (LTI) Specification. The purpose of this
 * code is to explore technical issues around IMS Learning Tools Interoperability
 * and inform the standards development process as it goes forward. This code
 * is not certified as IMS LTI complaint. Therefore, there is no assurance
 * that this code will comply with the specification or interoperate with LTI
 * compliant tools and systems.
 *
 * Certifications of software products, content, or tools to IMS Specifications
 * are issued by IMS GLC and are listed on the IMS web site at
 * www.imsglobal.org/cc/statuschart.html.
 *
 * The LTI specifications will be made available to the public free of charge on
 * the IMS website when it is ready for public comment and subsequently as a
 * final specification (www.imsglobal.org/specifications.html). For interested
 * parties, details on joining IMS are available at www.imsglobal.org/joinims.html.
 *
 */

define( 'LTI_TCP', 'http://www.imsglobal.org/xsd/imsltiTCP_v1p0' );
define( 'LTI_PC', 'http://www.imsglobal.org/xsd/imsltiPC_v1p0' );
define( 'LTI_SEC', 'http://www.imsglobal.org/xsd/imsltiSEC_v1p0' );

// get request param
if(!isset($_GET['action'])) {
	echo "action param required";
} else {
	$function = 'lti_' . $_GET['action'];
	if(function_exists($function)) {
		$function();
		//die("Calling $function()");
	} else {
		die("Function $function doesn't exist");
	}
}

function lti_launch_packet() {
	return array(
    'user_id' => 1,
    'roles' => 1,
    'launch_presentation_locale' => 1,
    'launch_presentation_css_url' => 1,
    'launch_presentation_document_target' => 1,
    'launch_presentation_window_name' => 1,
    'launch_presentation_width' => 1,
    'launch_presentation_height' => 1,
    'launch_presentation_return_url' => 1, );
}

function lti_reg_packet() {
	return array(
    'reg_password' => 1,
    'tool_version' => 1,
    'tool_code' => 1,
    'vendor_code' => 1,
    'tc_profile_url' => 1, );
}

function lti_signed_packet() {
	return array(
    'digest_algorithm' => 1,
    'nonce' => 1,
    'timestamp' => 1,
    'mac' => 1, );
}

function lti_menu_view_packet() {
	return array(
    'tool_proxy_guid' => 1,
    'lti_version' => 1,
    'lti_message_type' => 1,
    'ticket' => 1,
    'context_id' => 1,
    'context_type' => 1, );
}

function lti_extract_menu_view_request( $vars ) {
	$ret = array_intersect_key( $vars, array_merge( lti_signed_packet(), lti_menu_view_packet(), lti_launch_packet() ) );
	foreach( array_keys( $vars ) as $k ) {
		if( 0 === strpos( $k, 'custom_' ) ) {
			$ret[$k] = $vars[$k];
		}
	}
	return (object) $ret;
}

function lti_extract_deploymentrequest( $vars ) {
	return (object) array_intersect_key( $vars, array_merge( lti_reg_packet(), lti_launch_packet() ) );
}

function lti_edit(){
	lti_sign_check();
	//lti_login_user();
	$r = lti_extract_menu_view_request( $_POST );
	// check for admin or instructor role
	if(lti_has_role($r, 'Administrator') || lti_has_role($r, 'Instructor')) {
		redirect( 'pmwiki.php?n=' . $r->custom_course_shortname . '.' . $r->custom_course_shortname . '?action=edit');
	} else {
		die('Must have administrator or instructor permissions to edit');
	}
	//print_r($r); die("Edit page");
	//echo( 'pmwiki.php?n=' . $r->custom_course_shortname . '.' . $r->custom_course_shortname . '?action=edit');
}

function lti_view(){
	//die("View page");
	lti_sign_check();
	$r = lti_extract_menu_view_request( $_POST );
	
	$page_name = lti_get_page_name($r);
	if(lti_page_exists($page_name)) {
		redirect( 'pmwiki.php?n=' .  $page_name);
	}
	
	// Check if person has instructor or admin privileges
	if(lti_has_role($r, 'Administrator') || lti_has_role($r, 'Instructor')) {
		lti_create_page($page_name, $r);
		redirect( 'pmwiki.php?n=' .  $page_name);
	} else {
		die("This page doesn't exist");
	}
}

function lti_has_role($request, $role) {
	if(!isset($request->roles)) {
		return false;
	}
	$roles = split(',', $request->roles);
	return in_array($role, $roles);
}

function lti_page_exists($page) {
	return file_exists('wiki.d/' . $page);
}

function lti_create_page($page, $request) {
	if($f = fopen('wiki.d/' . $page, 'w')) {
		fwrite($f, 'text=Welcome to the course page for ' . $request->custom_course_shortname);
		fclose($f);
	}
	// try to create edit password 
	$page_group = array_shift(split('\.', $page));
	
	if(!is_null($page_group)) {
		if($f = fopen('local/' . $page_group . '.php', 'w')) {
			fwrite($f, "<?php \$DefaultPasswords['edit'] = crypt('" . lti_get_password($request, 'edit') . "'); ");
			fclose($f);
		}
	}
}

function lti_get_password($request, $type) {
	$consumer = lti_get_consumer_data($request->tool_proxy_guid);
	return sha1($type . $consumer->secret);
}

// TODO: Currently requires unique shortnames. For multiple consumers, this probably won't do.
function lti_get_page_name($request) {
	return $request->custom_course_shortname . '.' . $request->custom_course_shortname;
}

function lti_get_consumer_data($guid) {
	if(! file_exists("lti_consumers/$guid")){
		die('Unable to read consumer data');
	} else {
		$file_contents = file_get_contents("lti_consumers/$guid");
		if($file_contents === false) {
			die("Unable to read file $consumer_file");
		}
		return json_decode($file_contents);
	}
}

function lti_sign_check() {
	$guid = $_POST['tool_proxy_guid'];
	$mac = $_POST['mac'];

	unset( $_POST['mac'] );
	ksort( $_POST );

	//global $wpdb;
	//$r = $wpdb->get_var( $wpdb->prepare( "select secret from {$wpdb->prefix}lti_consumers where tool_proxy_guid = %s", $guid ) );
	$data = lti_get_consumer_data($guid);
	if(!is_null($data)) {
			$r = $data->secret;
		} else {
			die('Unable to read consumer data' . $file_contents);
		}
	//$r = 'secret';
	if( $mac != base64_encode( hash( 'sha1', join( '', array_values( $_POST ) ) . $r, true ) ) ) {
		echo 'mac check fail';
		exit;
	}
}

function lti_menu_view() {
	var_dump( lti_extract_menu_view_request( $_POST ) );
	// so.. log them in then redirect ?
	// should log them in anyways..
	//
	// but redirect i dunno.. i think our entry point
	// is wrong somehow.
	exit;
}

function lti_ping() {
	exit; // gives us the 200 we need
}

function lti_register() {
	$deployp = lti_extract_deploymentrequest( $_POST );
	$resp = file_get_contents( $deployp->tc_profile_url );
	if( $resp === false ) {
		echo "bad sauce";
		var_dump( $resp );
		exit;
	}
	$cp = simplexml_load_string( $resp );
	$cp->registerXPathNamespace( 'tcp', LTI_TCP );
	$cp->registerXPathNamespace( 'pc', LTI_PC );
	$cp->registerXPathNamespace( 'sec', LTI_SEC );

	// find reg service
	$regep = $cp->xpath( '//pc:service[@name="RegistrationService"]/@wsdl' );
	$regep = (string) $regep[0];

	// find capabilities (context-tool, context-tool-administration)
	if( ! count( $cp->xpath( '//pc:capability[text()="menulink-category-context-tool"]' ) ) ) {
		echo 'consumer doesnt support context tool menu links';
		exit;
	}
	if( ! count( $cp->xpath( '//pc:capability[text()="menulink-category-context-administration"]' ) ) ) {
		echo 'consumer doesnt support context tool admin menu links';
		exit;
	}
	if( ! count( $cp->xpath( '//pc:capability[text()="ping"]' ) ) ) {
		echo 'consumer doesnt support ping notifications';
		exit;
	}
	if( ! count( $cp->xpath( '//pc:capability[text()="tool-proxy-removed-notification"]' ) ) ) {
		echo 'consumer doesnt support ping notifications';
		exit;
	}
	if( ! count( $sec = $cp->xpath( '//sec:basic_hash_message_security_profile/sec:algorithm[text()="SHA-1"]' ) ) ) {
		echo 'consumer doesnt support basic hash message security using SHA-1, we need it to';
		exit;
	}
	// get tool consumer guid
	if( ! count( $cguid = $cp->xpath( '//tcp:tool_consumer_instance/tcp:guid/text()' ) ) ) {
		echo 'consumer didnt supply a guid for its self';
		exit;
	} else {
		$cguid = (string) $cguid[0];
	}

	$secret = wp_generate_password( 64, false );
	$pprofile = lti_provider_profile( $secret );

	// call register
	if( is_null( $guid = lti_register_provider( $regep, $pprofile, $deployp ) ) ) {
		echo 'registration problem <br/>'; // . $guid->get_error_message();
		exit;
	}

	// store guid
	/*global $wpdb;
	if( false === $wpdb->insert( $wpdb->prefix . 'lti_consumers', array( 'tool_proxy_guid' => $guid, 'consumer_guid' => $cguid, 'consumer_user_id' => $deployp->user_id, 'time' => current_time('mysql'), 'secret' => $secret ) ) ) {
	echo 'failed to insert consumer information in local database';
	exit;
	}*/
	if(! $consumer_file = fopen("lti_consumers/$guid", 'w')){
		die('Unable to write consumer data');
	} else {
		$data = array( 'tool_proxy_guid' => $guid, 'consumer_guid' => $cguid, 'consumer_user_id' => $deployp->user_id, 'time' => time(), 'secret' => $secret );
		fwrite($consumer_file, json_encode($data));
	}

	// redirect
	redirect( $deployp->launch_presentation_return_url . '&status=success', 301 );
}

function lti_register_provider( $regep, $pprofile, $deployp ) {
	$guid = null; //new WP_Error( 1000, 'Registration failed' );
	$regs = new SoapClient( $regep, array( 'trace' => 1 ) );
	$b = new SoapVar( lti_soap_sec_header( 'lti-tool-registration', $deployp->reg_password ), XSD_ANYXML );
	$regs->__setSoapHeaders( new SoapHeader( 'http://im.not.sure.why/this/needs/to/be/here', 'sec', $b ) );
	try {
		$r = $regs->registerTool( array( 'schema_version' => 'imp', 'tool_registration_request' => $pprofile ) );
		$r = simplexml_load_string( $r->tool_registration_response );
		$guid = (string) $r->tool_proxy_guid;
		$h = simplexml_load_string( $regs->__getLastResponse() );
		$h->registerXPathNamespace( 'ims', 'http://www.imsglobal.org/services/ltiv2p0/tregv1p0/wsdl11/sync/imsltitreg_v1p0' );
		if( ! count( $h->xpath( '//ims:imsx_codeMajor[text()="success"]' ) ) ) {
			$reason = $h->xpath( '//ims:imsx_description/text()' );
			$guid = null; // new WP_Error( 1001, 'Registration failed, reason : ' . $reason[0], $h->asXML() );
		}
	} catch( Exception $e ) {
		$guid = null; // new WP_Error( 1002, 'Registration failed', $e );
	}
	return $guid;
}

function lti_provider_profile($secret) {
	return <<<XML
<tool_registration_request
  xmlns="http://www.imsglobal.org/services/ltiv2p0/ltirgsv1p0/imsltiRGS_v1p0"
  xmlns:sec="http://www.imsglobal.org/xsd/imsltiSEC_v1p0"
  xmlns:tp="http://www.imsglobal.org/xsd/imsltiTPR_v1p0"
  xmlns:cm="http://www.imsglobal.org/xsd/imsltiMSS_v1p0"
  xmlns:pc="http://www.imsglobal.org/xsd/imsltiPC_v1p0">
  <tool_profile lti_version="2.0">
    <tp:vendor>
      <pc:code>uk.ac.strath.pmwiki</pc:code>
      <pc:name>pmwiki</pc:name>
      <pc:contact>
        <pc:email>admin@localhost.local</pc:email>
      </pc:contact>
    </tp:vendor>
    <tp:tool_info>
      <pc:code>uk.ac.strath.pmwiki</pc:code>
      <pc:name>pmwiki</pc:name>
      <pc:version>1.1.1.1</pc:version>
    </tp:tool_info>
    <tp:tool_instance>
      <tp:contact>
        <pc:email>admin@localhost.local</pc:email>
      </tp:contact>
      <tp:base_urls>
        <tp:base_url type="default">http://localhost/pmwiki/lti.php</tp:base_url>
        <tp:base_url type="icon_default">http://localhost/pmwiki/</tp:base_url>
      </tp:base_urls>
    </tp:tool_instance>
    <tp:messages>
      <tp:message type="ping" path="?action=ping"/>
    </tp:messages>
    <tp:links>
     <tp:menu_link>
        <tp:title>Edit course wiki</tp:title>
        <tp:messages>
          <tp:message type="menu-view-request" path="?action=edit">
            <tp:parameter name="course_shortname" variable="\$CourseOffering.label"/>
            <tp:parameter name="course_title" variable="\$CourseOffering.title"/>
            <tp:parameter name="person_fullname" variable="\$Person.name.full"/>
            <tp:parameter name="person_email" variable="\$Person.email.primary"/>
          </tp:message>
        </tp:messages>
        <tp:icons>
          <pc:icon>logo.png</pc:icon>
        </tp:icons>
        <tp:category_choice>
          <tp:category>context-tool-administration</tp:category>
        </tp:category_choice>
        <tp:document_target>iframe</tp:document_target>
      </tp:menu_link>
     <tp:menu_link>
        <tp:title>View course wiki</tp:title>
        <tp:messages>
          <tp:message type="menu-view-request" path="?action=view">
            <tp:parameter name="course_shortname" variable="\$CourseOffering.label"/>
            <tp:parameter name="course_title" variable="\$CourseOffering.title"/>
            <tp:parameter name="person_fullname" variable="\$Person.name.full"/>
            <tp:parameter name="person_email" variable="\$Person.email.primary"/>
          </tp:message>
        </tp:messages>
        <tp:icons>
          <pc:icon>logo.png</pc:icon>
        </tp:icons>
        <tp:category_choice>
          <tp:category>context-tool</tp:category>
        </tp:category_choice>
        <tp:document_target>iframe</tp:document_target>
      </tp:menu_link>
    </tp:links>
  </tool_profile>
  <system_settings>
    <cm:property name="document_target">iframe</cm:property>
    <cm:property name="window_name">ltiiframe</cm:property>
    <cm:property name="height">500px</cm:property>
    <cm:property name="width">100%</cm:property>
  </system_settings>
  <security_contract>
    <shared_secret>$secret</shared_secret>
    <security_profiles>
      <sec:basic_hash_message_security_profile>
        <sec:algorithm>SHA-1</sec:algorithm>
      </sec:basic_hash_message_security_profile>
    </security_profiles>
  </security_contract>
</tool_registration_request>
XML;
}

function lti_soap_sec_header( $username, $password ) {
	return sprintf( <<<XML
  <wsse:Security
    SOAP-ENV:mustUnderstand="1"
    xmlns:wsse="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd">
    <wsse:UsernameToken wsu:Id="UsernameToken-4" xmlns:wsu="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd">
      <wsse:Username>%s</wsse:Username>
      <wsse:Password Type="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-username-token-profile-1.0#PasswordText">%s</wsse:Password>
    </wsse:UsernameToken>
  </wsse:Security>
  <ims:imsx_syncRequestHeaderInfo xmlns:ims="http://www.imsglobal.org/services/ltiv2p0/tregv1p0/wsdl11/sync/imsltitreg_v1p0">
    <ims:imsx_version>V1.0</ims:imsx_version>
    <ims:imsx_messageIdentifier></ims:imsx_messageIdentifier>
  </ims:imsx_syncRequestHeaderInfo>
XML
	, $username, $password );
}

function wp_generate_password($a, $b) {
	return uniqid();
}

function redirect($url, $status) {
	header("Location: $url");
	exit;
}
