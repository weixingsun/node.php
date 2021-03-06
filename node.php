<?php

/**
 * Node.php v0.4
 * (c) 2016 Jerzy Głowacki
 *     2016/7/21 Add getallheaders() for v5.3
 * MIT License
 */

//define("ADMIN_MODE", true);
define("ADMIN_MODE", false); //set to true to allow unsafe operations, set back to false when finished

error_reporting(E_ALL);
set_time_limit(120);
define("NODE_VER", "v6.3.0");
define("NODE_ARCH", "x" . substr(php_uname("m"), -2)); //x86 or x64
define("NODE_FILE", "node-" . NODE_VER . "-linux-" . NODE_ARCH . ".tar.gz");
define("NODE_URL", "http://nodejs.org/dist/" . NODE_VER . "/" . NODE_FILE);
define("NODE_DIR", "node");
define("NODE_PORT", 49999);
//change ADMIN=true
//wget http://download.redis.io/releases/redis-3.2.1.tar.gz && tar zxf redis-3.2.1.tar.gz && cd redis-3.2.1 && make && src/redis-server #start redis on 127.0.0.1:6379
//git clone https://github.com/weixingsun/docker-redis.git && $HOST/service/node.php?start=docker-redis/src/main.js  #start nodejs server
//change ADMIN=false
//wget $HOST/service/node.php?path=api/msg/car:1,2:3

if (!function_exists('getallheaders')) 
{ 
    function getallheaders() 
    { 
           $headers = ''; 
       foreach ($_SERVER as $name => $value) 
       { 
           if (substr($name, 0, 5) == 'HTTP_') 
           { 
               $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value; 
           } 
       } 
       return $headers; 
    } 
} 

function node_install() {
	if(file_exists(NODE_DIR)) {
		echo "Node.js is already installed.\n";
		return;
	}
	if(!file_exists(NODE_FILE)) {
		echo "Downloading Node.js from " . NODE_URL . ":\n\n";
		$fp = fopen(NODE_FILE, "w");
		flock($fp, LOCK_EX);
		$curl = curl_init(NODE_URL);
		curl_setopt($curl, CURLOPT_HEADER, 0);
		curl_setopt($curl, CURLOPT_FILE, $fp);
		$resp = curl_exec($curl);
		curl_close($curl);
		flock($fp, LOCK_UN);
		fclose($fp);
		echo $resp === true ? "Done.\n" : "Failed. Error: curl_error($curl)\n";
	}
	echo "Installing Node.js:\n";
	passthru("tar -xzf " . NODE_FILE . " 2>&1 && mv node-" . NODE_VER . "-linux-" . NODE_ARCH . " " . NODE_DIR . " && touch nodepid && rm -f " . NODE_FILE, $ret);
	echo $ret === 0 ? "Done.\n" : "Failed. Error: $ret\nTry putting node folder via (S)FTP, so that " . __DIR__ . "/node/bin/node exists.";
}

function node_uninstall() {
	if(!file_exists(NODE_DIR)) {
		echo "Node.js is not yet installed.\n";
		return;
	}
	echo "Unnstalling Node.js:\n";
	passthru("rm -rf " . NODE_DIR . " nodepid", $ret);
	echo $ret === 0 ? "Done.\n" : "Failed. Error: $ret\n";
}

function node_start($file) {
	if(!file_exists(NODE_DIR)) {
		echo "Node.js is not yet installed. <a href='?install'>Install it</a>.\n";
		return;
	}
	$node_pid = intval(file_get_contents("nodepid"));
	if($node_pid > 0) {
		echo "Node.js is already running. <a href='?stop'>Stop it</a>.\n";
		return;
	}
	$file = escapeshellarg($file);
	echo "Starting: node $file\n";
	$node_pid = exec("PORT=" . NODE_PORT . " " . NODE_DIR . "/bin/node $file >nodeout 2>&1 & echo $!");
	echo $node_pid > 0 ? "Done. PID=$node_pid\n" : "Failed.\n";
	file_put_contents("nodepid", $node_pid, LOCK_EX);
	sleep(1); //Wait for node to spin up
	echo file_get_contents("nodeout");
}

function node_stop() {
	if(!file_exists(NODE_DIR)) {
		echo "Node.js is not yet installed. <a href='?install'>Install it</a>.\n";
		return;
	}
	$node_pid = intval(file_get_contents("nodepid"));
	if($node_pid === 0) {
		echo "Node.js is not yet running.\n";
		return;
	}
	echo "Stopping Node.js with PID=$node_pid:\n";
	$ret = -1;
	passthru("kill $node_pid", $ret);
	echo $ret === 0 ? "Done.\n" : "Failed. Error: $ret\n";
	file_put_contents("nodepid", '', LOCK_EX);
}

