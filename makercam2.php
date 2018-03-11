<?php
/*
Plugin Name: MakerCam 2
Plugin URI: http://makerslocal.org
Description: Shows cameras from a zoneminder install
Version: 2.2
Author: Hunter Fuller, Matt Robinson
Author URI: https://256.makerslocal.org/wiki/Network
*/

/*
    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

require(plugin_dir_path( __FILE__ ) . 'settings.php');

function clean_users () {
	global $wpdb;
	$wpdb->query("DELETE FROM wp_cam WHERE time < CURRENT_TIMESTAMP() - 60");
}
function update_users () {
	global $current_user;
	global $wpdb;
	$user = $current_user->user_login ? $current_user->user_login : $_SERVER['REMOTE_ADDR'];
	if (!$wpdb->query("update wp_cam SET time=CURRENT_TIMESTAMP WHERE user='$user'")) {
		$wpdb->insert("wp_cam", array("user" => $user));
	}
	clean_users();
}
function get_userss () {
	global $wpdb;
	clean_users();
	$arr = $wpdb->get_col("SELECT `user` FROM `wp_cam` ORDER BY `time` ASC");
	return join($arr, ", ");
}

function cam_init() {
	global $current_user;
	get_currentuserinfo();
	$level = $current_user->user_level;
	$user = $current_user->user_login ? $current_user->user_login : $_SERVER['REMOTE_ADDR'];
	$self = $_SERVER['REQUEST_URI'];

	//hack the planet if the user is coming from the shop. (just assume they are cool)
	if ( $level <= 1 && preg_match("/^" . Settings::SAFE_SPACE . "$/", $_SERVER['REMOTE_ADDR']) ) {
		$level = 2;
	}

	# for all authenticated users
	$op = $_GET['op'];
	if ( $op == "checkauth" ) {
		echo( ($level > 1) ? "true" : "false");
		exit();
	} elseif ( $op == "jpeg" || $op == "thumb" ) {
		$param = intval($_GET['camera']);
		update_users();
		header("Content-type: image/jpeg");
		if ( $param <= 0 or $level <= 1 ) { //parameter is invalid or we aren't logged in
			$param = 4; //roll-up door by default
			$param = 3; //soda machine
		}
		$url = Settings::ZM_URL . "/cgi-bin/nph-zms?mode=single&monitor=" . $param;
		if ( $op == "thumb" ) {
			$url .= "&scale=19";
		}
		readfile($url);
		exit;
	} elseif ( $op == "users" ) {
		print get_userss();
		exit;
	} elseif ( $op == "camcount" ) {
		//This count is deprecated, as it doesn't really tell you anything.
		//You can have 8 cameras and they aren't IDs 1 through 8...
		if ( !($level > 1) ) {
			echo 0;
		} else {
			$obj = json_decode(file_get_contents(Settings::ZM_URL . "/api/monitors.json"));
			echo count($obj->monitors);
		}
		exit;
	} elseif ( $op == "camids" ) {
		$arr = array();
		if ( $level > 1 ) {
			$obj = json_decode(file_get_contents(Settings::ZM_URL . "/api/monitors.json"));
			foreach ( $obj->monitors as $monitor ) {
				$arr[] = $monitor->Monitor->Id;
			}
		}
		echo(json_encode($arr));
		exit;
	} elseif ( $op == "version" ) {
		echo "4";
		exit;
	}
	//////////// LEGACY FOLLOWS /////////////
	elseif ($_GET['op'] == "day") {
		header("Content-type: image/jpeg");
		readfile("http://10.56.1.251/day.png");
		exit;
	}
	elseif ($_GET['op'] == "week") {
		header("Content-type: image/jpeg");
		readfile("http://10.56.1.251/week.png");
		exit;
	}
	elseif ($_GET['op'] == "month") {
		header("Content-type: image/jpeg");
		readfile("http://10.56.1.251/month.png");
		exit;
	}
	elseif ($_GET['op'] == "year") {
		header("Content-type: image/jpeg");
		readfile("http://10.56.1.251/year.png");
		exit;
	}
	elseif ($_GET['op'] == "inside.xml") {
		header("Content-type: application/xml");
		readfile("http://10.56.1.251/inside.xml");
		exit;
	}

}

function load_cams($content = '') {
	global $current_user;
	get_currentuserinfo();
	// set us up some variables
	$level = $current_user->user_level;
	$user = $current_user->user_login ? $current_user->user_login : $_SERVER['REMOTE_ADDR'];
	$self = $_SERVER['REQUEST_URI'];

	if (preg_match("/\[makercam\]/", $content)) {
		$content =
<<<EOD

<div id="makercam">
	<div id="makercam-view-container">
		<img src="?op=jpeg&camera=0" class="makercam-view" id="makercam-view-main">
	</div>
	<div id="makercam-controls">
		Members: <a href="/blog/wp-login.php?redirect_to=/camera">log in</a> to view more cameras.
		<noscript>(You will need JavaScript to view any other cameras.)</noscript>
	</div>

	<script>

	var version = null;

	jQuery(document).ready(function() {
		function refreshImages() {
			let buster = new Date().getTime();
			images = document.getElementsByClassName("makercam-view");
			for ( let i=0; i<images.length; i++ ) {
				image = images[i];
				image.src = image.src.split('&buster')[0] + "&buster=" + buster;
			}
			jQuery.get("?op=version&buster=" + new Date().getTime()).done(function(data) {
				console.log("server version: " + data);
				if ( version != null && version != data ) {
					console.log("we're only " + version + " - time to change version to " + data);
					window.location.reload(false); 
				} else {
					version = data;
				}
			});
		}
		
		jQuery.getJSON("?op=camids&buster=" + new Date().getTime()).done(function(camIds) {
			if ( camIds.length > 0 ) {
				//The user has access to some cameras
				let eControls = document.getElementById("makercam-controls");
				eControls.innerHTML = ""; //Remove the message about logging in
				let selectThisCamera = function() {
					document.getElementById("makercam-view-main").src = "?op=jpeg&camera=" + this.dataset.cameraId;
					refreshImages();
				};
				for ( let i = 0; i < camIds.length; i++ ) {
					console.log("Adding thumb for camera " + camIds[i]);
					let el = document.createElement("img");
					el.className = "makercam-view";
					el.dataset.cameraId = camIds[i];
				 	el.src = "?op=thumb&camera=" + camIds[i];
					el.onclick = selectThisCamera;
					eControls.appendChild(el);
				}
			}
			
			setInterval(refreshImages, 10000);
			
		});
	});
		
	</script>

	<style>
		.makercam-view {
			margin:1px;
		}
		.makercam-view:hover {
			cursor:pointer;
		}
		#makercam {
			text-align:center;
			max-width:650px;
			margin-bottom:24px;
		}
	</style>

</div>

<a href="/stats">Looking for the temperature in the shop? Try here.</a>

EOD
		;
	}
	return $content;
}

add_action('init', "cam_init", 1);
add_filter('the_content', 'load_cams');
?>
