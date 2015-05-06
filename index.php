<?php

	// Notes:
	// ======
	// QueryString is CaseSensitive
	//
	// SET-MY-IP or SET-URL=http://www.url.com or SET-URL=www.url.com or SET-URL=ip
	//
	// Remove Redirect with SET-URL=NULL
	//
	// Example Usage: http://server/path/?Password&SET-URL=urltoset
	// Example Usage: http://server/path/?Password&SET-MY-IP
	// curl --silent "http://www.webserver.com/go/to/office/?SET-URL=www.google.com" | head -1
	//
	// Templating: Create Directory and put index with following
	// <?php require_once('../index.php'); ? >

	$password_for_setting_url = "";
	$db_file_path=$_SERVER['DOCUMENT_ROOT'].'/go/redirection.sqlite3';

	$var_set_url = "SET-URL";
	$var_set_client_ip = "SET-MY-IP";
	$var_no_redirect = "NULL";

	$do_online_check = true;
	$do_age_check = true; // Need to Create Last Modified Field and Check that it is within permited time.
	$age_check_diff_in_minutes = 60;

	if ($password_for_setting_url == "") {
		$update_database = true;
	} else {
		$update_database = isset($_REQUEST[$password_for_setting_url]) ? true : false;
	}

	function online_check($url){
		$agent = "Mozilla/4.0 (compatible; MSIE 5.01; Windows NT 5.0)";$ch=curl_init();
		curl_setopt ($ch, CURLOPT_URL,$url );
		curl_setopt($ch, CURLOPT_USERAGENT, $agent);
		curl_setopt ($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt ($ch,CURLOPT_VERBOSE,false);
		curl_setopt($ch, CURLOPT_TIMEOUT, 5);
		curl_setopt($ch,CURLOPT_SSL_VERIFYPEER, FALSE);
		curl_setopt($ch,CURLOPT_SSLVERSION,3);
		curl_setopt($ch,CURLOPT_SSL_VERIFYHOST, FALSE);
		$page=curl_exec($ch);
		//echo curl_error($ch);
		$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		if($httpcode>=200 && $httpcode<400) return true;
		else return false;
	}

	date_default_timezone_set('UTC');
	$current_timestamp = date("Y-m-d H:i:s", time());

	$resource_not_available = true;

	try {
		$file_db = new PDO('sqlite:'.$db_file_path);
		$file_db-> setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$file_db->exec("CREATE TABLE IF NOT EXISTS redirection (local_url TEXT PRIMARY KEY, outside_url TEXT, last_modified DATETIME default current_timestamp)");
	} catch (PDOException $e) {
		echo $e->getMessage();
	}

	$local_url = isset($_SERVER['REQUEST_URI']) ? strtok($_SERVER['REQUEST_URI'],'?') : null;

	if (($update_database == true) && (isset($_REQUEST[$var_set_client_ip]) || isset($_REQUEST[$var_set_url]))) {

		if (isset($_REQUEST[$var_set_url])) {
			$outside_url = $_REQUEST[$var_set_url];
		} elseif (isset($_REQUEST[$var_set_client_ip])) {
			$outside_url = $_SERVER['REMOTE_ADDR'];
		} else {
			$outside_url = "";
			//$http_output = "Invalid\n<br />\nAvailable Set Options:\n<br />\n".$var_set_url."\n<br />\n".$var_set_client_ip;
			// Don't want to tell the public what commands are available
			$http_output = "Invalid\n<br />\n";
		}
		if ($outside_url != "") {
			// Write or Update to Database
			try {
				$file_db->exec('INSERT OR REPLACE INTO redirection (local_url, outside_url) VALUES ("' . $local_url . '", "' . $outside_url . '");');
				if ($outside_url == $var_no_redirect) {
					$http_output = "Success\n<br />\nRedirect Removed";
				} else {
					if (count($outside_url) <= 10 || ! (substr($outside_url, 0, 7) == 'http://' || strpos($outside_url,0, 8) == 'https://')) {
						$outside_url = "http://" . $outside_url;
					}
					$http_output = "Success\n<br />\nRedirect set to <a href=\"".$outside_url."\">".$outside_url."</a>";
				}
			} catch (PDOException $e) {
				// Should Never Come here, but if it does check database permissions.
				$http_output = "Failed\n<br />\n".$e->getMessage();
			}

			$file_db = null;
		}
		ob_clean();
		header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
		header("Cache-Control: no-cache");
		header("Pragma: no-cache");
		echo $http_output;
		exit;
	}

	// Try to get from database
	try {
		$result = $file_db->query('SELECT outside_url, last_modified FROM redirection where local_url=' . '"' . $local_url . '"');
		$row = $result->fetch(PDO::FETCH_ASSOC);;
		$outside_url = $row['outside_url'];
		$last_modified = $row['last_modified'];
		$datetime_diff_in_minutes = round(abs(strtotime($current_timestamp) - strtotime($last_modified)) / 60,0);
	} catch (PDOException $e) {
		$outside_url = "";
		//echo $e->getMessage();
	}

	if (($do_age_check == false) || ($do_age_check && $datetime_diff_in_minutes <= $age_check_diff_in_minutes)) {
		if ($outside_url == $var_no_redirect) {
			$outside_url = "";
		}

		if ($outside_url != "" && (($do_online_check && online_check($outside_url) == true) || $do_online_check == false)) {
			if (count($outside_url) <= 10 || ! (substr($outside_url, 0, 7) == 'http://' || strpos($outside_url,0, 8) == 'https://')) {
				$outside_url = "http://" . $outside_url;
			}
			$http_output = "<html>" . PHP_EOL;
			$http_output .= "<head>" . PHP_EOL;
			$http_output .= "<title>Redirecting...</title>" . PHP_EOL;
			$http_output .= "<meta http-equiv=\"refresh\" content=\"0;URL=" . $outside_url ."\" />" . PHP_EOL;
			$http_output .= "</head>" . PHP_EOL;
			$http_output .= "<body>" . PHP_EOL;
			$http_output .= "Resource Available @ <a href=\"".$outside_url."\">" . $outside_url . "</a><br />" . PHP_EOL;
			$http_output .= "Redirecting..." . PHP_EOL;
			$http_output .= "</body>" . PHP_EOL;
			$http_output .= "</html>" . PHP_EOL;
			$resource_not_available = false;
		}
	}

	if ($resource_not_available == true) {
		$http_output = "<html>" . PHP_EOL;
		$http_output .= "<head>" . PHP_EOL;
		$http_output .= "<title>Resource Not Available</title>" . PHP_EOL;
		$http_output .= "</head>" . PHP_EOL;
		$http_output .= "<body>" . PHP_EOL;
		$http_output .= "Resource Offline or Not Available!!!" . PHP_EOL;
		$http_output .= "</body>" . PHP_EOL;
		$http_output .= "</html>" . PHP_EOL;
	}

	$file_db = null;

	ob_clean();
	header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
	header("Cache-Control: no-cache");
	header("Pragma: no-cache");
	echo $http_output;

?>