function node_npm($cmd) {
	if(!file_exists(NODE_DIR)) {
		echo "Node.js is not yet installed. <a href='?install'>Install it</a>.\n";
		return;
	}
	$cmd = escapeshellcmd(NODE_DIR . "/bin/npm --cache ./.npm -- $cmd");
	echo "Running: $cmd\n";
	$ret = -1;
	passthru($cmd, $ret);
	echo $ret === 0 ? "Done.\n" : "Failed. Error: $ret. See <a href=\"npm-debug.log\">npm-debug.log</a>\n";
}

function node_serve($path = "") {
	if(!file_exists(NODE_DIR)) {
		node_head();
		echo "Node.js is not yet installed. Switch to Admin Mode and <a href='?install'>Install it</a>.\n";
		node_foot();
		return;
	}
	$node_pid = intval(file_get_contents("nodepid"));
	if($node_pid === 0) {
		node_head();
		echo "Node.js is not yet running. Switch to Admin Mode and <a href='?start'>Start it</a>\n";
		node_foot();
		return;
	}
        $url = "http://127.0.0.1:" . NODE_PORT . "/$path";
        //header('HTTP/1.1 307 Temporary Redirect');
        //header("Location: $url");
	
        $curl = curl_init($url);
	curl_setopt($curl, CURLOPT_HEADER, 1);
	curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        $headers = array();
        foreach(getallheaders() as $key => $value) {
                $headers[] = $key . ": " . $value;
        }
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $_SERVER["REQUEST_METHOD"]);
        if($_SERVER["REQUEST_METHOD"] === "POST") {
            curl_setopt($curl, CURLOPT_POST, 1);
            if (count($_POST)==0) {  //strlen($str_json_params) > 0) && isValidJSON($json_params)) {
                $str_json_params = file_get_contents('php://input');
                curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
                curl_setopt($curl, CURLOPT_POSTFIELDS, $str_json_params);
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            }else{
                //$str_header = implode(",", $headers);
                $fields = http_build_query($_POST);
                curl_setopt($curl, CURLOPT_POSTFIELDS, $fields);
                //error_log("post json=$str_json_params ");
            }
        } else if($_SERVER["REQUEST_METHOD"] === "PUT" || $_SERVER["REQUEST_METHOD"] === "DELETE"){
	    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $_SERVER["REQUEST_METHOD"]);
            curl_setopt($curl, CURLOPT_POSTFIELDS, file_get_contents('php://input'));
            curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
	}
        //error_log("url=$url");
 	$resp = curl_exec($curl);
	if($resp === false) {
		node_head();
		echo "Error requesting $path: " . curl_error($curl);
		node_foot();
	} else {
		list($head, $body) = explode("\r\n\r\n", $resp, 2);
		$headarr = explode("\n", $head);
		foreach($headarr as $headval) {
			header($headval);
		}
		echo $body;
	}
	curl_close($curl);
}

function node_head() {
	echo '<!DOCTYPE html><html><head><title>Node.php</title><meta charset="utf-8"><body style="font-family:Helvetica,sans-serif;"><h1>Node.php</h1><pre>';
}

function node_foot() {
	echo '</pre><p><a href="https://github.com/niutech/node.php" target="_blank">Powered by node.php</a></p></body></html>';
}

function node_dispatch() {
	if(ADMIN_MODE) {
		node_head();
		if(isset($_GET['install'])) {
			node_install();
		} elseif(isset($_GET['uninstall'])) {
			node_uninstall();
		} elseif(isset($_GET['start'])) {
			node_start($_GET['start']);
		} elseif(isset($_GET['stop'])) {
			node_stop();
		} elseif(isset($_GET['npm'])) {
			node_npm($_GET['npm']);
		} else {
			echo "You are in Admin Mode. Switch back to normal mode to serve your node app.";
		}
		node_foot();
	} else {
		$full_url = $_SERVER['REQUEST_URI'];
                $path = explode("?path=",$full_url);
		//error_log("path=$path[1]");
		node_serve($path[1]);
		/*if(isset($_GET['path'])) {
			node_serve($_GET['path']);
		} else {
			node_serve();
		}*/
	}
}

node_dispatch();
