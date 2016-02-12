#!/usr/bin/env php
<?php
/**
 * SCRIPT - tumblrpost.php
 * 
 * This script enables batch upload of photos on a Tumblr blog using the official API.
 * For more information use the source, Luke!.
 * @see http://www.tumblr.com/api/
 * @see https://www.tumblr.com/oauth/apps
 * @see https://api.tumblr.com/console/calls/user/info
 * 
 * @version 0.1
 * @author saeros <yonic.surny@gmail.com>
 *
 * Copyright (c) 2015 saeros
 * 
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to 
 * deal in the Software without restriction, including without limitation the 
 * rights to use, copy, modify, merge, publish, distribute, sublicense, and/or 
 * sell copies of the Software, and to permit persons to whom the Software is 
 * furnished to do so, subject to the following conditions:
 * 
 * The above copyright notice and this permission notice shall be included in 
 * all copies or substantial portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR 
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, 
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE 
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER 
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING 
 * FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS 
 * IN THE SOFTWARE.
 */
 
///////////////////////////////////////////////////////////////////////////////
// SETUP

// Uncomment the next 2 lines for debugging
// error_reporting(E_ALL);
// ini_set('display_errors', 1);

date_default_timezone_set('Europe/Brussels');
define('MIN_PAD',                   10);
if (posix_isatty(STDOUT)) {
    define('TERM_RESET',            "\033[0m");
    define('TERM_UNDERLINE',        "\033[4m");
    define('TERM_COLOR_RED',        "\033[31m");
    define('TERM_COLOR_BLUE',       "\033[34m");
    define('TERM_COLOR_GREEN',      "\033[32m");
    define('TERM_COLOR_YELLOW',     "\033[33m");
    define('TERM_SAVE_POSITION',    "\0337");
    define('TERM_RESTORE_POSITION', "\0338");
} else {
    define('TERM_RESET',            "");
    define('TERM_UNDERLINE',        "");
    define('TERM_COLOR_RED',        "");
    define('TERM_COLOR_BLUE',       "");
    define('TERM_COLOR_GREEN',      "");
    define('TERM_COLOR_YELLOW',     "");
    define('TERM_SAVE_POSITION',    "");
    define('TERM_RESTORE_POSITION', PHP_EOL);
}


///////////////////////////////////////////////////////////////////////////////
// OPTIONS

$shortopts = "hq";
$longopts = array('help', 'queued');

$options = getopt($shortopts, $longopts);


///////////////////////////////////////////////////////////////////////////////
// POSTER

class TumblrPoster {

    private $headers;
    private $queued;
    private $photos_directory;
    private $archive_directory;
    private $counter_processed;
    private $counter_downloaded;

    private $blog_name;
    private $consumer_key;
    private $consumer_secret;
    private $oauth_secret;
    private $oauth_token;

