<?php
/*
Plugin Name: Lti
Plugin URI: http://wordpress.org/#
Description: Lti thing
Author: me
Version: 0.1
*/
/*
 * try it with the p2 theme (http://wordpress.org/extend/themes/p2) with its
 * header removed.
 */
/**
 * lti-moo, (partial) full LTI implementation for moodle
 * Copyright (C) 2011 University of Kent (kent.ac.uk)
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
function lti_install() {
  global $wpdb;
  global $lti_db_version;

  $table_name = $wpdb->prefix . 'lti_consumers';
  if( $wpdb->get_var("show tables like '$table_name'") != $table_name ) {
    $sql = "CREATE TABLE " . $table_name . " (
      id mediumint(9) NOT NULL AUTO_INCREMENT,
      tool_proxy_guid varchar(255) NOT NULL,
      consumer_guid varchar(255) NOT NULL,
      consumer_user_id varchar(255),
      time bigint(11) DEFAULT '0' NOT NULL,
      secret varchar(255) not null,
      UNIQUE KEY id (id)
    );";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    add_option("lti_db_version", $lti_db_version);
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

function lti_add_trigger($vars) {
  $vars[] = 'lti_deploy';
  $vars[] = 'lti_ping';
  $vars[] = 'lti_menu_view_request';
  return $vars;
}


function lti_trigger_check() {
  if(intval(get_query_var('lti_deploy')) == 1) {
    lti_register();
  } else if(intval(get_query_var('lti_ping')) == 1) {
    lti_ping();
  } else if(intval(get_query_var('lti_menu_view_request')) == 1) {
    lti_sign_check();
    lti_login_user();
    $r = lti_extract_menu_view_request( $_POST );
    wp_redirect( 'archives/' . $r->custom_course_shortname );
  } else if(intval(get_query_var('lti_menu_view_request')) == 2) {
    lti_sign_check();
    lti_login_user();
    lti_create_course_post();
  }
}

function lti_edit(){
	lti_sign_check();
    //lti_login_user();
    $r = lti_extract_menu_view_request( $_POST );
    print_r($r); die("Edit page");
    wp_redirect( 'archives/' . $r->custom_course_shortname );
}

function lti_view(){
	die("View page");
	lti_sign_check();
    //lti_login_user();
    //lti_create_course_post();
}

function lti_create_course_post() {
    // if instructor then create if doesnt exist and send to admin page

  $r = lti_extract_menu_view_request( $_POST );

  if( false === strpos( $r->roles, 'Instructor' ) ) return;

  // does post exist ?
  global $wpdb;
  $post_id = $wpdb->get_var( $wpdb->prepare("select ID from {$wpdb->prefix}posts where post_name = %s", $r->custom_course_shortname ) );
  if( is_null( $post_id ) ) {
    $pd = array();
    $pd['post_title'] = $r->custom_course_title;
    $pd['post_name'] = $r->custom_course_shortname;
    $pd['post_content'] = 'Discuss your course in all its awesomeness.';
    $post_id = wp_insert_post($pd);
    wp_publish_post( $post_id );
    // then make one and edit it, or just redirect to edit page??
  }

  wp_redirect( 'archives/' . $r->custom_course_shortname );
}

function lti_return_link() {
  global $lti_launch_presentation_return_url;
  if( is_null( $lti_launch_presentation_return_url ) ) {
    $lti_launch_presentation_return_url = $_COOKIE['lti_launch_presentation_return_url'];
  }
  return $lti_launch_presentation_return_url;
}

function lti_course_name() {
  global $lti_custom_course_fullname;
  if( is_null( $lti_custom_course_fullname ) ) {
    $lti_custom_course_fullname = $_COOKIE['lti_custom_course_fullname'];
  }
  return $lti_custom_course_fullname;
}

function lti_login_user() {
  $r = lti_extract_menu_view_request( $_POST );
  // is user_id existing?
  global $wpdb;
  $user_id = $wpdb->get_var( $wpdb->prepare("select ID from {$wpdb->prefix}users where user_login = %s", $r->user_id) );
  //  no? then create
  if( is_null( $user_id ) ) {
    $user_data['user_login'] = $r->user_id;
    $user_data['display_name'] = $r->custom_person_fullname;
    if( false === strpos( $r->roles, 'Instructor' ) ) {
      $user_data['role'] = 'subscriber';
    } else {
      $user_data['role'] = 'author';
    }
    $user_data['user_pass'] = substr( md5( uniqid( microtime() ) ), 0, 7);
    $user_data['user_email'] = $r->custom_person_email;
    $user_id = wp_insert_user( $user_data );
  }
  //  yes? then login
  wp_set_current_user( $user_id );
  wp_clear_auth_cookie();
  wp_set_auth_cookie($user_id);
}

add_filter('request', 'lti_request_filter');
function lti_request_filter($request) {
  if( intval( $request['lti_menu_view_request'] ) ) {
    $r = lti_extract_menu_view_request( $_POST );
    $request['name'] = $r->custom_course_shortname;

    global $lti_launch_presentation_return_url;
    global $lti_custom_course_fullname;
    $lti_custom_course_fullname = $r->custom_course_title;
    $lti_launch_presentation_return_url = '<a class="secondary" href='.$r->launch_presentation_return_url.'>' . $r->custom_course_shortname . '</a>';
    setcookie( 'lti_launch_presentation_return_url', $lti_launch_presentation_return_url );
    setcookie( 'lti_custom_course_fullname', $lti_custom_course_fullname);
  }
  return $request;
}

function lti_sign_check() {
  $guid = $_POST['tool_proxy_guid'];
  $mac = $_POST['mac'];

  unset( $_POST['mac'] );
  ksort( $_POST );

  global $wpdb;
  //$r = $wpdb->get_var( $wpdb->prepare( "select secret from {$wpdb->prefix}lti_consumers where tool_proxy_guid = %s", $guid ) );
  $r = 'secret';
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

  // redirect
  wp_redirect( $deployp->launch_presentation_return_url . '&status=success', 301 );
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
        <tp:base_url type="icon_default">http://localhost/pmwiki/pub/skins/pmwiki</tp:base_url>
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
          <pc:icon>pmwiki-32.gif</pc:icon>
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
          <pc:icon>pmwiki-32.gif</pc:icon>
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
	return "secret";
}

function wp_redirect($url, $status) {
	header("Location: $url");
}