    function __construct($arguments, $arguments_count, $options) {
        // test for usage and print help
        if (array_key_exists("help", $options) || array_key_exists("h", $options)) {
            fwrite(STDOUT, TERM_UNDERLINE . "Usage:" . TERM_RESET . " $arguments[0]" . PHP_EOL . PHP_EOL);
            fwrite(STDOUT, "  -q (or --queued)           " . TERM_COLOR_BLUE . "*optional*" . TERM_RESET . " a flag to tell the script to put the post in queue (default is 'published')" . PHP_EOL);
            fwrite(STDOUT, "  -h (or --help)             prints this help" . PHP_EOL . PHP_EOL);

            fwrite(STDOUT, TERM_UNDERLINE . "Examples:" . TERM_RESET . PHP_EOL . PHP_EOL);
            fwrite(STDOUT, "  $arguments[0]           (post every photo available)" . PHP_EOL);
            fwrite(STDOUT, "  $arguments[0] --queued  (post every photo in the blog queue)" . PHP_EOL . PHP_EOL);

            fwrite(STDOUT, TERM_UNDERLINE . "Notes:" . TERM_RESET . PHP_EOL . PHP_EOL);
            fwrite(STDOUT, "  - photos are taken from the 'photos' directory where the script resides" . PHP_EOL);
            fwrite(STDOUT, "  - configuration resides in the config.json file" . PHP_EOL);

            die(0);
        }


        // test presence of photos directory
        $this->photos_directory = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'photos';
        if (!is_dir($this->photos_directory)) {
            fwrite(STDERR, TERM_UNDERLINE . TERM_COLOR_RED . "ERROR:" . TERM_RESET . " Bad usage!" . PHP_EOL);
            fwrite(STDERR, "  see php $arguments[0] -h (or --help) for usage" . PHP_EOL);
            fwrite(STDERR, "  'photos' directory doesn't exist!" . PHP_EOL);

            die(1);
        }

        // test presence of config.json and validity
        $raw_configuration = @file_get_contents("config.json");
        $configuration = json_decode($raw_configuration, true);
        $this->blog_name = $configuration['BLOG_NAME'];
        $this->consumer_key = $configuration['CONSUMER_KEY'];
        $this->consumer_secret = $configuration['CONSUMER_SECRET'];
        $this->oauth_secret = $configuration['OAUTH_SECRET'];
        $this->oauth_token = $configuration['OAUTH_TOKEN'];
        if (is_null($this->blog_name) || empty($this->blog_name) 
            || is_null($this->consumer_key) || empty($this->consumer_key) || strcmp($this->consumer_key, "REPLACE_ME") == 0 
            || is_null($this->consumer_secret) || empty($this->consumer_secret) || strcmp($this->consumer_secret, "REPLACE_ME") == 0 
            || is_null($this->oauth_secret) || empty($this->oauth_secret) || strcmp($this->oauth_secret, "REPLACE_ME") == 0 
            || is_null($this->oauth_token) || empty($this->oauth_token) || strcmp($this->oauth_token, "REPLACE_ME") == 0) {
            fwrite(STDERR, TERM_UNDERLINE . TERM_COLOR_RED . "ERROR:" . TERM_RESET . " Invalid configuration!" . PHP_EOL);
            fwrite(STDERR, "  see php $arguments[0] -h (or --help) for usage" . PHP_EOL);
            fwrite(STDERR, "  The configuration file 'config.json' doesn't exist or keys are missing/invalid" . PHP_EOL);

            die(1);
        }


        // setup archive directory
        $this->archive_directory = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'archive';
        if (!file_exists($this->archive_directory)) {
            mkdir($this->archive_directory); // create archive directory

            $date_string = $this->date_now_string();
            fwrite(STDOUT, "[$date_string] > created archive directory!" . PHP_EOL);
        }

        // queued 
        $this->queued = false;
        if (array_key_exists('q', $options) || array_key_exists('queued', $options)) {
            $this->queued = true;
        }

        // counters
        $this->counter_processed = 0;
        $this->counter_uploaded = 0;
    }

    /** process
     *
     * @since 0.1
     * @description public interface to launch the upload process
     */
    public function process() {
        $date_string = $this->date_now_string();
        fwrite(STDOUT, "[$date_string] Started processing '$this->blog_name'" . PHP_EOL);

        $this->loop_through_photos();

        $date_string = $this->date_now_string();
        fwrite(STDOUT, "[$date_string] Done processing '$this->blog_name': $this->counter_uploaded/$this->counter_processed photo(s) uploaded." . PHP_EOL);

        die(0);
    }

    /** loop_through_photos
     * 
     * @since 0.1
     * description loops through the photos and upload
     */
    private function loop_through_photos() {
        if ($handle = opendir($this->photos_directory)) {
            while (false !== ($entry = readdir($handle))) {
                if ($entry != "." && $entry != "..") {
                    $photo_path = $this->photos_directory . DIRECTORY_SEPARATOR . $entry;
                    if ($image = getimagesize($photo_path) ? true : false) {
                        $this->upload_photo($photo_path, $entry);
                        $this->counter_processed++;
                    }
                }
            } //! while
        } //! handle
    }

    /** upload_photo
     *
     * @since 0.1
     * @description upload a photo
     * @param string $photo_path path of the photo
     * @param string $photo_name name of the photo
     */
    private function upload_photo($photo_path, $photo_name) {
        // build params
        $params = array("data64" => base64_encode(file_get_contents($photo_path)),
                        "type" => "photo",
                        "state" => (($this->queued) ? "queue" : "published"));

        fwrite(STDOUT, TERM_SAVE_POSITION);
        $date_string = $this->date_now_string();
        $head = "[$date_string] $this->blog_name > $photo_name";
        $tail = "uploading";
        $pad = $this->pad_string($head, $tail);
        fwrite(STDOUT, TERM_RESTORE_POSITION . $head . $pad . $tail);

        // setup request
        $post_url = "http://api.tumblr.com/v2/blog/$this->blog_name.tumblr.com/post";
        $this->oauth_gen("POST", $post_url, $params);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_USERAGENT, "TumblrPoster");
        curl_setopt($ch, CURLOPT_URL, $post_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1 );
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Authorization: " . $this->headers['Authorization'],
            "Content-type: " . $this->headers["Content-type"],
            "Expect: ")
        );
        $params = http_build_query($params);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        $response = curl_exec($ch);
        $error = curl_error($ch);

        // handle response
        $response_json = json_decode($response);
        $response_status = $response_json->meta->status;
        $response_message = $response_json->meta->msg;
        if (!empty($error) || $response_status != 201) {
            if (empty($error)) {
                $error = $response_status . " - " . $response_message;
            }
            $tail = "error: $error";
            $pad = $this->pad_string($head, $tail);
            fwrite(STDERR, TERM_RESTORE_POSITION . $head . $pad . TERM_COLOR_RED . $tail . TERM_RESET . PHP_EOL);
            curl_close($ch);
            return;
        }

        curl_close($ch);

        //move file
        rename($photo_path, $this->archive_directory . DIRECTORY_SEPARATOR . $photo_name);

        $this->counter_uploaded++;
        $counter_string = sprintf("%03d", $this->counter_uploaded);
        $tail = strtolower($response_message) . "[$counter_string]";
        $pad = $this->pad_string($head, $tail);
        fwrite(STDOUT, TERM_RESTORE_POSITION . $head . $pad . TERM_COLOR_GREEN . $tail . TERM_RESET . PHP_EOL);
    }

    /** date_now_string
     * 
     * @since 0.1
     * @description provide a formated string of the current date
     * @return string
     */
    private function date_now_string() {
        return date("H:i:s");
    }

    /** pad_string
     * 
     * @since 0.1
     * @descritpion get a string composed of dots to pad output to the width of the terminal
     * @param string $head the string to print before
     * @param string $tail the string to print after
     * @return string
     */
    private function pad_string($head, $tail) {
        $length = 0;
        if (posix_isatty(STDOUT)) {
            $term_cols = intval(`tput cols`);
            $length = $term_cols - strlen($head) - strlen($tail);
        }
        $length = ($length > MIN_PAD ? $length : MIN_PAD);
        return str_repeat('.', $length);
    }

    /** ouath_gen
     *
     * @since 0.1
     */
    private function oauth_gen($method, $url, $iparams) {
        $iparams['oauth_consumer_key'] = $this->consumer_key;
        $iparams['oauth_nonce'] = strval(time());
        $iparams['oauth_signature_method'] = 'HMAC-SHA1';
        $iparams['oauth_timestamp'] = strval(time());
        $iparams['oauth_token'] = $this->oauth_token;
        $iparams['oauth_version'] = '1.0';
        $iparams['oauth_signature'] = $this->oauth_sig($method, $url, $iparams); 
        $oauth_header = array();
        foreach($iparams as $key => $value) {
            if (strpos($key, "oauth") !== false) { 
               $oauth_header []= $key ."=".$value;
            }
        }
        $oauth_header = "OAuth ". implode(",", $oauth_header);
        $this->headers = array( "Host" => "http://api.tumblr.com/", 
                                "Content-type" => "application/x-www-form-urlencoded", 
                                "Expect" => "",
                                "Authorization" => $oauth_header );
    }
    
    /** ouath_sig
     *
     * @since 0.1
     * @description generates the oauth signature
     */
    private function oauth_sig($method, $uri, $params) {
        $parts []= $method;
        $parts []= rawurlencode($uri);
       
        $iparams = array();
        ksort($params);
        foreach ($params as $key => $data) {
            if (is_array($data)) {
                $count = 0;
                foreach ($data as $val) {
                    $n = $key . "[". $count . "]";
                    $iparams []= $n . "=" . rawurlencode($val);
                    $count++;
                }
            } 
            else {
                $iparams[]= rawurlencode($key) . "=" .rawurlencode($data);
            }
        }
        $parts []= rawurlencode(implode("&", $iparams));
        $sig = implode("&", $parts);

        return base64_encode(hash_hmac('sha1', $sig, $this->consumer_secret."&". $this->oauth_secret, true));
    }
}

$poster = new TumblrPoster($argv, $argc, $options);
$poster->process();

?>